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
 * Connect this site to GuardLMS with one click.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_guardlms\local\connect_manager;

admin_externalpage_setup('local_guardlms_connect');

$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'connect' && confirm_sesskey()) {
    $manager = new connect_manager();
    redirect($manager->start_connect());
}

$PAGE->set_title(get_string('connect:title', 'local_guardlms'));
$PAGE->set_heading(get_string('connect:title', 'local_guardlms'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('connect:title', 'local_guardlms'));

$connected = connect_manager::is_connected();

if ($connected) {
    echo $OUTPUT->notification(get_string('connect:statusconnected', 'local_guardlms'), 'notifysuccess');

    $details = [];
    $websiteid = (int) get_config('local_guardlms', 'websiteid');
    if ($websiteid) {
        $details[] = get_string('connect:websiteid', 'local_guardlms', $websiteid);
    }
    $connectedat = (int) get_config('local_guardlms', 'connectedat');
    if ($connectedat) {
        $details[] = get_string('connect:connectedat', 'local_guardlms', userdate($connectedat));
    }
    $keyexpiresat = (int) get_config('local_guardlms', 'keyexpiresat');
    if ($keyexpiresat) {
        $details[] = get_string('connect:keyexpires', 'local_guardlms', userdate($keyexpiresat));
    }
    $lastpush = (int) get_config('local_guardlms', 'lastpush');
    if ($lastpush) {
        $details[] = get_string('connect:lastpush', 'local_guardlms', userdate($lastpush));
    }

    if ($details) {
        echo html_writer::alist($details);
    }

    echo html_writer::tag('p', get_string('connect:reconnectinfo', 'local_guardlms'));
} else {
    echo html_writer::tag('p', get_string('connect:intro', 'local_guardlms'));
    echo html_writer::tag('p', get_string('connect:freeaccount', 'local_guardlms'));
}

$connecturl = new moodle_url('/local/guardlms/connect.php', ['action' => 'connect', 'sesskey' => sesskey()]);
$buttonlabel = $connected
    ? get_string('connect:reconnectbutton', 'local_guardlms')
    : get_string('connect:button', 'local_guardlms');
echo $OUTPUT->single_button($connecturl, $buttonlabel, 'post');

echo $OUTPUT->footer();
