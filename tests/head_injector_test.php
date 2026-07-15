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

use local_guardlms\local\head_injector;

/**
 * Tests for the ownership verification meta tag emission.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_guardlms\local\head_injector
 */
final class head_injector_test extends \advanced_testcase {
    /**
     * The meta tag is emitted when the plugin is enabled and a token exists.
     */
    public function test_meta_tag_emitted_when_connected(): void {
        $this->resetAfterTest();

        set_config('enabled', 1, 'local_guardlms');
        set_config('verificationtoken', 'tok<en>"quoted"', 'local_guardlms');

        $tag = head_injector::meta_tag();

        $this->assertStringContainsString('<meta name="guardlms-verification"', $tag);
        // The token must be escaped for HTML output.
        $this->assertStringNotContainsString('tok<en>', $tag);
        $this->assertStringContainsString(s('tok<en>"quoted"'), $tag);
    }

    /**
     * Nothing is emitted without a token.
     */
    public function test_meta_tag_empty_without_token(): void {
        $this->resetAfterTest();

        set_config('enabled', 1, 'local_guardlms');
        unset_config('verificationtoken', 'local_guardlms');

        $this->assertSame('', head_injector::meta_tag());
    }

    /**
     * Nothing is emitted when the plugin is disabled.
     */
    public function test_meta_tag_empty_when_disabled(): void {
        $this->resetAfterTest();

        set_config('enabled', 0, 'local_guardlms');
        set_config('verificationtoken', 'sometoken', 'local_guardlms');

        $this->assertSame('', head_injector::meta_tag());
    }
}
