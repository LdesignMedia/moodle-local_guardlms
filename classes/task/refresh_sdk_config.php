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
 * Ad-hoc task that refreshes the stored GuardLMS SDK configuration.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\task;

use core\task\adhoc_task;
use local_guardlms\local\connect_manager;
use local_guardlms\local\sdk_client;

/**
 * Fetches the SDK key and configuration without blocking a web request.
 *
 * The belt to the settings page's synchronous bootstrap: it covers admins who
 * flip the toggle and never revisit the page.
 */
class refresh_sdk_config extends adhoc_task {
    /**
     * Refresh the SDK configuration once.
     */
    public function execute(): void {
        if (!connect_manager::is_connected()) {
            mtrace('GuardLMS is not connected, skipping SDK configuration refresh.');

            return;
        }

        if (sdk_client::resolve('fetch')) {
            mtrace('GuardLMS SDK configuration refreshed.');

            return;
        }

        // resolve() has already recorded why, and the settings page renders it.
        // Not raising here is deliberate: a failed refresh must not put the
        // task into the failure/backoff cycle, because the next scheduled run
        // would retry it anyway and an unreachable backend is not a defect.
        mtrace('GuardLMS SDK configuration refresh did not complete; see the plugin settings page.');
    }

    /**
     * Queue a refresh, collapsing duplicates.
     */
    public static function queue(): void {
        \core\task\manager::queue_adhoc_task(new self(), true);
    }
}
