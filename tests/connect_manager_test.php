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

use local_guardlms\local\api_client;
use local_guardlms\local\connect_manager;

/**
 * Tests for the keyless connect flow orchestration.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_guardlms\local\connect_manager
 */
final class connect_manager_test extends \advanced_testcase {
    /**
     * Build a stub api_client returning a fixed exchange payload.
     *
     * @param array $data Exchange response data.
     * @return api_client
     */
    protected function stub_client(array $data): api_client {
        $builder = $this->getMockBuilder(api_client::class)
            ->setConstructorArgs(['https://app.guardlms.example']);
        // PHPUnit 8.3 introduced onlyMethods(); Moodle 3.9 ships PHPUnit 7.5.
        if (method_exists($builder, 'onlyMethods')) {
            $builder->onlyMethods(['exchange']);
        } else {
            $builder->setMethods(['exchange']);
        }
        $client = $builder->getMock();
        $client->method('exchange')->willReturn($data);

        return $client;
    }

    /**
     * start_connect stores a state and builds the consent URL.
     */
    public function test_start_connect_builds_consent_url(): void {
        global $CFG;
        $this->resetAfterTest();

        set_config('baseurl', 'https://app.guardlms.example/', 'local_guardlms');

        $manager = new connect_manager();
        $url = $manager->start_connect();

        $state = get_config('local_guardlms', 'connectstate');
        $this->assertNotEmpty($state);
        $this->assertGreaterThan(time(), (int) get_config('local_guardlms', 'connectstateexpires'));

        $this->assertStringStartsWith('https://app.guardlms.example/connect/moodle', $url->out(false));
        $this->assertSame($CFG->wwwroot, $url->param('siteurl'));
        $this->assertSame($state, $url->param('state'));
        $this->assertSame($CFG->wwwroot . '/local/guardlms/callback.php', $url->param('callback'));
    }

    /**
     * The siteurl override is registered in place of wwwroot when set.
     */
    public function test_start_connect_uses_siteurl_override(): void {
        $this->resetAfterTest();

        set_config('baseurl', 'https://app.guardlms.example', 'local_guardlms');
        set_config('siteurloverride', 'https://public.example.com/', 'local_guardlms');

        $manager = new connect_manager();
        $url = $manager->start_connect();

        // The override is sent trailing-slash trimmed as the registered siteurl.
        $this->assertSame('https://public.example.com', $url->param('siteurl'));
    }

    /**
     * A successful callback stores the push key and connection metadata.
     */
    public function test_complete_connect_stores_configuration(): void {
        $this->resetAfterTest();

        set_config('baseurl', 'https://app.guardlms.example', 'local_guardlms');
        set_config('connectstate', 'thestate', 'local_guardlms');
        set_config('connectstateexpires', time() + 600, 'local_guardlms');

        $manager = new connect_manager($this->stub_client([
            'token' => '99|plain-push-key',
            'pushpath' => '/api/externalpush/moodle',
            'verification_token' => 'verifytoken123',
            'website_id' => 42,
            'expires_at' => '2027-07-15T00:00:00Z',
        ]));

        $manager->complete_connect(str_repeat('c', 64), 'thestate');

        $this->assertSame('99|plain-push-key', get_config('local_guardlms', 'apikey'));
        $this->assertSame('1', (string) get_config('local_guardlms', 'enabled'));
        $this->assertSame('verifytoken123', get_config('local_guardlms', 'verificationtoken'));
        $this->assertSame('42', (string) get_config('local_guardlms', 'websiteid'));
        $this->assertGreaterThan(0, (int) get_config('local_guardlms', 'connectedat'));

        // The pending state is consumed.
        $this->assertFalse(get_config('local_guardlms', 'connectstate'));

        // Connecting pushes the inventory straight away (issue #7). The push
        // cannot reach anything from a unit test, so what is asserted here is the
        // fallback: the failure is swallowed and retried through cron.
        $this->assertDebuggingCalled();
        $tasks = \core\task\manager::get_adhoc_tasks(\local_guardlms\task\initial_push::class);
        $this->assertCount(1, $tasks);

        $this->assertTrue(connect_manager::is_connected());
    }

    /**
     * Reconnecting is the recovery path the refused state points at, so a
     * successful exchange has to clear it.
     */
    public function test_complete_connect_clears_a_refused_key_state(): void {
        $this->resetAfterTest();

        set_config('baseurl', 'https://app.guardlms.example', 'local_guardlms');
        set_config('connectstate', 'thestate', 'local_guardlms');
        set_config('connectstateexpires', time() + 600, 'local_guardlms');
        set_config('authrejectedat', 1754000000, 'local_guardlms');
        connect_manager::note_site_url_mismatch(json_encode([
            'code' => 'site_url_mismatch',
            'expected_siteurl' => 'https://public.example.com',
            'received_siteurl' => 'https://internal.example.com',
        ]));

        $manager = new connect_manager($this->stub_client(['token' => '99|fresh-key']));
        $manager->complete_connect(str_repeat('c', 64), 'thestate');
        $this->assertDebuggingCalled();

        $this->assertFalse(connect_manager::is_auth_rejected());
        $this->assertNull(connect_manager::site_url_mismatch());
    }

