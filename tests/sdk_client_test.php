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

use local_guardlms\local\connect_manager;
use local_guardlms\local\sdk_client;
use local_guardlms\local\sdk_config;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/guardlms/tests/fixtures/testable_sdk_client.php');

/**
 * Tests for the SDK key client and the disconnect teardown ordering.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_guardlms\local\sdk_client
 */
final class sdk_client_test extends \advanced_testcase {
    /** @var string The push key a connected site holds. */
    private const PUSH_KEY = 'push-key-from-connect';

    /**
     * Put the site into the connected state.
     */
    private function set_up_connected(): void {
        set_config('apikey', self::PUSH_KEY, 'local_guardlms');
        set_config('connectedat', time(), 'local_guardlms');
    }

    /**
     * A body shaped like a successful backend response.
     *
     * @return string
     */
    private function success_body(): string {
        return json_encode(['message' => 'ok', 'data' => [
            'key' => 'glms_' . str_repeat('b', 56),
            'key_status' => 'issued',
            'key_prefix' => 'glms_bbb',
            'sdk_url' => 'https://app.guardlms.com/sdk/guardlms.min.js?v=deadbeef1234',
            'errors_endpoint' => 'https://app.guardlms.com/api/sdk/errors/collect',
            'analytics_endpoint' => 'https://app.guardlms.com/api/sdk/analytics/collect',
            'enabled' => true,
            'subscription_active' => true,
            'analytics_allowed' => false,
            'sample_rate' => 1.0,
            'analytics_sample_rate' => 1.0,
            'max_breadcrumbs' => 50,
            'max_errors_per_minute' => 60,
            'ignored_errors' => [],
            'allowed_domains' => [],
            'allowed_domains_match' => true,
        ]]);
    }

    /**
     * A successful fetch stores the payload and authenticates with the push key.
     */
    public function test_successful_fetch_stores_the_payload(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->set_up_connected();

        $client = new testable_sdk_client(200, $this->success_body());

        $this->assertTrue(sdk_client::resolve('fetch', 5, $client));

        $this->assertCount(1, $client->calls);
        $this->assertSame('fetch', $client->calls[0]['action']);
        $this->assertSame(self::PUSH_KEY, $client->calls[0]['apikey']);
        $this->assertSame($CFG->wwwroot, $client->calls[0]['siteurl']);
        $this->assertSame(5, $client->calls[0]['timeout']);

        $this->assertSame('glms_' . str_repeat('b', 56), sdk_config::key());
        $this->assertTrue(sdk_config::backend_enabled());
        $this->assertFalse(sdk_config::analytics_allowed());
        $this->assertGreaterThan(0, sdk_config::refreshed_at());
        $this->assertSame('', sdk_config::refresh_error());
    }

    /**
     * A disconnected site makes no request and records no failure.
     *
     * Being disconnected is not a refresh failure, and reporting it as one
     * would put an error on the settings page of every site that has simply
     * never connected.
     */
    public function test_a_disconnected_site_does_not_call_out(): void {
        $this->resetAfterTest();

        unset_config('apikey', 'local_guardlms');
        $client = new testable_sdk_client(200, $this->success_body());

        $this->assertFalse(sdk_client::resolve('fetch', 5, $client));
        $this->assertSame([], $client->calls);
        $this->assertSame('', sdk_config::refresh_error());
    }

    /**
     * §5.3 row 2: a 404 or 405 is a quiet no-op, not an error.
     */
    public function test_404_and_405_mark_the_backend_unsupported_without_an_error(): void {
        $this->resetAfterTest();

        foreach ([404, 405] as $status) {
            // Between iterations, or the second one inherits the first's verdict
            // and proves nothing.
            sdk_config::clear();
            $this->set_up_connected();

            $client = new testable_sdk_client($status, 'Not Found');

            $this->assertFalse(sdk_client::resolve('fetch', 5, $client));
            $this->assertTrue(sdk_config::backend_unsupported(), "HTTP {$status} must set the unsupported flag.");
            $this->assertSame('', sdk_config::refresh_error(), 'An old backend is not something the admin can fix.');
            $this->assertTrue(sdk_config::status()['hidden']);
        }
    }

    /**
     * §5.3 row 7: a rejected request surfaces the backend's own message.
     */
    public function test_a_rejected_request_records_the_backend_message(): void {
        $this->resetAfterTest();
        $this->set_up_connected();

        $client = new testable_sdk_client(422, json_encode(['message' => 'The site URL does not match.']));

        $this->assertFalse(sdk_client::resolve('fetch', 5, $client));
        $this->assertStringContainsString('The site URL does not match.', sdk_config::refresh_error());
        $this->assertFalse(sdk_config::backend_unsupported());
        $this->assertSame(7, sdk_config::status()['row']);
    }

    /**
     * A response with no message still yields a usable sentence.
     */
    public function test_a_rejected_request_without_a_message_still_reports_something(): void {
        $this->resetAfterTest();
        $this->set_up_connected();

        $client = new testable_sdk_client(500, '<html>Gateway exploded</html>');

        $this->assertFalse(sdk_client::resolve('fetch', 5, $client));
        $this->assertStringContainsString('500', sdk_config::refresh_error());
    }

