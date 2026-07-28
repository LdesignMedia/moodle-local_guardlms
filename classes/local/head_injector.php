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
 * Builds the GuardLMS head content: ownership meta tag and real-time SDK tags.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\local;

/**
 * Emits the guardlms-verification meta tag and the real-time monitoring SDK.
 */
class head_injector {
    /**
     * @var string Charset declaration emitted ahead of the SDK scripts.
     *
     * See sdk_tags_for() for why this is here rather than left to core.
     */
    public const CHARSET_META = '<meta charset="utf-8">' . "\n";

    /**
     * Build the meta tag HTML, or an empty string when not applicable.
     *
     * @return string
     */
    public static function meta_tag(): string {
        if (!get_config('local_guardlms', 'enabled')) {
            return '';
        }

        $token = trim((string) get_config('local_guardlms', 'verificationtoken'));
        if ($token === '') {
            return '';
        }

        return '<meta name="guardlms-verification" content="' . s($token) . '">' . "\n";
    }

    /**
     * Build the SDK script tags for this request, or an empty string.
     *
     * @return string
     */
    public static function sdk_tags(): string {
        return self::sdk_tags_for(self::current_request_env());
    }

    /**
     * Describe the parts of the current request the injection decision depends on.
     *
     * Split out from sdk_tags() so each guard can be exercised on its own. It
     * has to be: Moodle's PHPUnit bootstrap defines CLI_SCRIPT as true, so a
     * test calling sdk_tags() with no arguments can only ever observe the CLI
     * branch and every other guard would go untested.
     *
     * @return array{cli: bool, ajax: bool, pagelayout: string, selftest: bool}
     */
    public static function current_request_env(): array {
        global $PAGE;

        return [
            'cli' => defined('CLI_SCRIPT') && CLI_SCRIPT,
            'ajax' => defined('AJAX_SCRIPT') && AJAX_SCRIPT,
            'pagelayout' => isset($PAGE) ? (string) $PAGE->pagelayout : '',
            'selftest' => self::self_test_requested(),
        ];
    }

    /**
     * Build the SDK script tags for a described request environment.
     *
     * A raw synchronous <script src> is a deliberate deviation from
     * $PAGE->requires->js(), and it is the only thing that works here. In
     * /Users/luukverhoeven/PROJECTEN/LdesignMedia/moodle/lib/outputrenderers.php,
     * core_renderer::standard_head_html() at :693 constructs the hook at :708,
     * dispatches it at :710 and collects its output at :734, while
     * $PAGE->requires->get_head_code() only runs at :771. Hook output therefore
     * lands ahead of Moodle's AMD/requirejs bootstrap, which is exactly where an
     * error monitor wants to be. $PAGE->requires->js($url, true) would land at
     * :771 - later - and cannot be called from inside head generation anyway,
     * because the head is already being written by then.
     *
     * Ordering caveat, and why this emits its own charset meta. Core appends
     * its own <meta http-equiv="Content-Type" ... charset=utf-8> to the same
     * hook at :718, after plugin callbacks, so everything here precedes the
     * charset declaration and pushes it further into the head. HTML5 only
     * sniffs the first 1024 bytes for it. Measured, this block is 1151 bytes
     * with analytics enabled and 1018 without, so keeping it "compact enough"
     * is not a guard - it is already over budget, and one added ignoreErrors
     * entry would put the no-analytics case over too. Declaring utf-8 here,
     * ahead of the scripts, removes the dependency on the block's size for 29
     * bytes: the first declaration wins, core's later one is an identical
     * duplicate, and no future config growth can reintroduce the problem.
     *
     * This is belt and braces rather than a live bug fix: Moodle also sends
     * Content-Type: text/html; charset=utf-8 as an HTTP header
     * (lib/setuplib.php:2174), which takes precedence over any in-document
     * meta. The in-document declaration is what a saved or offline copy of the
     * page has to rely on.
     *
     * @param array $env Request environment from current_request_env().
     * @return string
     */
    public static function sdk_tags_for(array $env): string {
        // Nothing renders a page head under CLI or in an AJAX response.
        if (!empty($env['cli']) || !empty($env['ajax'])) {
            return '';
        }

        // Login pages carry credentials in form fields. A network breadcrumb of
        // a failed POST to /login/index.php plus console breadcrumbs is the
        // highest-risk page on the site for near-zero diagnostic value.
        // Everything else is in scope, /admin/* included: Moodle has no
        // front-end/back-end split, so for an admin /admin/ is the site, and
        // admin-page errors are among the most valuable to catch. What makes
        // that safe is the privacy posture below - no setUser, no click or form
        // breadcrumbs, sesskey redacted.
        if (($env['pagelayout'] ?? '') === 'login') {
            return '';
        }

        if (!sdk_config::injection_allowed()) {
            return '';
        }

        $url = self::script_src();
        if ($url === '') {
            return '';
        }

        $config = self::sdk_init_config();

        // JSON_HEX_TAG is what makes a closing-script-tag breakout impossible:
        // it rewrites every angle bracket as a unicode escape, so no payload
        // value can terminate the inline block. The other HEX flags close the
        // same door for quotes and ampersands; UNESCAPED_SLASHES only keeps the
        // URLs readable and adds no escaping of its own.
        $json = json_encode(
            $config,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );

        // Declared before the scripts, so the charset is settled inside the
        // sniffing window no matter how large the payload below grows.
        $tags = self::CHARSET_META;

        // No crossorigin attribute: it would require the GuardLMS host to send
        // Access-Control-Allow-Origin, and a host that does not would have the
        // script blocked outright rather than merely reporting opaque traces.
        $tags .= '<script src="' . s($url) . '"></script>' . "\n";

        // The guard matters: if the bundle fails to load, an unguarded
        // GuardLMS.init would throw a ReferenceError on every page of the site
        // because a CDN hiccuped. Failing silently is the right trade here.
        $script = 'if(window.GuardLMS){window.GuardLMS.init(' . $json . ');';
        if (!empty($env['selftest'])) {
            $script .= self::self_test_snippet();
        }
        $script .= '}';

        $tags .= '<script>' . $script . '</script>' . "\n";

        return $tags;
    }

