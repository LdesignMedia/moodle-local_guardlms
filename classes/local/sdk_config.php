<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Stored real-time monitoring (SDK) configuration for local_guardlms.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\local;

/**
 * The plugin's view of the GuardLMS SDK configuration.
 *
 * Moodle's plugin config is a flat string store, so everything is kept as a
 * scalar and the two list-valued fields are JSON encoded. Typed getters cast on
 * read, which is what keeps the rest of the plugin free of casting noise.
 *
 * This class is the single writer for every `sdk*` config key. Nothing else in
 * the plugin may call set_config()/unset_config() for these names: one writer
 * and one name per key is what stops a rename leaving a reader behind (see the
 * plan's ADD-1). The full key inventory is PAYLOAD_KEYS + LOCAL_KEYS.
 */
class sdk_config {
    /** @var string Plugin component name, used for every config read and write. */
    public const COMPONENT = 'local_guardlms';

    /**
     * @var string Plugin release, emitted as part of appVersion.
     *
     * Kept in step with version.php's $plugin->release by
     * sdk_config_test::test_plugin_release_matches_version_php(), because the
     * emitted appVersion is load-bearing for the backend's platform alert
     * (it matches ^(wordpress|moodle)-) and must not drift silently.
     */
    public const PLUGIN_RELEASE = '1.5.1';

    /** @var int Moodle version that introduced the Hooks API (4.4). Below this nothing is injected. */
    public const HOOKS_API_VERSION = 2024042200;

    /** @var int Seconds between two synchronous bootstrap attempts from the settings page. */
    public const BOOTSTRAP_THROTTLE = 300;

    /**
     * @var array Config keys written from the backend payload, mapped payload field => config key.
     *
     * `key` is handled separately by store_payload(): the backend returns null
     * for it whenever a key already exists, and a null must never clear the key
     * the plugin is holding.
     */
    public const PAYLOAD_KEYS = [
        'key_prefix' => 'sdkkeyprefix',
        'sdk_url' => 'sdkurl',
        'errors_endpoint' => 'sdkerrorsendpoint',
        'analytics_endpoint' => 'sdkanalyticsendpoint',
        'enabled' => 'sdkbackendenabled',
        'subscription_active' => 'sdksubscriptionactive',
        'analytics_allowed' => 'sdkanalyticsallowed',
        'sample_rate' => 'sdksamplerate',
        'analytics_sample_rate' => 'sdkanalyticssamplerate',
        'max_breadcrumbs' => 'sdkmaxbreadcrumbs',
        'max_errors_per_minute' => 'sdkmaxerrorsperminute',
        'ignored_errors' => 'sdkignorederrors',
        'allowed_domains' => 'sdkalloweddomains',
        'allowed_domains_match' => 'sdkalloweddomainsmatch',
    ];

    /**
     * @var array Config keys owned by the plugin rather than the backend payload.
     *
     * `sdkbackendunsupported` is not in the plan's key list. It is required
     * because §5.3 row 2 ("backend too old") hides the whole section on every
     * subsequent page render, not only on the render that made the HTTP call,
     * so the 404/405 verdict has to persist. It is cleared by any later
     * successful refresh, so an upgraded backend recovers on its own.
     */
    public const LOCAL_KEYS = [
        'sdkenabled',
        'sdkanalytics',
        'sdkkey',
        'sdkrefreshedat',
        'sdkrefresherror',
        'sdkbootstrapattempt',
        'sdkbackendunsupported',
    ];

    /** @var string[] Error messages the SDK drops before sending. Merged with the dashboard's list. */
    public const DEFAULT_IGNORE_ERRORS = [
        'Script error.',
        'ResizeObserver loop limit exceeded',
        'ResizeObserver loop completed with undelivered notifications',
        'Non-Error promise rejection captured',
        'NetworkError when attempting to fetch resource.',
        'The operation is insecure.',
        'AbortError',
    ];

    /**
     * @var string[] Substrings whose values are scrubbed from URLs, stack traces and breadcrumbs.
     *
     * This array fully replaces the SDK's own defaults, so it must be revisited
     * whenever those change. `sesskey` matches none of the SDK defaults, and
     * Moodle puts its CSRF token in nearly every URL, so without it the token
     * ships with every error. `token` already covers `logintoken`. A bare `key`
     * is deliberately absent: matching is case-insensitive substring, so `key`
     * would redact `apiKey`, `sesskey` and much else besides.
     */
    public const REDACTED_KEYS = [
        'password',
        'secret',
        'token',
        'apiKey',
        'api_key',
        'authorization',
        'sesskey',
        'nonce',
    ];

