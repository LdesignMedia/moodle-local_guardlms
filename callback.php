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
 * GuardLMS connect callback: exchanges the one-time code for the push key.
 *
 * GuardLMS redirects the admin's browser here after consent. The state check
 * ties the callback to the connect attempt this site started (CSRF), and the
 * actual exchange happens server-to-server.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_guardlms\local\connect_manager;

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/guardlms/callback.php'));

$code = required_param('code', PARAM_ALPHANUMEXT);
$state = required_param('state', PARAM_ALPHANUMEXT);

// Return to the plugin settings page, where the connection status is shown.
$returnurl = new moodle_url('/admin/settings.php', ['section' => 'local_guardlms']);

try {
    $manager = new connect_manager();
    $manager->complete_connect($code, $state);
} catch (moodle_exception $exception) {
    redirect($returnurl, $exception->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
}

redirect($returnurl);
