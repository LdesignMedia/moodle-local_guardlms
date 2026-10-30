# Releasing to Moodle Marketplace

[Back to README](../README.md)

Publishing a GitHub Release starts the **Release to Moodle Marketplace** workflow.
It submits the tagged plugin ZIP through Moodle's
[official reusable workflow](https://github.com/moodlehq/moodle-plugin-release).
Pushing a tag alone does not submit it.

## One-time setup

1. Sign in to [Moodle Marketplace](https://marketplace.moodle.com/account/security)
   with an account that can maintain `local_guardlms`.
2. Create an API token under **Account → Security → API tokens**. Copy it when
   shown; Marketplace does not let you retrieve it later.
3. In this repository's **Settings → Secrets and variables → Actions**, add a
   repository secret named **`MOODLE_MARKETPLACE_TOKEN`** with that value.

The workflow needs no additional GitHub token. Its built-in token can only read
repository contents and, in the validation job, CI results. The Marketplace token
is passed only to the submission job.

## Release a version

1. Update `$plugin->version` and `$plugin->release` in `version.php`, along with
   `sdk_config::PLUGIN_RELEASE` and the existing release metadata test. Every new
   Marketplace version needs a higher build number.
2. Merge into `main` and wait for the **ci** workflow to pass on the exact commit
   you want to release. Deploy any required GuardLMS receiver changes first.
3. Create a GitHub Release with a tag matching **`v<plugin release>`**, such as
   `v1.10.0`. Use that tested commit and write the release notes in the release body.
4. Publish the GitHub Release. The workflow checks the tag, release name,
   `main` ancestry and the latest matching `main` CI result before submitting.
5. Review the workflow response and the version's status in Marketplace.

Tags use `vMAJOR.MINOR.PATCH`, optionally followed by a prerelease suffix such as
`-rc.1`. The suffix must also appear in `$plugin->release`. The workflow checks
both annotated and lightweight tags.
Do not give a branch the same name as a release tag.

Marketplace reads the build, release name, maturity and Moodle compatibility
from `version.php`. The GitHub prerelease checkbox does not override those values;
release automation does not change the plugin's declared maturity.

## Retry an existing tag

After correcting a failed prerequisite, open **Actions → Release to Moodle
Marketplace → Run workflow**, select `main`, and enter the existing tag. Optional
release notes override the normal fallback to its GitHub Release body or changelog.
If `main` CI is still running, wait for it to pass before retrying.

If a previous submission already returned HTTP 201, check Marketplace before
retrying: that response means the version was accepted and automated checks were
queued, not that it is already publicly available. Do not move a released tag to
a different commit; use a new build and release for subsequent changes.

The API returns validation details for rejected packages. An expired or invalid
token must be replaced in the repository secret. The workflow never creates a
Marketplace token or changes Marketplace account permissions.

## Maintenance

The caller pins Moodle's release workflow to a reviewed commit. Review upstream
changes before updating that pin. The package is built with `git archive` and
respects `.gitattributes`; GitHub workflow files are excluded.

See the [Marketplace API reference](https://moodledev.io/general/community/plugincontribution/marketplace-api/add-plugin-version)
for submission responses and the required archive metadata.
