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

namespace local_guardlms;

use local_guardlms\local\sdk_config;

/**
 * Tests for the stored SDK configuration and the failure-state precedence chain.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_guardlms\local\sdk_config
 */
final class sdk_config_test extends \advanced_testcase {
    /**
     * A payload shaped like a healthy backend response.
     *
     * @param array $overrides Fields to replace.
     * @return array
     */
    private function payload(array $overrides = []): array {
        return $overrides + [
            'key' => 'glms_' . str_repeat('a', 56),
            'key_status' => 'issued',
            'key_prefix' => 'glms_aaa',
            'sdk_url' => 'https://dashboard.guardlms.com/sdk/guardlms.min.js?v=abc123def456',
            'errors_endpoint' => 'https://dashboard.guardlms.com/api/sdk/errors/collect',
            'analytics_endpoint' => 'https://dashboard.guardlms.com/api/sdk/analytics/collect',
            'enabled' => true,
            'subscription_active' => true,
            'analytics_allowed' => true,
            'sample_rate' => 1.0,
            'analytics_sample_rate' => 0.5,
            'max_breadcrumbs' => 50,
            'max_errors_per_minute' => 60,
            'batch_interval_seconds' => 10,
            'ignored_errors' => ['Custom dashboard noise'],
            'allowed_domains' => [],
            'allowed_domains_match' => true,
        ];
    }

    /**
     * Put the site into the healthy, monitored state.
     */
    private function set_up_healthy(): void {
        global $CFG;

        // Pin a Moodle new enough for the Hooks API so row 8 never fires by
        // accident on whichever core this suite happens to run against.
        $CFG->version = sdk_config::HOOKS_API_VERSION;
        sdk_config::store_payload($this->payload());
        set_config('sdkenabled', 1, 'local_guardlms');
        set_config('sdkanalytics', 1, 'local_guardlms');
    }

    /**
     * Typed getters cast the flat string store back to usable types.
     */
    public function test_typed_getters_cast_on_read(): void {
        $this->resetAfterTest();

        sdk_config::store_payload($this->payload([
            'sample_rate' => '0.25',
            'max_breadcrumbs' => '17',
            'max_errors_per_minute' => '90',
        ]));

        $this->assertSame(0.25, sdk_config::sample_rate());
        $this->assertSame(17, sdk_config::max_breadcrumbs());
        $this->assertSame(90, sdk_config::max_errors_per_minute());
        $this->assertTrue(sdk_config::backend_enabled());
        $this->assertTrue(sdk_config::subscription_active());
        $this->assertIsArray(sdk_config::allowed_domains());
    }

    /**
     * A sample rate outside 0.0-1.0 is clamped rather than passed through.
     */
    public function test_sample_rate_is_clamped(): void {
        $this->resetAfterTest();

        set_config('sdksamplerate', '5', 'local_guardlms');
        $this->assertSame(1.0, sdk_config::sample_rate());

        set_config('sdksamplerate', '-2', 'local_guardlms');
        $this->assertSame(0.0, sdk_config::sample_rate());
    }

    /**
     * An unset sample rate reads as 1.0, not as 0.0.
     *
     * A zero would silently discard every error, which is the worst possible
     * reading of "no value stored".
     */
    public function test_missing_sample_rate_defaults_to_one(): void {
        $this->resetAfterTest();

        unset_config('sdksamplerate', 'local_guardlms');

        $this->assertSame(1.0, sdk_config::sample_rate());
    }

    /**
     * Lists survive the JSON round trip through the flat string store.
     */
    public function test_lists_round_trip_through_json(): void {
        $this->resetAfterTest();

        sdk_config::store_payload($this->payload([
            'allowed_domains' => ['example.com', 'www.example.com'],
            'ignored_errors' => ['Dashboard noise'],
        ]));

        $this->assertSame(['example.com', 'www.example.com'], sdk_config::allowed_domains());
        $this->assertContains('Dashboard noise', sdk_config::ignore_errors());
    }

