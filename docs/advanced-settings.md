# Connection and advanced settings

[Back to README](../README.md)

## Connect to GuardLMS

1. Open Site administration > Plugins > Local plugins > GuardLMS.
2. New to GuardLMS? Choose **Create a free account** in the welcome banner.
   Registration opens in a new tab, so you can return to Moodle when ready.
   If you already have an account, skip this step.
3. Click **Connect**. Your browser is sent to GuardLMS where you log
   in or create a free account and confirm the connection.
4. Done. The site is registered in GuardLMS, site ownership is verified
   automatically, the push key is installed, and the first inventory push is
   queued.

Use **Open dashboard** in the banner whenever you want to review your site in
GuardLMS. Both links use the configured GuardLMS base URL, including staging or
self-hosted instances. Opening the banner links does not start a connection.

The daily push runs from Moodle cron. You can also run it on demand from Site
administration > Server > Scheduled tasks ("Push site information to
GuardLMS"). If pushes ever fail or the push key is about to expire, open the
settings page and click **Reconnect**. **Disconnect** removes the key and stops
reporting.

### Connection status

The settings page shows one of three states:

| Status | Meaning |
| --- | --- |
| **Connected** | The push key works. Pushes are being accepted. |
| **Reconnect required** | GuardLMS refused this site's key (HTTP 401 or 403). The key was revoked, or the website it belonged to was removed from the GuardLMS dashboard. The site has stopped reporting; click **Reconnect** to issue a new key. |
| **Not connected** | No key is installed. |

"Reconnect required" also names the date of the first refused push, so it is
clear since when the site stopped reporting. The plugin never deletes the key on
its own: a GuardLMS outage that answers 401 for a while resolves itself, and the
state clears as soon as a push is accepted again.

## Advanced settings (support and self-hosted only)

The settings page shows the Connect button, the connection status and the
real-time monitoring section, nothing else. The connection internals are hidden
so nobody can break a working setup by editing them. They are still reachable
by URL:

```
/admin/settings.php?section=local_guardlms&mode=advanced
```

That page exposes the GuardLMS base URL, the push path, the daily-push toggle
and an optional site URL override (for sites GuardLMS knows under a different
address). The push key, the
monitoring key and the verification token are never editable: the connect flow
writes them.

If an inventory push reports a site URL mismatch, the normal settings page shows
both the registered address and the address sent by the failed push. Check
Moodle's browser and CLI configuration: scheduled tasks must report the same
address used to connect. The site URL override applies to inventory and SDK key
requests, but does not rewrite actual page URLs in error reports. Use it only
for another address of the same installation. A cloned site should connect
under its own address; the plugin never adopts the registered address
automatically. The warning clears after an accepted inventory push or reconnect.

To pin the base URL for good (self-hosted GuardLMS, or a development instance),
set it in `config.php` instead, which also makes it read-only in advanced mode:

```php
$CFG->forced_plugin_settings['local_guardlms']['baseurl'] = 'https://guardlms.example.com';
```
