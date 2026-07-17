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
        $client = $this->getMockBuilder(api_client::class)
            ->setConstructorArgs(['https://app.guardlms.example'])
            ->onlyMethods(['exchange'])
            ->getMock();
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

        // The initial push has been queued.
        $tasks = \core\task\manager::get_adhoc_tasks(\local_guardlms\task\initial_push::class);
        $this->assertCount(1, $tasks);

        $this->assertTrue(connect_manager::is_connected());
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
