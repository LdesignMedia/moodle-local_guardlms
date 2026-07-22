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

use local_guardlms\local\config;

/**
 * Tests for the connection defaults.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_guardlms\local\config
 */
final class config_test extends \advanced_testcase {
    /**
     * An untouched site pushes to the packaged GuardLMS service.
     */
    public function test_defaults_apply_when_unset(): void {
        $this->resetAfterTest();

        $this->assertSame(config::DEFAULT_BASEURL, config::baseurl());
        $this->assertSame(config::DEFAULT_PUSHPATH, config::pushpath());
        $this->assertSame(config::DEFAULT_BASEURL . config::DEFAULT_PUSHPATH, config::pushendpoint());
    }

    /**
     * An empty stored value falls back to the default instead of building a hostless URL.
     */
    public function test_empty_stored_values_fall_back(): void {
        $this->resetAfterTest();

        set_config('baseurl', '  ', 'local_guardlms');
        set_config('pushpath', '', 'local_guardlms');

        $this->assertSame(config::DEFAULT_BASEURL, config::baseurl());
        $this->assertSame(config::DEFAULT_PUSHPATH, config::pushpath());
    }

    /**
     * Advanced overrides win, and the slashes are normalised on both sides of the join.
     */
    public function test_overrides_are_normalised(): void {
        $this->resetAfterTest();

        set_config('baseurl', 'https://guardlms.example.com/', 'local_guardlms');
        set_config('pushpath', 'api/externalpush/moodle', 'local_guardlms');

        $this->assertSame('https://guardlms.example.com', config::baseurl());
        $this->assertSame('/api/externalpush/moodle', config::pushpath());
        $this->assertSame(
            'https://guardlms.example.com/api/externalpush/moodle',
            config::pushendpoint()
        );
    }
}
