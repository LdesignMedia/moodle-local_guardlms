# Development and compatibility

[Back to README](../README.md)

## Continuous integration

Every push to `main` and every pull request runs the workflow in
`.github/workflows/ci.yml`. It reuses the
[Catalyst Moodle workflows](https://github.com/catalyst/catalyst-moodle-workflows),
which wrap [moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci) and
run the same checks the Moodle plugins directory applies on submission: PHP
lint, code checker, PHPDoc checker, plugin validation, upgrade savepoints,
Mustache lint, Grunt, PHPUnit and Behat. The shared matrix explicitly selects
Moodle 4.1, 4.5, 5.1 and 5.2 from the upstream workflow's available combinations.
These branches have working
snapshot images; the highest selected branch also runs the shared quality
checks. It tests multiple Moodle/PHP combinations with both PostgreSQL and
MariaDB; the PHPCompatibility check runs at PHP 7.4. Keep this explicit list
until the shared workflow's Moodle 5.3 snapshot images and MariaDB health
check are working.

An additional job explicitly tests `MOODLE_503_STABLE` on PHP 8.3 and 8.4
with MariaDB 11.4 and PostgreSQL 17, including PHPUnit and the plugin's Chrome
Behat scenarios. It installs Moodle from source and uses MariaDB's supported
health check, keeping Moodle 5.3 covered without the duplicate shared jobs.

### Moodle 5.3 compatibility (1.9.1)

On 5 October 2026, the plugin was tested on Moodle **5.3.0** (tag `v5.3.0`,
core commit `42622298fe06f9626d988d60b2bf589bd8f850e8`), PHP **8.4.25** and
MariaDB **11.4.7** at `moodle53.webvs.nl`, connected to GuardLMS staging:

- Fresh installation of 1.9.0 and upgrade to **1.9.1** (`2026100500`).
- One-time connect-code exchange and initial inventory delivery (HTTP 200),
  followed by the actual scheduled inventory task (HTTP 200).
- The stored staging snapshot contains Moodle 5.3, connector 1.9.1 and all
  412 installed plugins, plus the encrypted synthetic security-probe descriptor.
- Admin login and the connected settings page render successfully, including
  browser monitoring and PHP/SQL error opt-in controls. Requests without the
  required probe credential or refresh sesskey are refused.
- With browser monitoring temporarily enabled, the Moodle 5.3 head hook emits
  exactly one SDK initialization and one ownership verification tag.
- PHPUnit: **164 tests, 3,436 assertions, no failures**, using an isolated
  database. PHPUnit 11 reports 15 existing metadata deprecations.
- PHP lint: all 48 PHP files pass. Moodle CodeSniffer passes for the changed
  PHP files, and all four upgrade savepoints validate.

The staging exchange used the existing test website and a server-issued test
code; it did not exercise the dashboard consent screen in a browser. The
environment's public hostname remains protected by Cloudflare Access; HTTP
smoke checks used its loopback origin with the public host header.

To repeat the plugin suite in an initialized Moodle PHPUnit environment:

```bash
php vendor/bin/phpunit --testsuite local_guardlms_testsuite
```

Run the same checks locally before opening a pull request:

```bash
composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4
export PATH="$(pwd)/ci/bin:$(pwd)/ci/vendor/bin:$PATH"
moodle-plugin-ci install --plugin ./moodle-local_guardlms --db-host=127.0.0.1
moodle-plugin-ci phplint && moodle-plugin-ci phpcs --max-warnings 0 && moodle-plugin-ci phpunit
```
