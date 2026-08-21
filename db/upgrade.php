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
 * Upgrade steps for local_guardlms.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run the local_guardlms upgrade steps.
 *
 * @param int $oldversion The version the site is upgrading from.
 * @return bool
 */
function xmldb_local_guardlms_upgrade(int $oldversion): bool {
    if ($oldversion < 2026072800) {
        // Real-time monitoring is opt-in. Write both defaults explicitly rather
        // than relying on the admin_setting defaults: admin_apply_default_settings()
        // only writes a default for a setting that is in the admin tree at the
        // time it runs, and this section is built conditionally. An explicit
        // write makes "off" true for every upgraded site regardless of ordering.
        \local_guardlms\local\sdk_config::write_opt_in_defaults();

        // A connected site can have its key before the admin ever opens the
        // settings page. Not queued when disconnected: there is no credential
        // to authenticate the fetch with.
        if (\local_guardlms\local\connect_manager::is_connected()) {
            \local_guardlms\task\refresh_sdk_config::queue();
        }

        upgrade_plugin_savepoint(true, 2026072800, 'local', 'guardlms');
    }

    if ($oldversion < 2026082100) {
        // Earlier releases shipped https://app.guardlms.com as the default base
        // URL, a host that was never provisioned in production (every request
        // returns 404). admin_apply_default_settings() persisted that default,
        // so an explicit rewrite is needed; changing the constant alone would
        // only fix fresh installs. Only the untouched default is rewritten —
        // a deliberately overridden base URL (self-hosted) is left alone.
        $stored = rtrim(trim((string) get_config('local_guardlms', 'baseurl')), '/');
        if ($stored === \local_guardlms\local\config::LEGACY_BASEURL) {
            set_config('baseurl', \local_guardlms\local\config::DEFAULT_BASEURL, 'local_guardlms');
        }

        upgrade_plugin_savepoint(true, 2026082100, 'local', 'guardlms');
    }

    return true;
}