    /** @var string[] Breadcrumb types the SDK is allowed to record. No click/form: see interaction note. */
    public const ENABLED_BREADCRUMB_TYPES = ['navigation', 'network', 'console', 'user'];

    /**
     * Whether the admin has opted in to real-time monitoring.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config(self::COMPONENT, 'sdkenabled');
    }

    /**
     * Whether the admin has opted in to analytics on top of error monitoring.
     *
     * @return bool
     */
    public static function analytics_opted_in(): bool {
        return (bool) get_config(self::COMPONENT, 'sdkanalytics');
    }

    /**
     * The SDK ingest key issued to this plugin.
     *
     * @return string
     */
    public static function key(): string {
        return trim((string) get_config(self::COMPONENT, 'sdkkey'));
    }

    /**
     * The first characters of the SDK key, safe to show to an admin.
     *
     * @return string
     */
    public static function key_prefix(): string {
        return trim((string) get_config(self::COMPONENT, 'sdkkeyprefix'));
    }

    /**
     * URL of the SDK bundle, including the content-hash cache buster.
     *
     * @return string
     */
    public static function script_url(): string {
        return trim((string) get_config(self::COMPONENT, 'sdkurl'));
    }

    /**
     * Endpoint the SDK posts error batches to.
     *
     * @return string
     */
    public static function errors_endpoint(): string {
        return trim((string) get_config(self::COMPONENT, 'sdkerrorsendpoint'));
    }

    /**
     * Endpoint the SDK posts analytics batches to.
     *
     * @return string
     */
    public static function analytics_endpoint(): string {
        return trim((string) get_config(self::COMPONENT, 'sdkanalyticsendpoint'));
    }

    /**
     * The GuardLMS dashboard master switch for this site (§5.3 row 5).
     *
     * @return bool
     */
    public static function backend_enabled(): bool {
        return (bool) get_config(self::COMPONENT, 'sdkbackendenabled');
    }

    /**
     * Whether the GuardLMS subscription is active (§5.3 row 4).
     *
     * @return bool
     */
    public static function subscription_active(): bool {
        return (bool) get_config(self::COMPONENT, 'sdksubscriptionactive');
    }

    /**
     * Whether the GuardLMS plan includes analytics tracking (§5.3 row 3).
     *
     * @return bool
     */
    public static function analytics_allowed(): bool {
        return (bool) get_config(self::COMPONENT, 'sdkanalyticsallowed');
    }

    /**
     * Whether this site's host is accepted by the dashboard's domain list (§5.3 row 6).
     *
     * Defaults to true: an empty allow list accepts everything, and a site that
     * has never fetched a payload must not be reported as mismatched.
     *
     * @return bool
     */
    public static function allowed_domains_match(): bool {
        $stored = get_config(self::COMPONENT, 'sdkalloweddomainsmatch');
        if ($stored === false || $stored === '') {
            return true;
        }

        return (bool) $stored;
    }

    /**
     * The hosts GuardLMS accepts data from. Empty means "any".
     *
     * @return string[]
     */
    public static function allowed_domains(): array {
        return self::decode_list('sdkalloweddomains');
    }

    /**
     * Error messages the SDK drops, the plugin's curated list plus the dashboard's.
     *
     * The dashboard list is additive: dropping the curated entries would let a
     * dashboard with an empty list reintroduce the browser-noise errors that
     * make an error feed unreadable.
     *
     * @return string[]
     */
    public static function ignore_errors(): array {
        return array_values(array_unique(array_merge(
            self::DEFAULT_IGNORE_ERRORS,
            self::decode_list('sdkignorederrors')
        )));
    }

    /**
     * Client-side error sample rate, 0.0 - 1.0.
     *
     * @return float
     */
    public static function sample_rate(): float {
        return self::clamp_rate(get_config(self::COMPONENT, 'sdksamplerate'));
    }

    /**
     * Client-side analytics sample rate, 0.0 - 1.0.
     *
     * @return float
     */
    public static function analytics_sample_rate(): float {
        return self::clamp_rate(get_config(self::COMPONENT, 'sdkanalyticssamplerate'));
    }

    /**
     * How many breadcrumbs the SDK keeps per error.
     *
     * @return int
     */
    public static function max_breadcrumbs(): int {
        $value = (int) get_config(self::COMPONENT, 'sdkmaxbreadcrumbs');

        return $value > 0 ? $value : 50;
    }

