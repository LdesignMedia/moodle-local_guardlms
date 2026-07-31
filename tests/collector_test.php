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

use local_guardlms\local\collector;

/**
 * Tests for the reporting payload collector.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_guardlms\local\collector
 */
final class collector_test extends \advanced_testcase {
    /**
     * The built payload reports the configured site URL override as its siteurl.
     */
    public function test_build_payload_reports_override_siteurl(): void {
        $this->resetAfterTest();

        set_config('siteurloverride', 'https://public.example.com', 'local_guardlms');

        $payload = collector::build_payload();

        $this->assertSame('https://public.example.com', $payload['siteurl']);
        $this->assertSame('moodle', $payload['platform']);
    }

    /**
     * Without an override the payload reports this site's wwwroot.
     */
    public function test_build_payload_defaults_to_wwwroot(): void {
        global $CFG;
        $this->resetAfterTest();

        $payload = collector::build_payload();

        $this->assertSame(rtrim($CFG->wwwroot, '/'), $payload['siteurl']);
    }

    /**
     * With update notifications switched off site-wide, no plugin may claim to
     * have been checked: the 'updates' key is absent everywhere and the state
     * block says why.
     */
    public function test_update_check_disabled_omits_plugin_updates(): void {
        global $CFG;
        $this->resetAfterTest();

        $CFG->disableupdatenotifications = 1;

        $payload = collector::build_payload();
        $updatecheck = $payload['moodle']['updatecheck'];

        $this->assertFalse($updatecheck['enabled']);
        $this->assertTrue($updatecheck['stale']);
        $this->assertNull($updatecheck['lastfetched']);
        $this->assertNull($updatecheck['fetcherror']);
        $this->assertArrayNotHasKey('coreupdates', $payload['moodle']);

        $this->assertNotEmpty($payload['moodle']['plugins']);
        foreach ($payload['moodle']['plugins'] as $plugin) {
            $this->assertArrayNotHasKey('updates', $plugin, $plugin['component'] . ' must not report updates');
        }
    }

    /**
     * A cached response older than the freshness window is not reported either,
     * even though update notifications are enabled. Without a refresh (this is
     * the web-request path) months-old data must read as unknown, not as "no
     * updates available".
     */
    public function test_stale_cached_response_omits_plugin_updates(): void {
        $this->resetAfterTest();

        $this->seed_update_response(time() - (48 * 60 * 60), []);

        $payload = collector::build_payload();
        $updatecheck = $payload['moodle']['updatecheck'];

        $this->assertTrue($updatecheck['enabled']);
        $this->assertTrue($updatecheck['stale']);
        $this->assertArrayNotHasKey('coreupdates', $payload['moodle']);

        foreach ($payload['moodle']['plugins'] as $plugin) {
            $this->assertArrayNotHasKey('updates', $plugin);
        }
    }

    /**
     * With a fresh cached response every reported plugin carries an 'updates'
     * key. Plugins with nothing waiting carry an empty list — that is the
     * signal that says "checked, and current", as opposed to the key being
     * absent.
     */
    public function test_fresh_cached_response_reports_updates_for_every_plugin(): void {
        $this->resetAfterTest();

        $this->seed_update_response(time(), [
            'local_guardlms' => [
                [
                    'version' => 9999999999,
                    'release' => '99.9',
                    'maturity' => MATURITY_STABLE,
                    'url' => 'https://moodle.org/plugins/local_guardlms',
                ],
            ],
        ]);

        $payload = collector::build_payload();

        $this->assertTrue($payload['moodle']['updatecheck']['enabled']);
        $this->assertFalse($payload['moodle']['updatecheck']['stale']);
        $this->assertIsArray($payload['moodle']['coreupdates']);

        $bycomponent = [];
        foreach ($payload['moodle']['plugins'] as $plugin) {
            $this->assertArrayHasKey('updates', $plugin, $plugin['component'] . ' must report a checked state');
            $bycomponent[$plugin['component']] = $plugin;
        }

        $this->assertArrayHasKey('local_guardlms', $bycomponent);
        $this->assertSame([
            [
                'version' => '9999999999',
                'release' => '99.9',
                'maturity' => MATURITY_STABLE,
                'url' => 'https://moodle.org/plugins/local_guardlms',
            ],
        ], $bycomponent['local_guardlms']['updates']);

        // A plugin the response says nothing about is checked and current.
        $othercomponent = null;
        foreach ($bycomponent as $component => $plugin) {
            if ($component !== 'local_guardlms') {
                $othercomponent = $plugin;
                break;
            }
        }
        $this->assertNotNull($othercomponent);
        $this->assertSame([], $othercomponent['updates']);
    }

    /**
     * Every plugin reports the version on disk next to the version the database
     * is upgraded to, so a pending upgrade is not mistaken for being behind.
     */
    public function test_plugins_report_the_version_on_disk(): void {
        $this->resetAfterTest();

        $payload = collector::build_payload();

        foreach ($payload['moodle']['plugins'] as $plugin) {
            $this->assertArrayHasKey('versiondisk', $plugin);
        }
    }

    /**
     * Seed the cached update-check response Moodle keeps in config_plugins.
     *
     * This is the same storage \core\update\checker::store_response() writes to,
     * which lets a test exercise the checker without any network access.
     *
     * @param int $fetchedat Timestamp to record as the moment of the fetch.
     * @param array $updates Update lists keyed by frankenstyle component.
     */
    private function seed_update_response(int $fetchedat, array $updates): void {
        $response = [
            'status' => 'OK',
            'provider' => 'https://download.moodle.org/api/1.3/updates.php',
            'apiver' => '1.3',
            'timegenerated' => $fetchedat,
            'forbranch' => moodle_major_version(true),
            'ticket' => null,
            'updates' => $updates,
        ];

        set_config('recentfetch', $fetchedat, 'core_plugin');
        set_config('recentresponse', json_encode($response), 'core_plugin');

        // The checker and the plugin manager are singletons that cache the
        // decoded response, so both must forget what they read before this.
        \core\update\checker::reset_caches(true);
        \core_plugin_manager::reset_caches(true);
    }
}
