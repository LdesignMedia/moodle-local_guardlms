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

use local_guardlms\task\push_site_info;

/**
 * Tests for the configuration gate of the daily push task.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_guardlms\task\push_site_info
 */
final class push_site_info_task_test extends \advanced_testcase {
    /**
     * Run the task once and return everything it traced.
     *
     * @return string
     */
    private function execute_task(): string {
        ob_start();
        (new push_site_info())->execute();

        return (string) ob_get_clean();
    }

    /**
     * A connected site that never stored a base URL still pushes to the default host.
     *
     * The base URL setting only exists on the advanced settings page, so a fresh
     * install never persists it. The task has to fall back to config::baseurl()
     * exactly like the connect flow does, instead of skipping every night.
     */
    public function test_execute_pushes_when_baseurl_is_unset(): void {
        global $CFG;

        $this->resetAfterTest();
        require_once($CFG->libdir . '/filelib.php');

        set_config('enabled', 1, 'local_guardlms');
        set_config('apikey', 'push-key-from-connect', 'local_guardlms');
        unset_config('baseurl', 'local_guardlms');

        \curl::mock_response(json_encode(['message' => 'ok']));

        $output = $this->execute_task();

        $this->assertStringContainsString('GuardLMS push succeeded (HTTP 200).', $output);
        $this->assertSame('200', get_config('local_guardlms', 'lastpushstatus'));
    }

    /**
     * Without a push key there is nothing to authenticate with, so the task skips.
     */
    public function test_execute_skips_without_an_api_key(): void {
        $this->resetAfterTest();

        set_config('enabled', 1, 'local_guardlms');
        unset_config('apikey', 'local_guardlms');

        $output = $this->execute_task();

        $this->assertStringContainsString('not configured, skipping', $output);
        $this->assertFalse(get_config('local_guardlms', 'lastpushstatus'));
    }
}
