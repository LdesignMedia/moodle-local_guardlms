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

    /**
     * The settings callback queues a refresh only for a connected site.
     */
    public function test_settings_callback_queues_only_when_connected(): void {
        $this->resetAfterTest();

        refresh_sdk_config::queue_if_connected();
        $this->assertSame(0, $this->queued_refresh_tasks(), 'A disconnected site has no credential to refresh with.');

        set_config('apikey', 'push-key', 'local_guardlms');
        set_config('connectedat', time(), 'local_guardlms');

        refresh_sdk_config::queue_if_connected();
        $this->assertSame(1, $this->queued_refresh_tasks());
    }

    /**
     * The settings callback is never registered by function name.
     *
     * admin_setting::write_setting() guards its updated callback with
     * is_callable() and skips it *silently* when the function is not loaded.
     * lib.php is only included for plugins declaring before_session_start or
     * after_config, which this plugin does not, so a name-based callback would
     * save the toggle, report success, and never queue a refresh. Nothing would
     * surface the failure.
     *
     * Asserted at the source level because that is where the choice is made;
     * building the admin tree to inspect the registered value would execute the
     * settings page's synchronous bootstrap and issue a real HTTP request.
     */
    public function test_the_updated_callback_is_not_registered_by_name(): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/local/guardlms/settings.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('set_updatedcallback', $source, 'The guard is pointless if nothing registers one.');

        $this->assertDoesNotMatchRegularExpression(
            '/set_updatedcallback\(\s*[\'"]/',
            $source,
            'A string callback would be skipped silently when lib.php is not loaded.'
        );

        // What the closure reaches must exist without lib.php being included.
        $this->assertTrue(is_callable([refresh_sdk_config::class, 'queue_if_connected']));
    }

    /**
     * F3: the blocking bootstrap only runs when this section was requested.
     *
     * $ADMIN->fulltree does NOT mean "the admin asked for this page".
     * admin_get_root($reload = false, $requirefulltree = true) defaults to true
     * (lib/adminlib.php:8830), and admin/search.php:31, admin/category.php:40
     * and admin/settings.php:19 all call it bare - so fulltree is true while
     * building the tree for admin search, for a category listing, and for every
     * other plugin's settings page. A 5s blocking POST gated only on fulltree
     * would fire on all of them.
     *
     * Asserted at the source level because the alternative - building the admin
     * tree in-process to observe whether a request is issued - would itself
     * execute settings.php and make the outbound call this guard exists to
     * prevent.
     */
    public function test_the_blocking_bootstrap_is_gated_on_the_requested_section(): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/local/guardlms/settings.php');
        $this->assertNotFalse($source);

        // The section gate exists and names this plugin.
        $this->assertMatchesRegularExpression(
            "/optional_param\(\s*'section'.*?===\s*'local_guardlms'/s",
            $source,
            'The bootstrap must be gated on this plugin section actually being requested.'
        );

        // And the blocking call is downstream of that gate, not of fulltree alone.
        $gateat = strpos($source, "optional_param('section'");
        $callat = strpos($source, 'sdk_client::resolve(');
        $this->assertNotFalse($gateat);
        $this->assertNotFalse($callat);
        $this->assertLessThan($callat, $gateat, 'The section gate must precede the blocking call.');

        // The superseded claim must not survive in a comment: it told a reader
        // the guard did something it never did.
        $this->assertStringNotContainsString(
            'where fulltree is false',
            $source,
            'That comment asserted fulltree rules out admin search, which is false.'
        );
    }
}