    /**
     * The curated ignore list is kept when the dashboard supplies its own.
     */
    public function test_ignore_errors_merges_defaults_with_dashboard_list(): void {
        $this->resetAfterTest();

        sdk_config::store_payload($this->payload(['ignored_errors' => ['Dashboard noise']]));

        $ignored = sdk_config::ignore_errors();
        foreach (sdk_config::DEFAULT_IGNORE_ERRORS as $default) {
            $this->assertContains($default, $ignored);
        }
        $this->assertContains('Dashboard noise', $ignored);
        $this->assertSame(array_values(array_unique($ignored)), $ignored, 'The merged list must not repeat entries.');
    }

    /**
     * A null key never clears the key the plugin is already holding.
     *
     * The backend answers key:null with key_status:'exists' on every fetch
     * after the first, so treating null as "clear it" would revoke the site's
     * monitoring on its second refresh.
     */
    public function test_null_key_in_payload_keeps_the_stored_key(): void {
        $this->resetAfterTest();

        sdk_config::store_payload($this->payload());
        $original = sdk_config::key();
        $this->assertNotSame('', $original);

        sdk_config::store_payload($this->payload(['key' => null, 'key_status' => 'exists']));

        $this->assertSame($original, sdk_config::key());
    }

    /**
     * A successful payload clears both the error and the unsupported-backend flag.
     */
    public function test_successful_payload_clears_error_state(): void {
        $this->resetAfterTest();

        sdk_config::record_backend_unsupported();
        sdk_config::record_refresh_error('previous failure');

        sdk_config::store_payload($this->payload());

        $this->assertFalse(sdk_config::backend_unsupported());
        $this->assertSame('', sdk_config::refresh_error());
        $this->assertGreaterThan(0, sdk_config::refreshed_at());
    }

    /**
     * F1: a hostile backend message is escaped where it enters storage.
     *
     * The message is chosen by the GuardLMS backend and every consumer renders
     * it as HTML without escaping - html_writer::div() concatenates, redirect()
     * prints with triple braces, and get_string() substitutes {$a} with a plain
     * str_replace. Escaping on store is what closes all three at once.
     *
     * This fires with the feature switched OFF, on a site that never enabled
     * it, which is why it is not covered by trusting the backend for the script
     * URL: that requires opt-in plus a key, this requires neither.
     */
    public function test_a_hostile_refresh_error_is_escaped_on_store(): void {
        $this->resetAfterTest();

        $hostile = '<img src=x onerror=alert(1)>';
        sdk_config::record_refresh_error($hostile);

        $stored = sdk_config::refresh_error();

        // Assert the security property - no raw angle bracket survives, so
        // nothing can open a tag - rather than the absence of the payload's
        // wording. s() escapes & < > " ' and NOT '=', so 'onerror=' is still
        // present as inert text; asserting its absence would test this
        // fixture's phrasing and fail on correctly escaped output.
        $this->assertStringNotContainsString('<', $stored, 'No raw angle bracket may survive.');
        $this->assertStringNotContainsString('>', $stored);
        $this->assertSame(s($hostile), $stored);

        // Escaped, not stripped: the admin can still read what the backend
        // actually said. This is what separates escaping from sanitising.
        $this->assertSame($hostile, html_entity_decode($stored, ENT_QUOTES | ENT_HTML401));
    }

    /**
     * F1: the same escaping holds through the status chain that renders it.
     */
    public function test_a_hostile_refresh_error_stays_escaped_through_status(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->version = sdk_config::HOOKS_API_VERSION;

        sdk_config::record_refresh_error('</div><script>alert(1)</script>');

        $status = sdk_config::status();

        $this->assertSame(7, $status['row']);
        $this->assertStringNotContainsString('<script', (string) $status['headlinedata']);
        $this->assertStringNotContainsString('</div>', (string) $status['headlinedata']);
    }

    /**
     * F1: backend-supplied domain names are escaped in the mismatch advisory.
     */
    public function test_hostile_allowed_domains_are_escaped_in_the_advisory(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->version = sdk_config::HOOKS_API_VERSION;

        sdk_config::store_payload($this->payload([
            'allowed_domains' => ['<script>alert(1)</script>', 'ok.example.com'],
            'allowed_domains_match' => false,
        ]));

        $advisories = array_column(sdk_config::status()['advisories'], 'data', 'key');
        $this->assertArrayHasKey('sdk:domainmismatch', $advisories);

        $allowed = $advisories['sdk:domainmismatch']->allowed;
        $this->assertStringNotContainsString('<script', $allowed);
        $this->assertStringContainsString('ok.example.com', $allowed, 'Benign hosts must still be readable.');
    }

