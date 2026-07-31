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
 * Ad-hoc task that pushes the inventory right after a successful connect.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\task;

use core\task\adhoc_task;
use local_guardlms\local\pusher;

/**
 * One-off inventory push queued by the connect flow.
 */
class initial_push extends adhoc_task {
    /**
     * Push the inventory once; failures are logged, the daily task retries anyway.
     */
    public function execute(): void {
        if (!get_config('local_guardlms', 'enabled')) {
            mtrace('GuardLMS push disabled, skipping initial push.');
            return;
        }

        // Runs under cron, so it may refresh the update-check data: this is the
        // retry path for a connect-time push, and it should not hand GuardLMS a
        // second inventory that still says "updates unknown".
        mtrace(pusher::push(true));
    }
}
