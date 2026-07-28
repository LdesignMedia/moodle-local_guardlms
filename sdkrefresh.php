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
 * GuardLMS SDK configuration refresh endpoint.
 *
 * Fetches the SDK key and configuration on demand, so an admin never has to
 * wait for cron. A bare action endpoint: the Refresh now link on the plugin
 * settings page sends the admin here and the page it returns to shows the
 * result.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_guardlms\local\sdk_client;
use local_guardlms\local\sdk_config;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);
require_sesskey();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/guardlms/sdkrefresh.php'));

$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_guardlms']);

if (sdk_client::resolve('fetch')) {
    redirect(
        $settingsurl,
        get_string('sdk:refreshsuccess', 'local_guardlms'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// The resolve() call recorded the reason. Show it rather than a generic failure, so
// the admin does not have to hunt for it on the page they are being sent to.
$reason = sdk_config::refresh_error();
if ($reason === '') {
    $reason = sdk_config::backend_unsupported()
        ? get_string('sdk:backendunsupported', 'local_guardlms')
        : get_string('error:notconfigured', 'local_guardlms');
}

redirect($settingsurl, $reason, null, \core\output\notification::NOTIFY_ERROR);
