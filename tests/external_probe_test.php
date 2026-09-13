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
 * Synthetic external security probes containing no user data.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms;

use local_guardlms\local\external_probe;

/**
 * Tests for synthetic external probes.
 * @covers \local_guardlms\local\external_probe
 */
final class external_probe_test extends \advanced_testcase {
    /** Tests generation, file storage and narrow authorization. */
    public function test_descriptor_contains_only_synthetic_content(): void {
        $this->resetAfterTest();
        $probe = external_probe::collect();
        $this->assertSame(64, strlen($probe['token']));
        $this->assertNotSame($probe['token'], $probe['marker']);
        $this->assertFalse(external_probe::authorized(''));
        $this->assertFalse(external_probe::authorized('Bearer wrong'));
        $this->assertTrue(external_probe::authorized('Bearer ' . $probe['token']));
        $this->assertSame($probe, external_probe::collect());
        $file = get_file_storage()->get_file(\context_system::instance()->id,
            'local_guardlms', 'securityprobe', 0, '/', 'marker.txt');
        $this->assertSame($probe['marker'], $file->get_content());
    }

    /** Tests expiry and rotation without accumulating test files. */
    public function test_expired_credentials_stop_working_and_rotate(): void {
        $this->resetAfterTest();
        $probe = external_probe::collect();
        $probe['expires'] = time() - 1;
        set_config('externalprobe', json_encode($probe), 'local_guardlms');
        $this->assertFalse(external_probe::authorized('Bearer ' . $probe['token']));
        $fresh = external_probe::collect();
        $this->assertNotSame($probe['token'], $fresh['token']);
        $this->assertFalse(external_probe::authorized('Bearer ' . $probe['token']));
        $files = get_file_storage()->get_area_files(\context_system::instance()->id,
            'local_guardlms', 'securityprobe', false, 'id', false);
        $this->assertCount(1, $files);
    }

    /** Tests that anonymous pluginfile requests cannot access the stored marker. */
    public function test_pluginfile_rejects_anonymous_requests(): void {
        global $CFG;
        $this->resetAfterTest();
        external_probe::collect();
        require_once($CFG->dirroot . '/local/guardlms/lib.php');
        $this->assertFalse(local_guardlms_pluginfile(null, null, \context_system::instance(),
            'securityprobe', ['0', 'marker.txt'], true));
        $this->assertFalse(local_guardlms_pluginfile(null, null, \context_system::instance(),
            'unrelated', ['0', 'marker.txt'], true));
    }
}