    /**
     * F2: a backend that stops offering the feature must not strip the toggle.
     *
     * record_backend_unsupported() leaves the stored payload alone, so the SDK
     * keeps injecting from it. Hiding the section - which is right when nothing
     * is being injected - would leave third-party JavaScript loading on every
     * page with no control left to stop it.
     */
    public function test_row2_keeps_the_controls_when_monitoring_is_on(): void {
        $this->resetAfterTest();

        $this->set_up_healthy();
        $this->assertTrue(sdk_config::is_enabled());
        $this->assertTrue(sdk_config::injection_allowed());

        sdk_config::record_backend_unsupported();

        $status = sdk_config::status();

        $this->assertFalse($status['hidden'], 'Hiding this would remove the only way to switch injection off.');
        $this->assertSame(2, $status['row']);
        $this->assertSame('sdk:backendunsupportedactive', $status['headline']);
        $this->assertFalse($status['toggledisabled'], 'The admin must be able to untick it.');

        // Injection continues deliberately: one failed refresh must not
        // silently kill a working install.
        $this->assertTrue(sdk_config::injection_allowed());
    }

    /**
     * F2: with monitoring off, row 2 still hides the section entirely.
     */
    public function test_row2_still_hides_the_section_when_monitoring_is_off(): void {
        $this->resetAfterTest();

        $this->set_up_healthy();
        set_config('sdkenabled', 0, 'local_guardlms');
        sdk_config::record_backend_unsupported();

        $status = sdk_config::status();

        $this->assertTrue($status['hidden'], 'Nothing is injecting and nothing is actionable.');
        $this->assertSame(2, $status['row']);
        $this->assertSame('', $status['headline']);
        $this->assertFalse(sdk_config::injection_allowed());
    }

    /**
     * F2: turning the toggle off in that state actually stops the injection.
     */
    public function test_the_admin_can_switch_injection_off_after_a_404(): void {
        $this->resetAfterTest();

        $this->set_up_healthy();
        sdk_config::record_backend_unsupported();
        $this->assertTrue(sdk_config::injection_allowed());

        // What the rendered, still-present checkbox does when unticked.
        set_config('sdkenabled', 0, 'local_guardlms');

        $this->assertFalse(sdk_config::injection_allowed(), 'Unticking must stop the script loading.');
    }

    /**
     * F4: a never-connected site is told to connect, not to use a missing link.
     *
     * settings.php only renders Refresh now for a connected site, so row 1's
     * usual sentence points at a control that is not on the page.
     */
    public function test_row1_tells_a_disconnected_site_to_connect(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->version = sdk_config::HOOKS_API_VERSION;

        unset_config('apikey', 'local_guardlms');
        unset_config('connectedat', 'local_guardlms');

        $status = sdk_config::status();

        $this->assertSame(1, $status['row']);
        $this->assertSame('sdk:statusnotconnected', $status['headline']);

        // And a connected site still gets the Refresh now wording.
        set_config('apikey', 'push-key', 'local_guardlms');
        set_config('connectedat', time(), 'local_guardlms');

        $this->assertSame('sdk:statusnokey', sdk_config::status()['headline']);
    }

    /**
     * A failed refresh leaves the last good payload in place.
     */
    public function test_refresh_error_does_not_disturb_the_stored_payload(): void {
        $this->resetAfterTest();

        sdk_config::store_payload($this->payload());
        $key = sdk_config::key();
        $refreshedat = sdk_config::refreshed_at();

        sdk_config::record_refresh_error('connection refused');

        $this->assertSame($key, sdk_config::key());
        $this->assertSame($refreshedat, sdk_config::refreshed_at());
        $this->assertSame('connection refused', sdk_config::refresh_error());
    }

