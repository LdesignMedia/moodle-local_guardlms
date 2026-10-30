# Inventory and security checks

[Back to README](../README.md)

## Daily inventory

The scheduled task builds a typed payload and sends it to the GuardLMS
endpoint, authenticated with the site's push key:

- `moodle`: release, version number, branch, every installed plugin as its
  frankenstyle component name, version, release, display name, standard/third
  party flag, enabled state, public URL path below the
  site root (for example `/mod/quiz`), plus the updates Moodle itself reports
  as available for each of them
- `server`: operating system family, distribution and release,
  webserver name and version, and the session handler in use
- `php`: PHP version, SAPI, memory limit, max execution time,
  upload and post size limits, timezone and the loaded extensions
- `config`: the settings an auditor reads under
  Site administration > Security (site security, HTTP security,
  notifications), plus session, authentication, web service, debugging and
  update settings. The full key list is `collector::CONFIG_KEYS`. Secrets are
  never sent: the cron password, reCAPTCHA keys, IP allow/block lists and
  site policy are reported only as set/not-set flags (`cronremotepasswordset`,
  `recaptchaconfigured`, `allowedipset`, `blockedipset`, `sitepolicyset`)
- `securitychecks`: recognised checks from Site
  administration > Reports > Security, with fixed `ref`, `component`, `name`
  and `status` (`ok`, `info`, `warning`, `error`, `critical`, `na` or `unknown`).
  `summary` and `details` are always empty; unknown plugin checks are withheld.
- `security_config.hardening`: versioned aggregate
  observations; see Private hardening inventory below.

Plugin versions are reported with the raw values exactly as Moodle records them,
because GuardLMS matches CVEs on the component name and version.

### Available updates

Update information comes from Moodle's own update checker, so it is the same
list an admin sees under Site administration > Plugins > Plugins overview,
never a guess made by comparing version strings elsewhere.

That checker only reads the response cached by its last fetch. On a site whose
cron is broken, or where automatic checking is switched off, the cache is empty
or months old, and reporting it as-is would tell GuardLMS "no updates" when the
truth is "nobody looked". So the daily task refreshes the data itself whenever
the cache is older than 24 hours, and reports honestly when it cannot:

- `moodle.updatecheck` describes the state of the check: whether it is
  `enabled`, when it was `lastfetched`, whether the data is `stale`, and any
  `fetcherror` from the refresh attempt.
- Each plugin's `updates` key is **absent** when the data is stale, meaning
  "nobody checked", and **present but empty** when the plugin was checked and
  is current. GuardLMS relies on that distinction so an unchecked plugin is
  never displayed as up to date.
- `moodle.coreupdates` carries the same information for Moodle core, which the
  per-plugin API does not cover.

The refresh only happens on cron paths. The push that runs while an admin is
connecting the site stays on cached data, so the connect screen never blocks on
a request to download.moodle.org.

### Example payload

```json
{
  "platform": "moodle",
  "siteurl": "https://lms.example.com",
  "generatedtime": 1781308800,
  "moodle": {
    "release": "4.5.3+ (Build: 20250101)",
    "version": "2024100700.05",
    "branch": "405",
    "plugincount": 2,
    "plugins": [
      {
        "component": "mod_quiz",
        "type": "mod",
        "name": "quiz",
        "version": "2024100700",
        "versiondisk": "2024100700",
        "release": "4.5.3",
        "displayname": "Quiz",
        "isstandard": true,
        "enabled": 1,
        "relativepath": "/mod/quiz",
        "updates": []
      },
      {
        "component": "local_guardlms",
        "type": "local",
        "name": "guardlms",
        "version": "2026061900",
        "versiondisk": "2026061900",
        "release": "1.1.0",
        "displayname": "GuardLMS",
        "isstandard": false,
        "enabled": -1,
        "relativepath": "/local/guardlms",
        "updates": [
          {
            "version": "2026073100",
            "release": "1.5.0",
            "maturity": 200,
            "url": "https://moodle.org/plugins/local_guardlms"
          }
        ]
      }
    ],
    "updatecheck": {
      "enabled": true,
      "lastfetched": 1781305200,
      "stale": false,
      "fetcherror": null
    },
    "coreupdates": []
  },
  "server": {
    "os_family": "Linux",
    "os": "Linux",
    "webserver": "Apache/2.4.58"
  },
  "php": {
    "version": "8.2.0",
    "sapi": "fpm-fcgi",
    "memory_limit": "512M",
    "max_execution_time": "30",
    "upload_max_filesize": "100M",
    "post_max_size": "100M",
    "timezone": "Europe/Amsterdam",
    "extensions": ["Core", "curl", "json", "..."]
  },
  "config": {
    "cookiesecure": "1",
    "passwordpolicy": "1",
    "minpasswordlength": "8",
    "debugdisplay": "0",
    "cronremotepasswordset": "1",
    "...": "..."
  },
  "securitychecks": [
    {
      "ref": "core_passwordpolicy",
      "component": "core",
      "name": "Password policy",
      "status": "ok",
      "summary": "",
      "details": ""
    }
  ]
}
```

