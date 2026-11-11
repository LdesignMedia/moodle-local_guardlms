<p align="center">
  <a href="https://guardlms.com">
    <img src=".github/assets/banner-1544x500.png" alt="GuardLMS: continuous CVE monitoring for your Moodle site" width="772">
  </a>
</p>

# GuardLMS for Moodle

[![CI](https://github.com/LdesignMedia/moodle-local_guardlms/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/LdesignMedia/moodle-local_guardlms/actions/workflows/ci.yml)
[![CAMP](https://camp-registry.org/badge/local_guardlms.svg)](https://camp-registry.org/plugin/local_guardlms.html)

Connect your Moodle site to [GuardLMS](https://guardlms.com) for vulnerability
alerts, update reminders and security configuration checks.

The plugin reports your software inventory once a day. Optional error monitoring
helps you investigate browser and server problems from the GuardLMS dashboard.

## Features

- Daily Moodle and plugin inventory for vulnerability and update monitoring.
- Bundled PHP/JavaScript library metadata for dependency risk findings.
- Security configuration checks, including MFA, reCAPTCHA and Mobile API settings.
- Optional JavaScript, PHP and SQL error reporting.
- Optional page views and scroll-depth analytics on supported plans.
- One-click connection with clear status and reconnect controls.

## Requirements

- Moodle **3.9+** and PHP **7.4+**, using a PHP version supported by your Moodle release.
- A GuardLMS account, outbound HTTPS access and working Moodle cron.
- Individual checks depend on the features available in your Moodle version.

## Install and connect

1. Install from the [Moodle plugins directory](https://moodle.org/plugins/local_guardlms)
   or through **Site administration → Plugins → Install plugins**.
2. Visit **Site administration** to complete the installation or upgrade.
3. Open **Plugins → Local plugins → GuardLMS**.
4. Click **Connect**, sign in to GuardLMS and confirm the connection.
5. Use **Open dashboard** to view your site's results.

For manual installation, place the plugin in `local/guardlms` on Moodle 3.9–5.0,
or `public/local/guardlms` on Moodle 5.1 and later.

Composer-managed Moodle **5.2+** sites can use the
[Composer installation guide](docs/composer.md).

Inventory is sent daily by cron. To send it now, run **Push site information to
GuardLMS** under **Server → Scheduled tasks**. If the plugin asks you to reconnect,
use **Reconnect** on its settings page.

## Optional monitoring

Enable only the features you need in the plugin settings:

- **Enable real-time monitoring** — JavaScript errors from the browser.
- **Enable page analytics** — page views and scroll depth; requires an eligible plan.
- **Report PHP and SQL errors** — server errors; separate opt-in, off by default.

Monitoring requires a connected site and active GuardLMS monitoring.
See [monitoring setup and data sharing](docs/monitoring.md) for details.

## Privacy

The inventory reports software, environment and selected configuration metadata.
Account records, passwords, reCAPTCHA keys and configured IP lists stay in Moodle.

Optional browser monitoring sends page URLs and diagnostic context. PHP/SQL error
reporting can also send redacted SQL and request parameters. Review the
[monitoring privacy details](docs/monitoring.md) before enabling these features,
and the [inventory reference](docs/inventory.md) for the configuration payload.

## Documentation

- [Connection, troubleshooting and advanced settings](docs/advanced-settings.md)
- [Browser monitoring, analytics and server errors](docs/monitoring.md)
- [Inventory, security checks and payload reference](docs/inventory.md)
- [Development, tests and compatibility](docs/development.md)
- [Releasing to Moodle Marketplace and CAMP](docs/releases.md)
- [Release notes](CHANGELOG.md)

## Support and license

Report problems through [GitHub issues](https://github.com/LdesignMedia/moodle-local_guardlms/issues).
Learn more at [guardlms.com](https://guardlms.com).

Licensed under [GNU GPL v3 or later](LICENSE).

### PHP diagnostic availability (1.12.0)

Deploy the GuardLMS receiver supporting issue LdesignMedia/guardlms#2935 before
upgrading: older receivers reject the new `hardening.php_functions` section.
The connector observes `phpinfo` availability with `function_exists` and the local
`disable_functions` list, without calling the function or exporting that list.
Only a runtime label, availability/presence booleans and an allowlisted FFI mode leave Moodle. Web and CLI observations remain
separate; use a manual web administration push for web-runtime evidence. Scheduled
CLI pushes cannot establish web availability. Page access is not tested, and
function availability does not establish public exposure. PHP configuration is
never changed. Unknown SAPIs and collection failures remain unsupported/failed.

The same section reports `pcntl_loaded` via `extension_loaded`, and `ffi_mode`
(`unavailable`, `disabled`, `preload`, `enabled`) from extension presence and the
local `ffi.enable` setting. Unknown modes are omitted. No PCNTL/FFI execution,
library loading, or preload/configuration paths are collected. PCNTL presence
does not prove individual functions are callable. FFI preload is restricted but
not equivalent to disabled; CLI requirements remain separate from web advice.

### Enrolment and executable prerequisite evidence (1.12.0)

The hardening envelope includes `enrolment_methods` (global enablement, course
instance presence, enabled instance presence, historical user-enrolment presence)
and `external_tools` (configured path presence and, where verified by filesystem
metadata, executable-file presence). No course/user identifiers, enrolment keys,
paths or command arguments are exported. Hidden courses are included; usage does
not prove current effective access. Missing settings and unreadable executables
remain unknown. Binaries are never executed, including to obtain versions.

External tools cover configured PHP, du, dot, Ghostscript, pdftoppm, Python,
unoconv, aspell, ClamAV, LaTeX, dvips, dvisvgm and ImageMagick settings. Runtime is
labelled web/CLI. A configured executable does not prove feature enablement,
actual use, successful execution or a vulnerable binary version. GuardLMS uses
these facts as context and in reviewed CVE-specific prerequisite rules, never as
a blanket reason to suppress enrolment or binary vulnerabilities.
