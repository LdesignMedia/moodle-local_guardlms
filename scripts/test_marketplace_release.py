"""Release guard tests: real temporary git refs, mocked GitHub responses, no publishing."""

import contextlib
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import urllib.error

import check_marketplace_release as release


class MarketplaceReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        previous = Path.cwd()
        os.chdir(self.temp.name)
        self.addCleanup(os.chdir, previous)
        self.git("init", "-q", "-b", "main")
        self.git("config", "user.email", "tests@example.invalid")
        self.git("config", "user.name", "Release tests")
        self.git("config", "commit.gpgsign", "false")
        self.git("config", "tag.gpgsign", "false")
        self.git("config", "core.hooksPath", str(Path(self.temp.name) / "empty-hooks"))
        self.write_version()
        self.git("add", "version.php")
        self.git("commit", "-qm", "Initial release")
        self.sha = self.git("rev-parse", "HEAD")
        self.git("update-ref", "refs/remotes/origin/main", self.sha)
        self.git("tag", "v1.10.0")

    def git(self, *args):
        return subprocess.check_output(["git", *args], text=True, stderr=subprocess.DEVNULL).strip()

    def write_version(self, component="local_guardlms", version="2026103000", name="1.10.0"):
        Path("version.php").write_text(
            "<?php\n/* $plugin->release = 'commented'; */\n"
            f"$plugin->component = '{component}';\n$plugin->version = {version};\n"
            f"$plugin->release = '{name}';\n", encoding="utf-8")

    def retag(self):
        self.git("add", "version.php")
        self.git("commit", "-qm", "Change metadata")
        self.sha = self.git("rev-parse", "HEAD")
        self.git("update-ref", "refs/remotes/origin/main", self.sha)
        self.git("tag", "-f", "v1.10.0")

    def ci_run(self, **overrides):
        return {"id": 10, "run_attempt": 1, "head_sha": self.sha, "head_branch": "main",
                "event": "push", "status": "completed", "conclusion": "success",
                "updated_at": "2026-10-30T12:00:00Z", **overrides}

    def test_lightweight_and_annotated_tags_resolve_to_same_commit(self):
        self.assertEqual(self.sha, release.validate_checkout("v1.10.0"))
        self.git("tag", "-d", "v1.10.0")
        self.git("tag", "-a", "v1.10.0", "-m", "Release")
        self.assertEqual(self.sha, release.validate_checkout("v1.10.0"))

    def test_prerelease_tag_matches_literal_release(self):
        self.write_version(name="1.10.0-rc.1")
        self.retag()
        self.git("tag", "v1.10.0-rc.1")
        self.assertEqual(self.sha, release.validate_checkout("v1.10.0-rc.1"))

    def test_invalid_tags_are_rejected_before_git(self):
        for tag in ("", "main", "v1.10", "v01.10.0", "v1.10.0\n", "v1.10.0;echo secret", "$(id)", "v1.10.0/other"):
            with self.subTest(tag=tag), patch.object(release, "git") as git:
                with self.assertRaises(release.ReleaseError):
                    release.validate_checkout(tag)
                git.assert_not_called()

    def test_missing_tag_and_wrong_checkout_are_rejected(self):
        with self.assertRaises(release.ReleaseError):
            release.validate_checkout("v2.0.0")
        self.git("commit", "--allow-empty", "-qm", "Later main commit")
        with self.assertRaisesRegex(release.ReleaseError, "checked-out"):
            release.validate_checkout("v1.10.0")

    def test_unmerged_commit_is_rejected(self):
        self.git("checkout", "-qb", "feature")
        self.git("commit", "--allow-empty", "-qm", "Not merged")
        self.git("tag", "-f", "v1.10.0")
        with self.assertRaises(release.ReleaseError):
            release.validate_checkout("v1.10.0")

    def test_same_named_remote_branch_cannot_redirect_publisher_checkout(self):
        self.git("update-ref", "refs/remotes/origin/v1.10.0", self.sha)
        self.git("commit", "--allow-empty", "-qm", "Later release")
        self.git("update-ref", "refs/remotes/origin/main", "HEAD")
        self.git("tag", "-f", "v1.10.0")
        with self.assertRaisesRegex(release.ReleaseError, "same name"):
            release.validate_checkout("v1.10.0")

    def test_metadata_must_match_component_release_and_numeric_build(self):
        for values in ({"component": "local_other"}, {"name": "1.9.2"}, {"version": "'2026103000'"},
                       {"version": "0"}, {"version": "2026103000 + 1"}):
            with self.subTest(values=values):
                self.write_version(**values)
                self.retag()
                with self.assertRaises(release.ReleaseError):
                    release.validate_checkout("v1.10.0")

    def test_success_requires_the_exact_main_push_commit(self):
        release.require_passing_ci([self.ci_run()], self.sha)
        for runs in ([], [self.ci_run(head_sha="other")], [self.ci_run(event="pull_request")], [self.ci_run(head_branch="feature")]):
            with self.subTest(runs=runs), self.assertRaisesRegex(release.ReleaseError, "No main-branch CI"):
                release.require_passing_ci(runs, self.sha)

    def test_newest_failure_or_incomplete_rerun_cannot_use_old_success(self):
        for state in ({"conclusion": "failure"}, {"status": "in_progress", "conclusion": None}, {"conclusion": "skipped"}):
            with self.subTest(state=state), self.assertRaisesRegex(release.ReleaseError, "not successful"):
                release.require_passing_ci([
                    self.ci_run(),
                    self.ci_run(id=9, run_attempt=2, updated_at="2026-10-30T13:00:00Z", **state),
                ], self.sha)

    def test_incomplete_response_fails_closed(self):
        for runs in ([{}], [self.ci_run(updated_at="invalid")], [self.ci_run(updated_at=None)]):
            with self.assertRaises(release.ReleaseError):
                release.require_passing_ci(runs, self.sha)

    def test_api_query_and_credentials_are_scoped_and_not_logged(self):
        with patch.object(release.urllib.request, "build_opener") as opener:
            response = io.StringIO(json.dumps({"workflow_runs": [self.ci_run()]}))
            opener.return_value.open.return_value = response
            self.assertEqual([self.ci_run()], release.fetch_runs("LdesignMedia/moodle-local_guardlms", self.sha, "secret-token"))
            request = opener.return_value.open.call_args.args[0]
            self.assertIn("/actions/workflows/ci.yml/runs?", request.full_url)
            self.assertIn(f"head_sha={self.sha}", request.full_url)
            self.assertIn("event=push&branch=main", request.full_url)
            self.assertNotIn("secret-token", request.full_url)
            self.assertEqual("Bearer secret-token", request.get_header("Authorization"))
        with self.assertRaisesRegex(release.ReleaseError, "GH_TOKEN"):
            release.fetch_runs("LdesignMedia/moodle-local_guardlms", self.sha, "")

    def test_http_failure_does_not_expose_response_or_token(self):
        with patch.object(release.urllib.request, "build_opener") as opener:
            opener.return_value.open.side_effect = urllib.error.HTTPError("url", 401, "secret-token", {}, None)
            with self.assertRaises(release.ReleaseError) as error:
                release.fetch_runs("LdesignMedia/moodle-local_guardlms", self.sha, "secret-token")
            self.assertIn("401", str(error.exception))
            self.assertNotIn("secret-token", str(error.exception))

    def test_workflow_outputs_are_only_written_after_all_checks_pass(self):
        env = {"RELEASE_TAG": "v1.10.0", "GITHUB_REPOSITORY": "LdesignMedia/moodle-local_guardlms",
               "GH_TOKEN": "secret-token", "GITHUB_OUTPUT": str(Path.cwd() / "output")}
        for runs, expected in (([], 1), ([self.ci_run()], 0)):
            stdout, stderr = io.StringIO(), io.StringIO()
            with patch.dict(os.environ, env), patch.object(release, "fetch_runs", return_value=runs), \
                    contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                self.assertEqual(expected, release.main())
            self.assertNotIn("secret-token", stdout.getvalue() + stderr.getvalue())
            if expected:
                self.assertFalse(Path("output").exists())
                self.assertEqual("", stdout.getvalue())
            else:
                expected_output = f"tag=v1.10.0\nsha={self.sha}\n"
                self.assertEqual(expected_output, Path("output").read_text())
                self.assertEqual(expected_output, stdout.getvalue())


if __name__ == "__main__":
    unittest.main()