    /**
     * The client-side error cap, mirroring the server-side cap so the client stops first.
     *
     * @return int
     */
    public static function max_errors_per_minute(): int {
        $value = (int) get_config(self::COMPONENT, 'sdkmaxerrorsperminute');

        return $value > 0 ? $value : 60;
    }

    /**
     * When the last successful refresh completed, or 0 when there has never been one.
     *
     * @return int
     */
    public static function refreshed_at(): int {
        return (int) get_config(self::COMPONENT, 'sdkrefreshedat');
    }

    /**
     * The last refresh failure message, or an empty string when the last refresh worked.
     *
     * @return string
     */
    public static function refresh_error(): string {
        return trim((string) get_config(self::COMPONENT, 'sdkrefresherror'));
    }

    /**
     * Whether the GuardLMS backend is too old to know about SDK keys (§5.3 row 2).
     *
     * @return bool
     */
    public static function backend_unsupported(): bool {
        return (bool) get_config(self::COMPONENT, 'sdkbackendunsupported');
    }

    /**
     * Whether this Moodle is new enough for db/hooks.php to be honoured (§5.3 row 8).
     *
     * Below 4.4 the hook is never dispatched, so neither the verification meta
     * tag nor the SDK is injected and the toggle cannot do anything.
     *
     * @return bool
     */
    public static function moodle_supports_injection(): bool {
        global $CFG;

        return (int) $CFG->version >= self::HOOKS_API_VERSION;
    }

    /**
     * Whether a payload has ever been stored.
     *
     * Rows 3, 4, 5 and 6 of §5.3 are all facts asserted by the backend. Before
     * any payload arrives they are all false by default, which would make a
     * freshly connected site claim its subscription is inactive and its
     * dashboard switch is off. They are therefore only consulted once a refresh
     * has succeeded; until then row 1 ("not yet fetched") is the honest answer.
     *
     * @return bool
     */
    public static function has_payload(): bool {
        return self::refreshed_at() > 0;
    }

    /**
     * Whether the settings page should perform its synchronous bootstrap fetch.
     *
     * @return bool
     */
    public static function should_bootstrap(): bool {
        // A behat site must never call the live backend: the fetch would hit
        // the real GuardLMS API with the test site's push key, and its error
        // response would overwrite the very state the scenarios assert.
        if (defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING) {
            return false;
        }

        if (self::has_payload() || self::backend_unsupported()) {
            return false;
        }

        $lastattempt = (int) get_config(self::COMPONENT, 'sdkbootstrapattempt');

        return (time() - $lastattempt) >= self::BOOTSTRAP_THROTTLE;
    }

    /**
     * Record that a bootstrap attempt is being made, before the request is issued.
     *
     * Written first and unconditionally: a backend that hangs until the timeout
     * must still consume the throttle, otherwise every settings page view pays
     * the full timeout again.
     */
    public static function note_bootstrap_attempt(): void {
        set_config('sdkbootstrapattempt', time(), self::COMPONENT);
    }

    /**
     * Write the opt-in defaults for the two admin toggles.
     *
     * Called from db/upgrade.php. It lives here so the key names have exactly
     * one home: these two are the only sdk* keys with a second writer, Moodle's
     * own admin settings API, and it binds by the same names from settings.php.
     */
    public static function write_opt_in_defaults(): void {
        set_config('sdkenabled', 0, self::COMPONENT);
        set_config('sdkanalytics', 0, self::COMPONENT);
    }

    /**
     * Store a payload returned by the GuardLMS sdk-key endpoint.
     *
     * @param array $payload Decoded `data` from the backend response.
     */
    public static function store_payload(array $payload): void {
        // A null key means "you already have one" - never clear a held key.
        if (isset($payload['key']) && trim((string) $payload['key']) !== '') {
            set_config('sdkkey', trim((string) $payload['key']), self::COMPONENT);
        }

        foreach (self::PAYLOAD_KEYS as $field => $configkey) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            set_config($configkey, self::encode_value($payload[$field]), self::COMPONENT);
        }

