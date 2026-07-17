# GuardLMS (local_guardlms)

A Moodle local plugin that reports the site to [GuardLMS](https://github.com/LdesignMedia/guardlms)
for security monitoring. Once a day the plugin pushes the Moodle version, the
installed plugin inventory and the server environment to GuardLMS over HTTPS.
GuardLMS matches the site against known CVEs.

## What it does

A daily scheduled task builds a payload and sends it to the configured GuardLMS
endpoint, authenticated with a bearer API key. The payload is a typed envelope so
sections can grow over time:

- `moodle`: release, version number, branch and every installed plugin as its
  frankenstyle component name, version, release, display name, standard/third
  party flag and enabled state
- `server`: operating system, hostname and webserver software
- `php`: PHP version, SAPI, loaded `php.ini`, memory limit, max execution time,
  upload and post size limits, timezone and the loaded extensions
- `config` (optional, off by default): selected security and session settings,
  such as the cookie policy, so GuardLMS can review how the site is hardened

Plugin versions are reported with the raw values exactly as Moodle records them,
because GuardLMS matches CVEs on the component name and version.

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
        "release": "4.5.3",
        "displayname": "Quiz",
        "isstandard": true,
        "enabled": 1
      },
      {
        "component": "local_guardlms",
        "type": "local",
        "name": "guardlms",
        "version": "2026061900",
        "release": "1.1.0",
        "displayname": "GuardLMS",
        "isstandard": false,
        "enabled": -1
      }
    ]
  },
  "server": {
    "os_family": "Linux",
    "os": "Linux",
    "hostname": "web01",
    "webserver": "Apache/2.4.58"
  },
  "php": {
    "version": "8.2.0",
    "sapi": "fpm-fcgi",
    "ini": "/etc/php/8.2/fpm/php.ini",
    "memory_limit": "512M",
    "max_execution_time": "30",
    "upload_max_filesize": "100M",
    "post_max_size": "100M",
    "timezone": "Europe/Amsterdam",
    "extensions": ["Core", "curl", "json", "..."]
  }
}
```

## Installation

1. Copy the plugin to `local/guardlms` in your Moodle root.
2. Visit Site administration to run the upgrade.

## Connect to GuardLMS (recommended)

1. Open Site administration > Plugins > Local plugins > Connect to GuardLMS.
2. Click **Connect to GuardLMS**. Your browser is sent to GuardLMS where you log
   in or create a **free** account and confirm the connection.
3. Done. The site is registered in GuardLMS, site ownership is verified
   automatically (the plugin serves a verification meta tag), the push key is
   installed, and the first inventory push is queued.

No API keys to copy, no web services, protocols, service users or tokens. The
daily push runs from Moodle cron; you can also run it on demand from Site
administration > Server > Scheduled tasks. If pushes ever fail or the push key
is about to expire, open the connect page and click **Reconnect to GuardLMS**.

## Manual configuration (fallback)

For advanced setups you can still configure the plugin by hand:

1. Open Site administration > Plugins > Local plugins > GuardLMS.
2. Paste a website-bound inventory push key. You can mint one via the GuardLMS
   API (`POST /api/websites/{id}/inventory-key`, requires an API key with the
   `websites:write` scope) or ask GuardLMS support.
3. Leave the base URL and endpoint path at their defaults unless GuardLMS support
   tells you otherwise.
4. Optionally enable "Include Moodle configuration" to also report selected
   security and session settings.

## Requirements

- Moodle 3.9 or later.

## License

GNU GPL v3 or later.
