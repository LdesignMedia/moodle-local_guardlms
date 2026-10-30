#!/usr/bin/env python3
"""Validate the release ref and its main-branch CI before exposing submission outputs."""

import datetime
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request


class ReleaseError(Exception):
    """A release prerequisite was not satisfied."""


def git(*args):
    result = subprocess.run(["git", *args], capture_output=True, text=True, check=False)
    if result.returncode:
        raise ReleaseError("The release tag, checkout or origin/main could not be verified.")
    return result.stdout.strip()


def validate_checkout(tag):
    if not re.fullmatch(r"v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[A-Za-z0-9]+(?:[.-][A-Za-z0-9]+)*)?", tag):
        raise ReleaseError("Use a tag such as v1.10.0 or v1.10.0-rc.1.")
    # The upstream publisher checks out the bare tag name, which can prefer a branch.
    branch = subprocess.run(["git", "show-ref", "--verify", "--quiet", f"refs/remotes/origin/{tag}"], check=False)
    if branch.returncode == 0:
        raise ReleaseError("A remote branch has the same name as the release tag; remove the ambiguity before publishing.")
    if branch.returncode != 1:
        raise ReleaseError("Remote branch names could not be verified.")
    sha = git("rev-parse", "--verify", f"refs/tags/{tag}^{{commit}}")
    if git("rev-parse", "HEAD") != sha:
        raise ReleaseError("The checked-out commit does not match the release tag.")
    git("merge-base", "--is-ancestor", sha, "refs/remotes/origin/main")

    # Ignore comments while preserving quoted strings. Never execute version.php.
    source = git("show", f"{sha}:version.php")
    tokens = r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"|//[^\n]*|\#[^\n]*|/\*[\s\S]*?\*/"
    source = re.sub(tokens, lambda m: m[0] if m[0][0] in "'\"" else "\n" * m[0].count("\n"), source)
    fields = {}
    for name in ("component", "release", "version"):
        values = re.findall(rf"(?m)^\s*\$plugin\s*->\s*{name}\s*=\s*([^;\n]+);", source)
        if len(values) != 1:
            raise ReleaseError(f"version.php must contain one literal {name} assignment.")
        value = values[0].strip()
        if name == "version":
            if not re.fullmatch(r"[1-9][0-9]*", value):
                raise ReleaseError("version.php must contain a positive integer build number.")
        else:
            literal = re.fullmatch(r"'([^'\\]*)'|\"([^\"\\$]*)\"", value)
            if not literal:
                raise ReleaseError(f"version.php must contain a literal {name} string.")
            value = literal[1] if literal[1] is not None else literal[2]
        fields[name] = value
    if fields["component"] != "local_guardlms":
        raise ReleaseError("The release package must be local_guardlms.")
    if fields["release"] != tag[1:]:
        raise ReleaseError("The tag must be v followed by the exact release in version.php.")
    return sha


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def fetch_runs(repository, sha, token):
    if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository):
        raise ReleaseError("GITHUB_REPOSITORY must identify the owner and repository.")
    if not token:
        raise ReleaseError("GH_TOKEN is required to read the main-branch CI result.")
    query = urllib.parse.urlencode({"head_sha": sha, "event": "push", "branch": "main", "per_page": 100})
    request = urllib.request.Request(
        f"https://api.github.com/repos/{repository}/actions/workflows/ci.yml/runs?{query}",
        headers={"Authorization": f"Bearer {token}", "Accept": "application/vnd.github+json",
                 "X-GitHub-Api-Version": "2022-11-28", "User-Agent": "guardlms-marketplace-release"},
    )
    try:
        with urllib.request.build_opener(NoRedirects).open(request, timeout=30) as response:
            data = json.load(response)
        runs = data["workflow_runs"]
        if not isinstance(runs, list):
            raise ValueError
        return runs
    except urllib.error.HTTPError as error:
        error.close()
        raise ReleaseError(f"GitHub CI lookup returned HTTP {error.code}; check Actions read permission.") from None
    except (OSError, ValueError, KeyError, TypeError):
        raise ReleaseError("GitHub CI lookup failed; no version will be submitted.") from None


def require_passing_ci(runs, sha):
    retry = "Wait for this commit's main CI to pass, then retry the release workflow manually."
    try:
        matches = [run for run in runs if run["head_sha"] == sha
                   and run["head_branch"] == "main" and run["event"] == "push"]
        if not matches:
            raise ReleaseError("No main-branch CI run was found for the tagged commit. " + retry)
        # updated_at accounts for a failed/in-progress rerun of an older run too.
        latest = max(matches, key=lambda run: (
            datetime.datetime.fromisoformat(run["updated_at"].replace("Z", "+00:00")),
            run["id"], run.get("run_attempt", 1),
        ))
        if latest["status"] != "completed" or latest["conclusion"] != "success":
            raise ReleaseError("The latest CI result for the tagged commit is not successful. " + retry)
    except (AttributeError, KeyError, TypeError, ValueError):
        raise ReleaseError("GitHub returned an incomplete CI result; no version will be submitted.") from None


def main():
    try:
        tag = os.environ.get("RELEASE_TAG", "")
        sha = validate_checkout(tag)
        runs = fetch_runs(os.environ.get("GITHUB_REPOSITORY", ""), sha, os.environ.get("GH_TOKEN", ""))
        require_passing_ci(runs, sha)
        output = f"tag={tag}\nsha={sha}\n"
        if os.environ.get("GITHUB_OUTPUT"):
            with Path(os.environ["GITHUB_OUTPUT"]).open("a", encoding="utf-8") as handle:
                handle.write(output)
        print(output, end="")
        return 0
    except ReleaseError as error:
        print(f"Release check failed: {error}", file=sys.stderr)
    except OSError:
        print("Release check failed: unable to access the checkout or workflow output.", file=sys.stderr)
    return 1


if __name__ == "__main__":
    sys.exit(main())
