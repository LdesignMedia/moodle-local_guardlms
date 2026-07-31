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
 * Scheduled task that pushes the site reporting payload to GuardLMS.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\task;

use core\task\scheduled_task;
use local_guardlms\local\pusher;

/**
 * Daily push of the Moodle, server and PHP inventory to the GuardLMS endpoint.
 */
class push_site_info extends scheduled_task {
    /**
     * Human readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:pushsiteinfo', 'local_guardlms');
    }

    /**
     * Build the payload and POST it to GuardLMS.
     */
    public function execute(): void {
        if (!get_config('local_guardlms', 'enabled')) {
            mtrace('GuardLMS push disabled, skipping.');
            return;
        }

        $baseurl = trim((string) get_config('local_guardlms', 'baseurl'));
        $apikey = trim((string) get_config('local_guardlms', 'apikey'));
        if ($baseurl === '' || $apikey === '') {
            mtrace('GuardLMS base URL or API key not configured, skipping.');
            return;
        }

        // Warn while the push key is close to its expiry date so admins can
        // reconnect (one click) before pushes start failing.
        $keyexpiresat = (int) get_config('local_guardlms', 'keyexpiresat');
        if ($keyexpiresat && $keyexpiresat < time() + (30 * DAYSECS)) {
            mtrace('Warning: the GuardLMS push key expires on ' . userdate($keyexpiresat)
                . '. Reconnect via the GuardLMS connect page to refresh it.');
        }

        // Refresh Moodle's update-check data as part of this run. The task is
        // scheduled daily, matching the cadence Moodle's own update cron uses,
        // so a site whose core update cron never runs still reports which of
        // its plugins have updates waiting instead of reporting silence.
        mtrace(pusher::push(true));
    }
}
