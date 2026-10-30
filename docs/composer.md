# Composer installation

[Back to README](../README.md)

Native Composer installation requires a Composer-managed Moodle **5.2+** project,
such as one created with [moodle/seed](https://github.com/moodle/seed).
Manual and Marketplace installation still support Moodle 3.9 and later.

## Install from GitHub

Run these commands in the directory containing your **site's** `composer.json`:

```bash
composer config allow-plugins.moodle/composer-installer true
composer config repositories.guardlms vcs https://github.com/LdesignMedia/moodle-local_guardlms
composer require ldesignmedia/moodle-local_guardlms:dev-main
```

This installs the development branch. To pin a release, replace `dev-main` with a
tagged version that includes `composer.json`. Older tags without that file cannot
be installed this way. Commit your site's updated `composer.json` and
`composer.lock` to keep deployments reproducible.

Moodle's installer places the plugin in `public/local/guardlms` inside the Moodle
installation (`moodle/public/local/guardlms` in the default seed layout). Visit
**Site administration** to complete the installation or upgrade, then connect
GuardLMS from **Plugins → Local plugins → GuardLMS**.

## Packagist and releases

The package name is `ldesignmedia/moodle-local_guardlms`. It is not yet registered
on Packagist; the GitHub repository configuration above makes it installable
without registration.

To enable installation without that repository configuration, a maintainer must
[submit this repository to Packagist](https://packagist.org/packages/submit) and
enable its GitHub update hook. Once a release containing `composer.json` is
indexed, sites can use `composer require ldesignmedia/moodle-local_guardlms`.
Composer derives versions from Git tags; no version is hardcoded in the manifest.
Marketplace submission and Packagist registration are separate steps.

See [Moodle's Composer guide](https://moodledev.io/docs/5.2/guides/composer) for
site setup and local development with mirrored path repositories.