    /**
     * §5.3 row 7: a transport failure is recorded rather than thrown.
     */
    public function test_a_transport_failure_is_recorded_not_thrown(): void {
        $this->resetAfterTest();
        $this->set_up_connected();

        $client = new testable_sdk_client(200, '', true);

        $this->assertFalse(sdk_client::resolve('fetch', 5, $client));
        $this->assertNotSame('', sdk_config::refresh_error());
        $this->assertSame(7, sdk_config::status()['row']);
    }

    /**
     * A 200 with a body that is not the expected shape is a failure, not a store.
     */
    public function test_a_malformed_success_body_does_not_store_anything(): void {
        $this->resetAfterTest();
        $this->set_up_connected();

        $client = new testable_sdk_client(200, 'not json at all');

        $this->assertFalse(sdk_client::resolve('fetch', 5, $client));
        $this->assertSame('', sdk_config::key());
        $this->assertNotSame('', sdk_config::refresh_error());
    }

    /**
     * A successful refresh clears a previously recorded failure.
     */
    public function test_a_successful_refresh_clears_an_earlier_failure(): void {
        $this->resetAfterTest();
        $this->set_up_connected();

        $this->assertFalse(sdk_client::resolve('fetch', 5, new testable_sdk_client(200, '', true)));
        $this->assertNotSame('', sdk_config::refresh_error());

        $this->assertTrue(sdk_client::resolve('fetch', 5, new testable_sdk_client(200, $this->success_body())));
        $this->assertSame('', sdk_config::refresh_error());
        $this->assertSame(0, sdk_config::status()['row']);
    }

    /**
     * A backend that has since been upgraded stops being reported as unsupported.
     */
    public function test_an_upgraded_backend_clears_the_unsupported_flag(): void {
        $this->resetAfterTest();
        $this->set_up_connected();

        $this->assertFalse(sdk_client::resolve('fetch', 5, new testable_sdk_client(404, '')));
        $this->assertTrue(sdk_config::backend_unsupported());

        $this->assertTrue(sdk_client::resolve('fetch', 5, new testable_sdk_client(200, $this->success_body())));
        $this->assertFalse(sdk_config::backend_unsupported());
        $this->assertFalse(sdk_config::status()['hidden']);
    }

    /**
     * E9: disconnect revokes before the push key is dropped.
     *
     * Asserted through the credential the revoke call carried. If the key were
     * cleared first, resolve() would return early with no request at all, so a
     * recorded call holding the push key is proof of the ordering.
     */
    public function test_disconnect_revokes_before_clearing_the_push_key(): void {
        $this->resetAfterTest();
        $this->set_up_connected();
        sdk_config::store_payload(json_decode($this->success_body(), true)['data']);

        $client = new testable_sdk_client(200, json_encode(['data' => ['key_status' => 'revoked']]));

        connect_manager::disconnect($client);

        $this->assertCount(1, $client->calls, 'Disconnect must attempt a revoke.');
        $this->assertSame('revoke', $client->calls[0]['action']);
        $this->assertSame(
            self::PUSH_KEY,
            $client->calls[0]['apikey'],
            'The revoke must run while the push key is still held.'
        );
        $this->assertSame('', (string) get_config('local_guardlms', 'apikey'));
    }

    /**
     * E9: disconnect clears every sdk key.
     */
    public function test_disconnect_clears_every_sdk_key(): void {
        global $DB;

        $this->resetAfterTest();
        $this->set_up_connected();
        sdk_config::store_payload(json_decode($this->success_body(), true)['data']);
        set_config('sdkenabled', 1, 'local_guardlms');
        set_config('sdkanalytics', 1, 'local_guardlms');

        connect_manager::disconnect(new testable_sdk_client(200, json_encode(['data' => []])));

        $remaining = $DB->get_fieldset_select(
            'config_plugins',
            'name',
            "plugin = 'local_guardlms' AND " . $DB->sql_like('name', ':pattern'),
            ['pattern' => 'sdk%']
        );

        $this->assertSame([], $remaining, 'Left behind after disconnect: ' . implode(', ', $remaining));
        $this->assertFalse(connect_manager::is_connected());
    }

    /**
     * E9: disconnect completes even when the revoke call fails.
     *
     * An admin who clicked Disconnect gets a disconnected site either way; a
     * GuardLMS that cannot be reached must not leave the site half connected.
     */
    public function test_disconnect_completes_when_the_revoke_fails(): void {
        $this->resetAfterTest();
        $this->set_up_connected();
        sdk_config::store_payload(json_decode($this->success_body(), true)['data']);

        connect_manager::disconnect(new testable_sdk_client(200, '', true));

        $this->assertFalse(connect_manager::is_connected());
        $this->assertSame('', sdk_config::key());
        $this->assertSame('', (string) get_config('local_guardlms', 'apikey'));
    }

    /**
     * A revoke stores no payload: the connection is being torn down.
     */
    public function test_revoke_does_not_store_a_payload(): void {
        $this->resetAfterTest();
        $this->set_up_connected();

        $this->assertTrue(sdk_client::resolve('revoke', 5, new testable_sdk_client(200, $this->success_body())));

        $this->assertSame('', sdk_config::key(), 'A revoke response must never re-store a key.');
    }
}