    /**
     * ADD-1: clear() removes every sdk* key, including any not in the inventory.
     *
     * Asserted against the config table rather than against the inventory list,
     * because a key written by some code path but missing from the inventory is
     * exactly the failure ADD-1 describes - a name with a writer and no
     * corresponding clear. Comparing the inventory to itself could never catch
     * that.
     */
    public function test_clear_removes_every_sdk_config_row(): void {
        global $DB;

        $this->resetAfterTest();

        $this->set_up_healthy();
        sdk_config::record_refresh_error('some failure');
        sdk_config::note_bootstrap_attempt();
        sdk_config::record_backend_unsupported();

        // Guard the guard: the rows have to exist before clear() can prove
        // anything by removing them.
        $before = $DB->count_records_select(
            'config_plugins',
            "plugin = 'local_guardlms' AND " . $DB->sql_like('name', ':pattern'),
            ['pattern' => 'sdk%']
        );
        $this->assertGreaterThan(0, $before);

        sdk_config::clear();

        $remaining = $DB->get_fieldset_select(
            'config_plugins',
            'name',
            "plugin = 'local_guardlms' AND " . $DB->sql_like('name', ':pattern'),
            ['pattern' => 'sdk%']
        );

        $this->assertSame([], $remaining, 'These sdk* keys are written but never cleared: ' . implode(', ', $remaining));
    }

