# GuardLMS site info (local_guardlms)

A Moodle local plugin that exposes a read only web service reporting the site's
Moodle version and installed plugin inventory. [GuardLMS](https://github.com/LdesignMedia/guardlms)
reads this service to match the site against known CVEs.

## What it does

The plugin registers a single external function, `local_guardlms_get_site_info`,
behind a dedicated web service (`local_guardlms_service`). When called with a
valid token it returns:

- the Moodle release, version number and branch
- every installed plugin as its frankenstyle component name, version, release,
  display name, standard/third party flag and enabled state

GuardLMS matches CVEs on the component name and version, so the inventory is
returned with the raw values exactly as Moodle records them.

### Parameters

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `onlythirdparty` | bool | `false` | When true, only non-standard (third party) plugins are returned. |

### Example response

```json
{
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
      "version": "2026061300",
      "release": "1.0.0",
      "displayname": "GuardLMS",
      "isstandard": false,
      "enabled": -1
    }
  ],
  "generatedtime": 1781308800
}
```

## Installation

1. Copy the plugin to `local/guardlms` in your Moodle root.
2. Visit Site administration to run the upgrade.

## Configuration

1. Enable web services: Site administration > Advanced features > Enable web services.
2. Enable a protocol (REST is recommended): Site administration > Server > Web services > Manage protocols.
3. Create a dedicated service user and grant it the `local/guardlms:viewsiteinfo`
   capability at system level.
4. Add that user to the GuardLMS site info service: Site administration > Server >
   Web services > External services > GuardLMS site info > Authorised users.
5. Create a token for that user against the GuardLMS site info service.
6. Provide the token and site URL to GuardLMS.

## Requirements

- Moodle 3.9 or later.

## License

GNU GPL v3 or later.
