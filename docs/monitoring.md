# Monitoring and privacy

[Back to README](../README.md)

## Real-time monitoring

Real-time monitoring needs a connected site. On Moodle 4.4 and later the
script is injected through the Hooks API (`db/hooks.php`); on Moodle 3.9 to
4.3 the legacy `before_standard_html_head` callback in `lib.php` does the same
job. The output and its position in the page head are identical on both paths.

### How it works

1. Connect the site. The plugin fetches its monitoring key and the SDK
   settings (script URL, endpoints, sample rates, ignored errors, allowed
   domains) from GuardLMS and caches them in plugin config.
2. Tick **Enable real-time monitoring** on the settings page and save.
3. From then on the GuardLMS SDK is loaded on every page. Browser errors are
   sent to GuardLMS in batches with the error message, script and line, stack
   trace, and a short trail of preceding page loads, network requests and
   console messages.

The SDK settings are refreshed from GuardLMS when the site connects, when the
plugin is upgraded, when the settings page is saved (through an ad hoc task on
cron) and when the settings page is opened after a while, so changes made in
the dashboard (sample rate, ignored errors, allowed domains, switching
monitoring off) reach the site without touching Moodle. The settings page also
has a **Refresh now** button and a **Send a test error** button to confirm the
pipeline end to end.

### Page analytics

**Enable page analytics** adds page views and scroll depth on top of
error reporting. It requires a GuardLMS plan that includes analytics; on other
plans the checkbox is disabled and error monitoring keeps working.

### What is and is not sent

- Sent: the page URL and referrer, the browser, operating system and window
  size, a session identifier that groups errors from one browsing
  session, and the error details listed above.
- The plugin does not attach Moodle user IDs to browser reports. Page URLs
  and error content still need to be considered when reviewing data sharing.
- Scrubbed before sending: values of `sesskey`, `token`, `password`, `secret`,
  `apiKey`, `authorization` and `nonce` in URLs, stack traces and breadcrumbs.
- Dropped: known browser noise such as "Script error." and ResizeObserver
  warnings, plus whatever you add to the ignore list in the dashboard. Reports
  are also rate limited per minute.
- The browser session identifier is separate from the Moodle user ID. The
  receiving server can observe the source IP address even though the plugin
  does not add an IP field to browser reports.

These browser settings are separate from the server-error opt-in described below.

### Status messages

The real-time section on the settings page tells you why monitoring is or is
not active: not connected yet, key not fetched yet, ready to switch on, active,
no active subscription, switched off in the dashboard, domain mismatch between
the site URL and the allowed domains in GuardLMS, analytics not in your plan,
or the last refresh error.

## Server errors (opt-in)

Enable **Report PHP and SQL errors** in the plugin settings. This is independent
of the browser SDK checkbox and defaults to off. A connected site, valid SDK key
and active GuardLMS monitoring are required. Deploy GuardLMS with PHP/SQL ingest
support and run both the GuardLMS migration and Moodle plugin upgrade first.

To enforce the setting in `config.php` (before loading `lib/setup.php`):

```php
$CFG->forced_plugin_settings['local_guardlms']['servererrorsenabled'] = true;
```

Set it to `false` to enforce an opt-out. When enabled and connected, the plugin
forces `$CFG->dboptions['logerrors'] = true` and updates the active database
connection's copied option. The native option is **logerrors**, despite the
singular `logerror` in some config examples. Existing `logslow` and other options
are preserved. No config.php file is rewritten; overrides last for the request.

Uncaught PHP exceptions (including SQL failures) are reported through a chained
exception handler. PHP warnings and fatal errors are sent at request shutdown.
Committed native SQL error logs, including caught SQL failures, are also sent at
shutdown; a task retries up to 50 pending SQL logs each minute. Delivery receipts
avoid replay across concurrent requests and preserve late-committing logs.
An uncaught SQL exception with a matching committed native log is sent only via
that log. Transactional exceptions are sent directly before core rolls back.

Reports appear under **PHP** and **SQL** in the existing realtime error overview.
Stack traces omit function arguments, and raw exception debuginfo is never
transmitted. SQL errors do carry the executed statement and bound parameters as
`sqlQuery`/`sqlParams` (parsed from debuginfo when debugging is on, otherwise from
the native SQL log), plus the full request URL with query string, the request
method and GET/POST parameters (`requestMethod`/`requestQuery`/`requestPost`), so
the failing request is reproducible from the dashboard. Sensitive parameter names
(`sesskey`, passwords, tokens, API keys) are masked by name, and password hashes
and emails are redacted before sending.
Moodle's native `log_queries` table **does** store SQL text and parameters locally;
its retention and cleanup remain the site administrator’s responsibility. Plugin delivery receipts store log IDs only.
Native SQL logs contain no request URL or handled flag, so their reports use the
site root and the context “Moodle SQL error log”.

Coverage starts after Moodle configuration. Exceptions caught and consumed by
application code are not visible to PHP's global exception handler; caught SQL
failures are available only if their native logs survive transaction rollback.
The standard Moodle AJAX dispatcher is also observed: its error response is
forwarded unchanged, even when it catches the exception internally. AJAX reports
include a stack trace only when Moodle includes one in the response; this feature
does not enable debug output. Core error pages which invoke the default handler
directly are observed during head rendering. Other custom replacement handlers
(for example cron or third-party web-service handlers) must cooperate. PHP delivery is best-effort
with a 1.5-second HTTP timeout; SQL delivery is retried while native logs remain.
A hard process kill or out-of-memory failure may prevent shutdown reporting.

The AJAX observer is restricted to core `lib/ajax/service.php` and
`service-nologin.php`, buffers at most 1 MiB and sends at most 20 exceptions per
response. Successful response data is not transmitted. Redacted request context may
accompany exception reports as described above.
A caught SQL failure surfaced by AJAX may also produce a separate native SQL-log
report, because core does not expose a correlation ID or (in production) a trace.