    /**
     * ADD-1: the key inventory names each key once.
     */
    public function test_key_inventory_has_no_duplicates(): void {
        $keys = sdk_config::all_keys();

        $this->assertSame(array_values(array_unique($keys)), $keys, 'One name per key: the inventory repeats a key.');
        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertStringStartsWith('sdk', $key, 'Every owned key is sdk-prefixed, which is what makes clear() total.');
        }
    }

    /**
     * Non-connection settings survive a clear.
     *
     * clear() runs on disconnect, and wiping baseurl or pushpath would mean a
     * self-hosted site had to reconfigure its advanced settings to reconnect.
     */
    public function test_clear_leaves_non_sdk_settings_alone(): void {
        $this->resetAfterTest();

        set_config('baseurl', 'https://guardlms.example.com', 'local_guardlms');
        set_config('enabled', 1, 'local_guardlms');
        $this->set_up_healthy();

        sdk_config::clear();

        $this->assertSame('https://guardlms.example.com', get_config('local_guardlms', 'baseurl'));
        $this->assertSame('1', get_config('local_guardlms', 'enabled'));
    }

    /**
     * PLUGIN_RELEASE is what version.php says, so appVersion cannot drift.
     */
    public function test_plugin_release_matches_version_php(): void {
        global $CFG;

        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/guardlms/version.php');

        $this->assertSame($plugin->release, sdk_config::PLUGIN_RELEASE);
        $this->assertSame(2026082100, $plugin->version);
        $this->assertSame(2020061500, $plugin->requires, 'Bumping requires would drop live Moodle 4.0-4.3 installs.');
    }

    /**
     * appVersion carries the platform prefix the backend alert matches on.
     */
    public function test_app_version_format(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->release = '4.5.2+ (Build: 20250109)';

        $this->assertSame('moodle-4.5.2/local_guardlms-' . sdk_config::PLUGIN_RELEASE, sdk_config::app_version());
        $this->assertMatchesRegularExpression('/^moodle-/', sdk_config::app_version());
    }

    /**
     * An unparsable core release still yields a matchable appVersion.
     */
    public function test_app_version_survives_an_odd_core_release(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->release = 'dev-main';

        $this->assertMatchesRegularExpression('/^moodle-/', sdk_config::app_version());
    }

    /**
     * Row 8 is detected from the core version, not from the plugin's requires.
     */
    public function test_moodle_below_44_is_detected(): void {
        global $CFG;

        $this->resetAfterTest();

        $CFG->version = 2023100900;
        $this->assertFalse(sdk_config::moodle_supports_injection());

        $CFG->version = sdk_config::HOOKS_API_VERSION;
        $this->assertTrue(sdk_config::moodle_supports_injection());
    }

    /**
     * The bootstrap runs once, then the throttle holds it off.
     */
    public function test_bootstrap_is_throttled(): void {
        $this->resetAfterTest();

        $this->assertTrue(sdk_config::should_bootstrap(), 'A site that never fetched must bootstrap.');

        sdk_config::note_bootstrap_attempt();
        $this->assertFalse(sdk_config::should_bootstrap(), 'A second render inside the window must not refetch.');

        // Move the attempt outside the window.
        set_config('sdkbootstrapattempt', time() - sdk_config::BOOTSTRAP_THROTTLE - 1, 'local_guardlms');
        $this->assertTrue(sdk_config::should_bootstrap());
    }

    /**
     * A site with a payload, or a backend that cannot serve one, never bootstraps.
     */
    public function test_bootstrap_stops_once_it_is_pointless(): void {
        $this->resetAfterTest();

        sdk_config::store_payload($this->payload());
        $this->assertFalse(sdk_config::should_bootstrap(), 'A stored payload means there is nothing to bootstrap.');

        sdk_config::clear();
        sdk_config::record_backend_unsupported();
        $this->assertFalse(sdk_config::should_bootstrap(), 'An old backend cannot answer, so do not keep asking.');
    }

    /**
     * §5.3 row 2 hides the whole section and says nothing.
     *
     * Asserted while rows 1 and 7 are also true, which is UX2's requirement:
     * row 2 has the highest precedence in the chain.
     */
    public function test_status_row2_backend_unsupported_wins_over_everything(): void {
        $this->resetAfterTest();

        sdk_config::record_refresh_error('a failure');
        sdk_config::record_backend_unsupported();

        $status = sdk_config::status();

        $this->assertTrue($status['hidden']);
        $this->assertSame(2, $status['row']);
        $this->assertSame('', $status['headline'], 'Row 2 renders no error at all.');
    }

    /**
     * §5.3 row 8 beats every row except 2.
     */
    public function test_status_row8_requires44_beats_rows_5_4_7_and_1(): void {
        global $CFG;

        $this->resetAfterTest();

        $CFG->version = 2023100900;
        sdk_config::store_payload($this->payload([
            'enabled' => false,
            'subscription_active' => false,
        ]));
        sdk_config::record_refresh_error('a failure');

        $status = sdk_config::status();

        $this->assertSame(8, $status['row']);
        $this->assertSame('sdk:requires44', $status['headline']);
        $this->assertTrue($status['toggledisabled']);
        $this->assertFalse($status['hidden']);
    }

    /**
     * §5.3 row 5 beats rows 4, 7 and 1.
     */
    public function test_status_row5_dashboard_off_beats_rows_4_7_and_1(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->version = sdk_config::HOOKS_API_VERSION;

        sdk_config::store_payload($this->payload([
            'enabled' => false,
            'subscription_active' => false,
            'key' => null,
        ]));
        sdk_config::record_refresh_error('a failure');

        $status = sdk_config::status();

        $this->assertSame(5, $status['row']);
        $this->assertSame('sdk:statusdashboardoff', $status['headline']);
    }

    /**
     * §5.3 row 4 beats rows 7 and 1.
     */
    public function test_status_row4_no_subscription_beats_rows_7_and_1(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->version = sdk_config::HOOKS_API_VERSION;

        sdk_config::store_payload($this->payload([
            'subscription_active' => false,
            'key' => null,
        ]));
        sdk_config::record_refresh_error('a failure');

        $status = sdk_config::status();

        $this->assertSame(4, $status['row']);
        $this->assertSame('sdk:statusnosubscription', $status['headline']);
    }

    /**
     * §5.3 row 7 beats row 1, which is UX7's precedence requirement.
     */
    public function test_status_row7_refresh_error_beats_row1(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->version = sdk_config::HOOKS_API_VERSION;

        // No key and a failed refresh: rows 1 and 7 are both true.
        sdk_config::record_refresh_error('connection refused');

        $status = sdk_config::status();

        $this->assertSame(7, $status['row']);
        $this->assertSame('sdk:statusrefresherror', $status['headline']);
        $this->assertSame('connection refused', $status['headlinedata']);
    }

    /**
     * A connected site that never refreshed reports no key, never dashboard off.
     *
     * The trap the literal precedence chain sets. Row 5 outranks row 1, and
     * both sdkbackendenabled and sdksubscriptionactive default to 0, so a site
     * that has simply never fetched would be told "Real-time monitoring is
     * turned off in the GuardLMS dashboard" - a confident diagnosis the plugin
     * has no evidence for, sending the admin to a dashboard setting that is
     * very likely fine, when the true state is "no key fetched yet".
     *
     * Before a successful refresh those flags mean *unknown*, not *off*, which
     * is why rows 3, 4, 5 and 6 are gated on sdkrefreshedat > 0. A vague right
     * answer beats a confident wrong one, which is the whole point of §5.3.
     */
    public function test_a_site_that_never_refreshed_reports_no_key_not_dashboard_off(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->version = sdk_config::HOOKS_API_VERSION;

        // A connected site that has never had a successful refresh. Nothing is
        // stored, so every backend-asserted flag reads false by default - the
        // exact condition that produces the wrong sentence.
        set_config('apikey', 'push-key-from-connect', 'local_guardlms');
        set_config('connectedat', time(), 'local_guardlms');

        $this->assertFalse(sdk_config::has_payload());
        $this->assertFalse(sdk_config::backend_enabled(), 'The trap only exists while this defaults to false.');
        $this->assertFalse(sdk_config::subscription_active());

        $status = sdk_config::status();

        $this->assertSame(1, $status['row']);
        $this->assertSame('sdk:statusnokey', $status['headline']);
        $this->assertFalse($status['hidden']);

        // The three sentences the plugin has no evidence for.
        $this->assertNotSame('sdk:statusdashboardoff', $status['headline']);
        $this->assertNotSame('sdk:statusnosubscription', $status['headline']);
        $this->assertSame([], $status['advisories'], 'Nothing is known about the plan yet, so nothing is claimed.');
    }

    /**
     * Once a payload has arrived, the same flags are believed.
     *
     * The other half of the gate: suppressing rows 4 and 5 before a refresh
     * must not suppress them afterwards, or the states they exist to report
     * would never render at all.
     */
    public function test_the_same_flags_are_believed_once_a_payload_exists(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->version = sdk_config::HOOKS_API_VERSION;

        sdk_config::store_payload($this->payload(['enabled' => false]));

        $this->assertTrue(sdk_config::has_payload());
        $this->assertSame(5, sdk_config::status()['row']);
        $this->assertSame('sdk:statusdashboardoff', sdk_config::status()['headline']);
    }

    /**
     * §5.3 row 7 must not suppress injection.
     *
     * Only rows 4 and 5 suppress. Going dark because one refresh timed out
     * would reintroduce exactly the silence this design exists to remove: the
     * site would stop reporting errors precisely when something is wrong.
     */
    public function test_a_failed_refresh_does_not_stop_injection(): void {
        $this->resetAfterTest();

        $this->set_up_healthy();
        $this->assertTrue(sdk_config::injection_allowed());

        sdk_config::record_refresh_error('connection refused');

        $this->assertSame(7, sdk_config::status()['row'], 'The admin is told the refresh failed.');
        $this->assertTrue(
            sdk_config::injection_allowed(),
            'A failed refresh must not stop collection: the last good payload is still valid.'
        );
    }

    /**
     * Rows 4 and 5 are the two that do suppress injection.
     */
    public function test_rows_4_and_5_suppress_injection(): void {
        $this->resetAfterTest();

        $this->set_up_healthy();
        set_config('sdksubscriptionactive', 0, 'local_guardlms');
        $this->assertFalse(sdk_config::injection_allowed(), 'Row 4 suppresses collection.');

        $this->set_up_healthy();
        set_config('sdkbackendenabled', 0, 'local_guardlms');
        $this->assertFalse(sdk_config::injection_allowed(), 'Row 5 suppresses collection.');
    }

    /**
     * The success path reports active once the admin has opted in.
     */
    public function test_status_success_path(): void {
        $this->resetAfterTest();

        $this->set_up_healthy();
        $this->assertSame(0, sdk_config::status()['row']);
        $this->assertSame('sdk:statusactive', sdk_config::status()['headline']);

        set_config('sdkenabled', 0, 'local_guardlms');
        $this->assertSame('sdk:statusready', sdk_config::status()['headline']);
    }

    /**
     * §5.3 row 3 is an advisory: it renders alongside the chosen headline.
     */
    public function test_status_row3_analytics_advisory_is_not_exclusive(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->version = sdk_config::HOOKS_API_VERSION;

        sdk_config::store_payload($this->payload([
            'analytics_allowed' => false,
            'subscription_active' => false,
        ]));

        $status = sdk_config::status();

        $this->assertSame(4, $status['row'], 'The headline still comes from the chain.');
        $this->assertTrue($status['analyticsdisabled']);
        $this->assertContains('sdk:analyticsnotinplan', array_column($status['advisories'], 'key'));
    }

    /**
     * §5.3 row 6 is an advisory naming both hosts.
     */
    public function test_status_row6_domain_mismatch_advisory_names_both_hosts(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->version = sdk_config::HOOKS_API_VERSION;

        sdk_config::store_payload($this->payload([
            'allowed_domains' => ['example.com'],
            'allowed_domains_match' => false,
        ]));

        $status = sdk_config::status();

        $this->assertSame(0, $status['row'], 'A domain mismatch is an advisory, not a headline.');

        $advisories = array_column($status['advisories'], 'data', 'key');
        $this->assertArrayHasKey('sdk:domainmismatch', $advisories);
        $this->assertSame('example.com', $advisories['sdk:domainmismatch']->allowed);
        $this->assertSame(sdk_config::site_host(), $advisories['sdk:domainmismatch']->actual);
        $this->assertNotSame('', $advisories['sdk:domainmismatch']->actual);
    }

    /**
     * An empty allow list is a match, so an unfetched site is never "mismatched".
     */
    public function test_allowed_domains_match_defaults_to_true(): void {
        $this->resetAfterTest();

        unset_config('sdkalloweddomainsmatch', 'local_guardlms');

        $this->assertTrue(sdk_config::allowed_domains_match());
    }

    /**
     * UX7: the last-success line is a sentence before the first refresh, never an epoch.
     */
    public function test_last_refresh_text_never_renders_an_epoch(): void {
        $this->resetAfterTest();

        unset_config('sdkrefreshedat', 'local_guardlms');

        $never = sdk_config::last_refresh_text();
        $this->assertSame(get_string('sdk:norefreshyet', 'local_guardlms'), $never);
        $this->assertNotSame('', $never);
        $this->assertStringNotContainsString('1970', $never, 'The epoch must never leak through as a date.');

        sdk_config::store_payload($this->payload());
        $this->assertStringContainsString(userdate(sdk_config::refreshed_at()), sdk_config::last_refresh_text());
    }

    /**
     * Injection needs every precondition, not merely the admin toggle.
     */
    public function test_injection_allowed_requires_all_preconditions(): void {
        $this->resetAfterTest();

        $this->set_up_healthy();
        $this->assertTrue(sdk_config::injection_allowed());

        foreach (['sdkenabled', 'sdkbackendenabled', 'sdksubscriptionactive'] as $key) {
            $this->set_up_healthy();
            set_config($key, 0, 'local_guardlms');
            $this->assertFalse(sdk_config::injection_allowed(), "Injection must stop when {$key} is off.");
        }

        foreach (['sdkkey', 'sdkurl'] as $key) {
            $this->set_up_healthy();
            set_config($key, '', 'local_guardlms');
            $this->assertFalse(sdk_config::injection_allowed(), "Injection must stop when {$key} is empty.");
        }
    }

    /**
     * A Moodle that ignores db/hooks.php never counts as injectable.
     *
     * The settings page tells these sites the toggle has no effect; this is
     * what keeps that promise true no matter which caller asks.
     */
    public function test_injection_is_refused_below_moodle_44(): void {
        global $CFG;

        $this->resetAfterTest();

        $this->set_up_healthy();
        $this->assertTrue(sdk_config::injection_allowed());

        $CFG->version = 2023100900;
        $this->assertFalse(sdk_config::injection_allowed());
    }

    /**
     * Analytics needs the plan entitlement and the admin's opt-in.
     */
    public function test_analytics_active_needs_both_the_plan_and_the_opt_in(): void {
        $this->resetAfterTest();

        $this->set_up_healthy();
        $this->assertTrue(sdk_config::analytics_active());

        set_config('sdkanalytics', 0, 'local_guardlms');
        $this->assertFalse(sdk_config::analytics_active(), 'The admin has not opted in.');

        set_config('sdkanalytics', 1, 'local_guardlms');
        set_config('sdkanalyticsallowed', 0, 'local_guardlms');
        $this->assertFalse(sdk_config::analytics_active(), 'The plan does not include analytics.');
    }
}