    /**
     * The validated SDK bundle URL, or an empty string when it is not usable.
     *
     * @return string
     */
    public static function script_src(): string {
        $url = clean_param(sdk_config::script_url(), PARAM_URL);
        if ($url === '') {
            return '';
        }

        // HTTPS only. The SDK is third-party JavaScript executing on every page
        // of the site; served over http it is trivially replaceable in transit.
        if (strncasecmp($url, 'https://', 8) !== 0) {
            return '';
        }

        return $url;
    }

    /**
     * The exact option set handed to GuardLMS.init().
     *
     * @return array
     */
    public static function sdk_init_config(): array {
        $config = [
            'apiKey' => sdk_config::key(),
            'endpoint' => sdk_config::errors_endpoint(),
            'appVersion' => sdk_config::app_version(),
            'releaseStage' => 'production',
            'sampleRate' => sdk_config::sample_rate(),
            'maxBreadcrumbs' => sdk_config::max_breadcrumbs(),
            'maxErrorsPerMinute' => sdk_config::max_errors_per_minute(),
            // An IP is a GDPR identifier, and the receiving server observes it
            // at the transport layer anyway - there is nothing to gain by
            // having the client volunteer it as well.
            'collectUserIp' => false,
            // The single most important privacy knob. Selectors built for click
            // and form breadcrumbs on a quiz page carry question and answer
            // option identifiers; combined with pageUrl they reconstruct a
            // learner's answers.
            'interactionBreadcrumbsEnabled' => false,
            // FULL REPLACEMENTS, not merges. The SDK applies options with
            // Object.assign (sdk/src/index.js:218), so passing either of these
            // overwrites the SDK's own default array outright. A breadcrumb
            // type or a redaction key added to the SDK defaults in a later
            // release will therefore never reach this plugin - both lists have
            // to be revisited by hand whenever those defaults change.
            //
            // That matters most for redactedKeys: sesskey appears in nearly
            // every Moodle URL and matches none of the SDK's own defaults, so
            // if this list drifts from the plan's canonical set the CSRF token
            // ships inside pageUrl, sourceFile and stackTrace on every error.
            'enabledBreadcrumbTypes' => sdk_config::ENABLED_BREADCRUMB_TYPES,
            'redactedKeys' => sdk_config::REDACTED_KEYS,
            'ignoreErrors' => sdk_config::ignore_errors(),
        ];

        // The batchInterval option is deliberately absent: its stored value drifted
        // three ways across the backend and is enforced nowhere, so emitting it
        // could only regress flush latency from the SDK's 2s default.
        //
        // setUser is never called either. Sending {id,email,name} from an LMS
        // turns telemetry into processing of learning behaviour tied to a named
        // individual, which needs a DPA, a lawful basis and per-user consent -
        // none of which an admin checkbox provides. The SDK's own anonymousId
        // already delivers session stitching without identity, and staying
        // anonymous is what keeps the privacy provider honest.

        if (sdk_config::analytics_active()) {
            $config['analytics'] = [
                'enabled' => true,
                'endpoint' => sdk_config::analytics_endpoint(),
                'sampleRate' => sdk_config::analytics_sample_rate(),
                'trackScrollDepth' => true,
            ];
        }

        return $config;
    }

    /**
     * Whether this request asked for the admin self-test probe.
     *
     * Gated on the capability, not merely on being logged in: the probe writes
     * a deliberate error into the site's own error feed, so anyone who can
     * trigger it can add noise to it.
     *
     * @return bool
     */
    protected static function self_test_requested(): bool {
        if (!optional_param('guardlmsselftest', 0, PARAM_BOOL)) {
            return false;
        }

        try {
            return has_capability('moodle/site:config', \context_system::instance());
        } catch (\Throwable $e) {
            // Capability checks need a session; without one there is no admin
            // to run a self-test for.
            return false;
        }
    }

    /**
     * The probe that proves the SDK loaded and reached the backend.
     *
     * Deferred to a task so the throw cannot interrupt head parsing, and
     * distinct from init() so a failure to load leaves no trace at all. It
     * stores nothing: the flag lives entirely in the query string.
     *
     * @return string
     */
    protected static function self_test_snippet(): string {
        return 'setTimeout(function(){'
            . 'throw new Error("GuardLMS self-test error from local_guardlms");'
            . '},0);';
    }
}
