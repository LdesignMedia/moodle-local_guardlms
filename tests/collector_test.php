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
}