## Private hardening inventory (1.7.0)

The inventory includes a versioned
`security_config.hardening` block. It reports fixed booleans and aggregate counts:
web plugin installation policy, token expiry/restrictions, guest/self enrolment
instances, scheduled/ad hoc task retries, MFA configuration and ClamAV configuration.
Every section has an observation time and `observed`, `unsupported` or `failed`
status. An unavailable observation contains no metrics; it is never a zero/pass.

No user names, emails, user IDs, token values, enrolment keys, course names,
Per-user IP restriction lists, antivirus connection addresses, absolute filesystem paths,
SQL or exception text are included in these observations. Native security checks
export only allowlisted identifiers, fixed labels and verdicts. Their rendered
summaries/details and unrecognised third-party checks remain local. Inventory
also omits server hostname, code root, plugin absolute paths and PHP ini path.
The site URL and public component-relative URL paths remain necessary inventory
identifiers. Login-notification recipients and internal cURL host lists are
reduced to presence flags.

The observations describe configuration, not proof of exploitation or end-to-end
protection. MFA at an external identity provider, antivirus operation, external
backups and restore success are not established. A stopped push task cannot
report its own outage. GuardLMS must consider the last accepted push separately;
these are scheduled snapshots, not realtime monitoring.

This contract covers the server-side inventory. Optional browser monitoring is
a separate feature with its own privacy declaration (page URLs, browser/session
telemetry and errors); disable it if that transfer is not appropriate.


## Moodle MFA, reCAPTCHA and Mobile API observations (1.10.0)

Deploy the GuardLMS API support for issue #2776 before this connector: the
receiver validates the version-1 hardening block with a closed schema. The new
`recaptcha` and `mobile_api` groups remain version 1 and retain the existing
`status`/`observed_at` envelope.

- `mfa` adds `configuration_complete`, `manual_admins_present` and, when provable,
  `manual_admins_mfa_required`. Active manual-authentication site administrators
  and users with `moodle/site:config` at system context are checked locally.
  Only the resulting booleans are sent. No user factor enrolments, identities,
  user counts, secrets, sessions or IP addresses are exported or changed. A disabled
  MFA policy or a known static factor combination reaching 100 points without a
  challenge produces a negative enforcement verdict. Conditional factors and
  custom factors remain unknown when a safe conclusion is unavailable.
  Configuration completeness checks supported input factors and the 100-point
  threshold; it does not prove provider operation or user enrolment.
- `recaptcha` adds `keys_configured`, `self_registration_enabled` (active email
  self-registration with account creation allowed), `signup_enabled` (the
  auth_email switch) and `login_enabled` on Moodle versions exposing that switch.
  Both keys are reduced to presence booleans; no Google request, account details
  or key material is sent. The administrator must test key validity and domain
  registration locally.
- `mobile_api.enabled` means the mobile setting, global web services, REST
  protocol and official `moodle_mobile_app` service are all enabled. It does not
  establish whether anyone uses the mobile app. GuardLMS recommends disabling
  the API only if the site does not need the app.

A representative receiver contract is in
[`tests/fixtures/moodle-configuration-observations.json`](../tests/fixtures/moodle-configuration-observations.json).

Missing verdicts mean unknown. Check observation time and push again after
configuration changes; these are scheduled configuration snapshots.

## Offline SSRF policy and filesystem checks

The hardening block now includes `ssrf` and `filesystem`. Configured cURL
IP addresses, CIDRs and hostnames remain local; only the number of omitted rules
and fixed sample verdicts are exported. Moodle's own IP-rule
parser checks fixed metadata, loopback, private IPv4 and local IPv6 samples.
This detects gaps in nonempty lists without performing HTTP requests, DNS
lookups, metadata reads or exploitation. Passing samples do not establish full
subnet coverage or protection against redirects/DNS rebinding. URL downloader
state and broad port allowances provide context.

Filesystem checks inspect effective permissions of the collecting PHP process,
including the public directory and sampled code/config files, and whether
moodledata is inside the public directory. CLI and web contexts are separate;
cron results do not establish web-user permissions. The checks do not create
files or export absolute paths, owners or file contents. The receiver must
support these new sections before this connector is deployed.

## Synthetic external security probes

The inventory now includes a short-lived `external_probe` descriptor for GuardLMS.
It authorizes access only to a synthetic marker file and a synthetic cache-test
page; it is not a Moodle user credential. The scanner uses these resources to
check anonymous file access and shared-cache isolation without requesting user
records. The endpoints reject missing, incorrect and expired probe credentials.
The backend must support encrypted probe ingestion before these checks run.
The marker file is replaced on credential rotation rather than accumulated.
