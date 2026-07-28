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

use local_guardlms\task\refresh_sdk_config;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/local/guardlms/db/upgrade.php');

/**
 * Tests for the 1.4.0 upgrade step.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_guardlms_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /** @var int The version the upgrade step runs from. */
    private const OLD_VERSION = 2026072201;

    /** @var int The savepoint this release writes. */
    private const NEW_VERSION = 2026072800;

    /**
     * Pretend the site is still on the previous release.
     *
     * upgrade_plugin_savepoint() refuses to move a version backwards, so the
     * stored version has to be the old one for the step to be runnable at all.
     */
    private function pretend_old_version(): void {
        set_config('version', self::OLD_VERSION, 'local_guardlms');
    }

    /**
     * Count the queued SDK refresh tasks.
     *
     * @return int
     */
    private function queued_refresh_tasks(): int {
        global $DB;

        return $DB->count_records('task_adhoc', ['classname' => '\\' . refresh_sdk_config::class]);
    }

    /**
     * E6: the upgrade writes the opt-in defaults explicitly.
     */
    public function test_upgrade_writes_the_opt_in_defaults(): void {
        $this->resetAfterTest();
        $this->pretend_old_version();

        unset_config('sdkenabled', 'local_guardlms');
        unset_config('sdkanalytics', 'local_guardlms');

        $this->assertTrue(xmldb_local_guardlms_upgrade(self::OLD_VERSION));

        $this->assertSame('0', get_config('local_guardlms', 'sdkenabled'));
        $this->assertSame('0', get_config('local_guardlms', 'sdkanalytics'));
        $this->assertSame((string) self::NEW_VERSION, get_config('local_guardlms', 'version'));
    }

    /**
     * E6: a connected site gets a refresh queued so its key arrives without cron waits.
     */
    public function test_upgrade_queues_a_refresh_when_connected(): void {
        $this->resetAfterTest();
        $this->pretend_old_version();

        set_config('apikey', 'push-key', 'local_guardlms');
        set_config('connectedat', time(), 'local_guardlms');

        $this->assertSame(0, $this->queued_refresh_tasks());

        xmldb_local_guardlms_upgrade(self::OLD_VERSION);

        $this->assertSame(1, $this->queued_refresh_tasks());
    }

    /**
     * E6: a disconnected site queues nothing.
     *
     * There is no credential to authenticate the fetch with, so the task could
     * only fail and write a misleading error onto the settings page.
     */
    public function test_upgrade_queues_nothing_when_disconnected(): void {
        $this->resetAfterTest();
        $this->pretend_old_version();

        unset_config('apikey', 'local_guardlms');
        unset_config('connectedat', 'local_guardlms');

        xmldb_local_guardlms_upgrade(self::OLD_VERSION);

        $this->assertSame(0, $this->queued_refresh_tasks());
    }

    /**
     * E6: re-running the step is a no-op.
     */
    public function test_upgrade_is_a_no_op_on_re_run(): void {
        $this->resetAfterTest();
        $this->pretend_old_version();

        set_config('apikey', 'push-key', 'local_guardlms');
        set_config('connectedat', time(), 'local_guardlms');

        xmldb_local_guardlms_upgrade(self::OLD_VERSION);
        $this->assertSame(1, $this->queued_refresh_tasks());

        // The admin has since opted in. A re-run must not undo that.
        set_config('sdkenabled', 1, 'local_guardlms');

        $this->assertTrue(xmldb_local_guardlms_upgrade(self::NEW_VERSION));

        $this->assertSame('1', get_config('local_guardlms', 'sdkenabled'), 'A re-run must not reset the admin choice.');
        $this->assertSame(1, $this->queued_refresh_tasks(), 'A re-run must not queue a second refresh.');
    }

    /**
     * A site already past the savepoint is untouched.
     */
    public function test_upgrade_skips_a_site_already_past_the_savepoint(): void {
        $this->resetAfterTest();

        set_config('version', self::NEW_VERSION, 'local_guardlms');
        set_config('sdkenabled', 1, 'local_guardlms');
        set_config('apikey', 'push-key', 'local_guardlms');
        set_config('connectedat', time(), 'local_guardlms');

        $this->assertTrue(xmldb_local_guardlms_upgrade(self::NEW_VERSION + 1));

        $this->assertSame('1', get_config('local_guardlms', 'sdkenabled'));
        $this->assertSame(0, $this->queued_refresh_tasks());
    }

    /**
     * Duplicate refresh tasks collapse into one.
     */
    public function test_queued_refreshes_do_not_pile_up(): void {
        $this->resetAfterTest();

        refresh_sdk_config::queue();
        refresh_sdk_config::queue();
        refresh_sdk_config::queue();

        $this->assertSame(1, $this->queued_refresh_tasks());
    }
}