        set_config('sdkrefreshedat', time(), self::COMPONENT);
        set_config('sdkrefresherror', '', self::COMPONENT);
        // A payload proves the backend understands the endpoint after all.
        set_config('sdkbackendunsupported', 0, self::COMPONENT);
    }

    /**
     * Record a failed refresh without disturbing the last good payload.
     *
     * The message is escaped here, where untrusted text enters plugin storage,
     * because it can carry a string chosen by the GuardLMS backend and every
     * consumer renders it as HTML: settings.php through html_writer::div(),
     * which concatenates its contents without escaping, and sdkrefresh.php
     * through redirect(), whose notification template prints the message with
     * triple-brace (unescaped) output. get_string() is no help either - it
     * substitutes {$a} with a plain str_replace.
     *
     * Escaping on store rather than at each render is deliberate: a future
     * third consumer cannot reintroduce the hole by forgetting, and this is
     * the single choke point through which all three error paths pass.
     *
     * @param string $message Human readable failure reason shown to the admin.
     */
    public static function record_refresh_error(string $message): void {
        set_config('sdkrefresherror', s($message), self::COMPONENT);
    }

    /**
     * Record that the backend does not implement the sdk-key endpoint (§5.3 row 2).
     *
     * No error is recorded: an old backend is not a failure the admin can act on.
     */
    public static function record_backend_unsupported(): void {
        set_config('sdkbackendunsupported', 1, self::COMPONENT);
        set_config('sdkrefresherror', '', self::COMPONENT);
    }

    /**
     * Remove every sdk* config value.
     *
     * Driven off the key inventory so a key added to PAYLOAD_KEYS or LOCAL_KEYS
     * is cleared without anyone having to remember a second list.
     */
    public static function clear(): void {
        foreach (array_merge(array_values(self::PAYLOAD_KEYS), self::LOCAL_KEYS) as $configkey) {
            unset_config($configkey, self::COMPONENT);
        }
    }

    /**
     * Every config key this class owns, for tests and for the ADD-1 inventory.
     *
     * @return string[]
     */
    public static function all_keys(): array {
        return array_merge(array_values(self::PAYLOAD_KEYS), self::LOCAL_KEYS);
    }

    /**
     * Whether the SDK should be injected into page heads right now.
     *
     * @return bool
     */
    public static function injection_allowed(): bool {
        // The version check is unreachable through today's only caller - below
        // 4.4 the hook never fires - but it is what makes this method mean what
        // its name says. Without it, wiring sdk_tags() into the legacy lib.php
        // callback would inject on exactly the sites whose settings page says
        // the toggle has no effect.
        return self::moodle_supports_injection()
            && self::is_enabled()
            && self::backend_enabled()
            && self::subscription_active()
            && self::key() !== ''
            && self::script_url() !== '';
    }

    /**
     * Whether the analytics block belongs in the emitted SDK config.
     *
     * @return bool
     */
    public static function analytics_active(): bool {
        return self::analytics_allowed() && self::analytics_opted_in();
    }

    /**
     * Resolve §5.3 to exactly one headline plus any advisories.
     *
     * The precedence chain is 2 -> 8 -> 5 -> 4 -> 7 -> 1. Rows 6 and 3 are
     * non-exclusive advisories: they render in addition to the headline,
     * because a domain mismatch or a missing analytics entitlement is worth
     * saying even on an otherwise healthy site.
     *
     * @return array{hidden: bool, row: int, headline: string, headlinedata: mixed,
     *               advisories: array, toggledisabled: bool, analyticsdisabled: bool}
     */
    public static function status(): array {
        $status = [
            'hidden' => false,
            'row' => 0,
            'headline' => 'sdk:statusready',
            'headlinedata' => null,
            'advisories' => [],
            'toggledisabled' => false,
            'analyticsdisabled' => false,
        ];

        // Row 2 - the backend does not offer the feature.
        if (self::backend_unsupported()) {
            if (!self::is_enabled()) {
                // Nothing is being injected and there is nothing the admin can
                // act on, so say nothing at all.
                $status['hidden'] = true;
                $status['row'] = 2;
                $status['headline'] = '';

                return $status;
            }

            // Monitoring is switched on, so the SDK is still loading on every
            // page from the payload already stored. Hiding the section here
            // would take away the only control for turning that off, leaving
            // third-party JavaScript running with no way to stop it - which is
            // how a backend that starts answering 404 would strand a live site.
            // Injection is deliberately NOT suppressed: one failed refresh must
            // not silently kill a working install. Say what is happening and
            // leave the toggle usable.
            $status['row'] = 2;
            $status['headline'] = 'sdk:backendunsupportedactive';

            return $status;
        }

        $haspayload = self::has_payload();

        // Advisories first: they are independent of whichever headline wins.
        if ($haspayload && !self::analytics_allowed()) {
            $status['advisories'][] = ['key' => 'sdk:analyticsnotinplan', 'data' => null];
            $status['analyticsdisabled'] = true;
        }
        if ($haspayload && !self::allowed_domains_match()) {
            $status['advisories'][] = [
                'key' => 'sdk:domainmismatch',
                // Both values are escaped for the same reason as the refresh
                // error: allowed_domains() is backend-supplied, and the string
                // is interpolated by get_string() and rendered inside a div,
                // neither of which escapes. The separator is ours, so each
                // element is escaped before the implode rather than after.
                'data' => (object) [
                    'allowed' => implode(', ', array_map('s', self::allowed_domains())),
                    'actual' => s(self::site_host()),
                ],
            ];
        }

        // Row 8 - this Moodle ignores db/hooks.php, so the toggle cannot work.
        if (!self::moodle_supports_injection()) {
            $status['row'] = 8;
            $status['headline'] = 'sdk:requires44';
            $status['toggledisabled'] = true;
            $status['analyticsdisabled'] = true;

            return $status;
        }

        // Row 5 - turned off in the GuardLMS dashboard.
        if ($haspayload && !self::backend_enabled()) {
            $status['row'] = 5;
            $status['headline'] = 'sdk:statusdashboardoff';

            return $status;
        }

        // Row 4 - no active subscription.
        if ($haspayload && !self::subscription_active()) {
            $status['row'] = 4;
            $status['headline'] = 'sdk:statusnosubscription';

            return $status;
        }

        // Row 7 - the last refresh failed. Wins over row 1, because "we tried
        // and here is why it failed" is strictly more useful than "no key yet".
        $error = self::refresh_error();
        if ($error !== '') {
            $status['row'] = 7;
            $status['headline'] = 'sdk:statusrefresherror';
            $status['headlinedata'] = $error;

            return $status;
        }

        // Row 1 - nothing fetched yet. The two cases need different sentences:
        // settings.php only renders the Refresh now link for a connected site,
        // so telling a disconnected admin to use it points at a control that is
        // not on the page. Connecting is the actual next step there.
        if (self::key() === '') {
            $status['row'] = 1;
            $status['headline'] = connect_manager::is_connected()
                ? 'sdk:statusnokey'
                : 'sdk:statusnotconnected';

            return $status;
        }

        // Success path: a usable key is held.
        $status['headline'] = self::is_enabled() ? 'sdk:statusactive' : 'sdk:statusready';

        return $status;
    }

    /**
     * The last successful refresh as a sentence, never an epoch and never empty.
     *
     * @return string
     */
    public static function last_refresh_text(): string {
        $refreshedat = self::refreshed_at();
        if ($refreshedat <= 0) {
            return get_string('sdk:norefreshyet', self::COMPONENT);
        }

        return get_string('sdk:lastrefresh', self::COMPONENT, userdate($refreshedat));
    }

    /**
     * This site's host, as GuardLMS sees it in the reported site URL.
     *
     * @return string
     */
    public static function site_host(): string {
        global $CFG;

        return (string) parse_url($CFG->wwwroot, PHP_URL_HOST);
    }

    /**
     * The appVersion string the SDK reports, e.g. moodle-4.5.2/local_guardlms-1.5.0.
     *
     * The backend's platform alert matches ^(wordpress|moodle)-, so the leading
     * segment is a wire contract and not cosmetic.
     *
     * @return string
     */
    public static function app_version(): string {
        global $CFG;

        $release = '';
        if (preg_match('/^[0-9]+(\.[0-9]+)*/', (string) $CFG->release, $matches)) {
            $release = $matches[0];
        }
        if ($release === '') {
            // A Moodle with an unparsable $CFG->release still deserves a usable
            // prefix; the alert only depends on the platform segment.
            $release = 'unknown';
        }

        return 'moodle-' . $release . '/local_guardlms-' . self::PLUGIN_RELEASE;
    }

    /**
     * Read a JSON encoded list back as a plain array of strings.
     *
     * @param string $configkey Config key holding the JSON.
     * @return string[]
     */
    protected static function decode_list(string $configkey): array {
        $raw = trim((string) get_config(self::COMPONENT, $configkey));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $list = [];
        foreach ($decoded as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $list[] = trim((string) $item);
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * Flatten a payload value into something Moodle's string config store can hold.
     *
     * @param mixed $value Value from the decoded payload.
     * @return string
     */
    protected static function encode_value($value): string {
        if (is_array($value)) {
            return (string) json_encode(array_values($value));
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Coerce a stored sample rate into the 0.0 - 1.0 the SDK expects.
     *
     * @param mixed $value Raw config value.
     * @return float
     */
    protected static function clamp_rate($value): float {
        if ($value === false || $value === '' || $value === null) {
            return 1.0;
        }

        return max(0.0, min(1.0, (float) $value));
    }
}