    /**
     * A mismatch records diagnostic URLs without adopting another site's identity.
     */
    public function test_site_url_mismatch_keeps_credentials_and_explicit_override(): void {
        $this->resetAfterTest();
        set_config('apikey', '99|original-key', 'local_guardlms');
        set_config('siteurloverride', 'https://clone.example.com', 'local_guardlms');

        $mismatch = connect_manager::note_site_url_mismatch(json_encode([
            'code' => 'site_url_mismatch',
            'expected_siteurl' => 'https://production.example.com',
            'received_siteurl' => 'https://clone.example.com',
            'message' => 'Additional server text is not persisted.',
        ]));

        $this->assertSame('https://production.example.com', $mismatch['expected_siteurl']);
        $this->assertSame('https://clone.example.com', $mismatch['received_siteurl']);
        $this->assertGreaterThan(0, $mismatch['time']);
        $this->assertCount(3, $mismatch);
        $this->assertSame($mismatch, connect_manager::site_url_mismatch());
        $this->assertSame('99|original-key', get_config('local_guardlms', 'apikey'));
        $this->assertSame('https://clone.example.com', get_config('local_guardlms', 'siteurloverride'));
        $this->assertFalse(connect_manager::is_auth_rejected());
    }

    /**
     * Validation errors and malformed bodies must not invent a URL mismatch.
     */
    public function test_only_structured_site_url_mismatches_are_recorded(): void {
        $this->resetAfterTest();
        $responses = [
            'not json',
            json_encode(['message' => 'The site URL does not match.']),
            json_encode(['code' => 'other_error', 'expected_siteurl' => 'a', 'received_siteurl' => 'b']),
            json_encode(['code' => 'site_url_mismatch', 'expected_siteurl' => 'a']),
            json_encode(['code' => 'site_url_mismatch', 'expected_siteurl' => [], 'received_siteurl' => 'b']),
        ];
        foreach ($responses as $response) {
            $this->assertNull(connect_manager::note_site_url_mismatch($response));
            $this->assertNull(connect_manager::site_url_mismatch());
        }
        $this->assertFalse(get_config('local_guardlms', 'siteurloverride'));
    }

    /**
     * Only the statuses that actually mean "this key is dead" count. A backend
     * outage, a rate limit and a URL mismatch are all recoverable without a new
     * key, so none of them may prompt a reconnect.
     */
    public function test_only_refused_key_statuses_count(): void {
        $this->assertTrue(connect_manager::is_rejected_status(401));
        $this->assertTrue(connect_manager::is_rejected_status(403));
        $this->assertFalse(connect_manager::is_rejected_status(500));
        $this->assertFalse(connect_manager::is_rejected_status(429));
        $this->assertFalse(connect_manager::is_rejected_status(422));
    }

    /**
     * "Since when did this site stop reporting" is the question the settings
     * page answers, so a cron retry must not reset the clock.
     */
    public function test_the_first_refusal_timestamp_survives_later_refusals(): void {
        $this->resetAfterTest();

        connect_manager::note_auth_rejected();
        $first = connect_manager::auth_rejected_at();
        $this->assertGreaterThan(0, $first);

        connect_manager::note_auth_rejected();

        $this->assertSame($first, connect_manager::auth_rejected_at());
    }

    /**
     * An accepted push clears the refused state.
     */
    public function test_an_accepted_push_clears_the_refused_state(): void {
        $this->resetAfterTest();

        set_config('authrejectedat', 1754000000, 'local_guardlms');
        $this->assertTrue(connect_manager::is_auth_rejected());

        connect_manager::note_auth_accepted();

        $this->assertFalse(connect_manager::is_auth_rejected());
    }

    /**
     * A site with no key has nothing to reconnect a refused key for.
     */
    public function test_disconnect_clears_the_refused_state(): void {
        $this->resetAfterTest();

        set_config('apikey', '99|dead-key', 'local_guardlms');
        set_config('connectedat', 1750000000, 'local_guardlms');
        set_config('authrejectedat', 1754000000, 'local_guardlms');

        connect_manager::note_site_url_mismatch(json_encode([
            'code' => 'site_url_mismatch',
            'expected_siteurl' => 'https://public.example.com',
            'received_siteurl' => 'https://internal.example.com',
        ]));
        connect_manager::disconnect();

        $this->assertFalse(connect_manager::is_connected());
        $this->assertFalse(connect_manager::is_auth_rejected());
        $this->assertNull(connect_manager::site_url_mismatch());
    }

    /**
     * A state mismatch aborts the exchange and consumes the pending state.
     */
    public function test_complete_connect_rejects_state_mismatch(): void {
        $this->resetAfterTest();

        set_config('connectstate', 'expectedstate', 'local_guardlms');
        set_config('connectstateexpires', time() + 600, 'local_guardlms');

        $manager = new connect_manager($this->stub_client(['token' => 'never-used']));

        $this->expectException(\moodle_exception::class);
        $manager->complete_connect(str_repeat('c', 64), 'wrongstate');
    }

    /**
     * An expired state aborts the exchange.
     */
    public function test_complete_connect_rejects_expired_state(): void {
        $this->resetAfterTest();

        set_config('connectstate', 'thestate', 'local_guardlms');
        set_config('connectstateexpires', time() - 1, 'local_guardlms');

        $manager = new connect_manager($this->stub_client(['token' => 'never-used']));

        $this->expectException(\moodle_exception::class);
        $manager->complete_connect(str_repeat('c', 64), 'thestate');
    }
}
