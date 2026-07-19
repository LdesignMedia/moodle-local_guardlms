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
 * Admin settings for local_guardlms.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Cache the webserver software while we are in a web request. The daily task
    // runs under CLI cron where this superglobal is empty, so it is read back
    // from plugin config there.
    if (!empty($_SERVER['SERVER_SOFTWARE'])) {
        $software = $_SERVER['SERVER_SOFTWARE'];
        if (get_config('local_guardlms', 'webserver') !== $software) {
            set_config('webserver', $software, 'local_guardlms');
        }
    }

    $settings = new admin_settingpage('local_guardlms', get_string('pluginname', 'local_guardlms'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $connected = \local_guardlms\local\connect_manager::is_connected();

        // The button triggers the connect redirect directly; connect.php is a
        // bare redirect endpoint. The status and the button live on this page.
        $connecturl = new moodle_url('/local/guardlms/connect.php', ['sesskey' => sesskey()]);

        // Connection status shown here on the settings page. The GuardLMS logo
        // is prepended to the page title via styles.css, so no logo markup is
        // needed here.
        $status = '';
        if ($connected) {
            $status .= html_writer::div(get_string('connect:statusconnected', 'local_guardlms'), 'alert alert-success');

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
                $status .= html_writer::alist($details);
            }
        } else {
            $status .= html_writer::tag('p', get_string('connect:intro', 'local_guardlms'));
            $status .= html_writer::tag('p', get_string('connect:freeaccount', 'local_guardlms'));
        }

        // A single Connect / Reconnect button in the GuardLMS brand colour.
        $buttonlabel = $connected
            ? get_string('connect:reconnectbutton', 'local_guardlms')
            : get_string('connect:button', 'local_guardlms');
        $status .= html_writer::div(
            html_writer::link($connecturl, $buttonlabel, ['class' => 'btn local-guardlms-btn']),
            'mt-2 mb-2'
        );

        $settings->add(new admin_setting_heading('local_guardlms/header', '', $status));

        // GuardLMS base URL stays editable so an admin can point at their own
        // GuardLMS instance. pushpath and apikey are connection internals written
        // by the connect flow (connect_manager::complete_connect) and are not
        // shown, so they cannot be hand-edited to a wrong endpoint or a replaced
        // key. pushpath falls back to its default in pusher.php.
        $settings->add(new admin_setting_configtext(
            'local_guardlms/baseurl',
            get_string('settings:baseurl', 'local_guardlms'),
            get_string('settings:baseurl_desc', 'local_guardlms'),
            'https://app.guardlms.com',
            PARAM_URL
        ));

        // Operational toggles only make sense once the site is connected.
        if ($connected) {
            $settings->add(new admin_setting_configcheckbox(
                'local_guardlms/enabled',
                get_string('settings:enabled', 'local_guardlms'),
                get_string('settings:enabled_desc', 'local_guardlms'),
                1
            ));

            $settings->add(new admin_setting_configcheckbox(
                'local_guardlms/sendconfig',
                get_string('settings:sendconfig', 'local_guardlms'),
                get_string('settings:sendconfig_desc', 'local_guardlms'),
                0
            ));
        }
    }
}
