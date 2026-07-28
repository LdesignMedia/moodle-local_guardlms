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
        // Advanced mode is deliberately URL only, so an end user never sees the
        // connection internals: /admin/settings.php?section=local_guardlms&mode=advanced.
        // The settings form posts back to a URL without the mode parameter, so the
        // marker setting added below re-flags advanced mode on the save request.
        $advanced = optional_param('mode', '', PARAM_ALPHA) === 'advanced'
            || optional_param('guardlmsadv', 0, PARAM_BOOL);

        $connected = \local_guardlms\local\connect_manager::is_connected();

        // The buttons trigger the endpoints directly; connect.php is a bare
        // redirect and disconnect.php a bare action. The status and both buttons
        // live on this page.
        $connecturl = new moodle_url('/local/guardlms/connect.php', ['sesskey' => sesskey()]);
        $disconnecturl = new moodle_url('/local/guardlms/disconnect.php', ['sesskey' => sesskey()]);

        // Branded heading: favicon before the plugin name. The image is built
        // with the full wwwroot so it resolves on subdirectory installs, and the
        // plain core title above is hidden by styles.css to avoid a duplicate.
        $logo = html_writer::img(
            $CFG->wwwroot . '/local/guardlms/pix/icon.png',
            get_string('pluginname', 'local_guardlms'),
            ['class' => 'local-guardlms-logo']
        );

        // One status line, green when connected and red when not. The WordPress
        // plugin renders the same block, so both plugins look the same.
        $status = html_writer::div(
            html_writer::span(get_string('connect:statuslabel', 'local_guardlms')) . ' ' .
            html_writer::span(
                $connected
                    ? get_string('connect:statusconnected', 'local_guardlms')
                    : get_string('connect:statusdisconnected', 'local_guardlms'),
                'local-guardlms-badge ' .
                    ($connected ? 'local-guardlms-badge-connected' : 'local-guardlms-badge-disconnected')
            ),
            'local-guardlms-status'
        );

        $details = [];
        if ($connected) {
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
        } else {
            $status .= html_writer::tag('p', get_string('connect:intro', 'local_guardlms'));
            $status .= html_writer::tag('p', get_string('connect:freeaccount', 'local_guardlms'));
        }

        // Connect / Reconnect in the GuardLMS brand colour, plus Disconnect once
        // the site is connected.
        $buttons = html_writer::link(
            $connecturl,
            $connected
                ? get_string('connect:reconnectbutton', 'local_guardlms')
                : get_string('connect:button', 'local_guardlms'),
            ['class' => 'btn local-guardlms-btn']
        );
        if ($connected) {
            $buttons .= html_writer::link(
                $disconnecturl,
                get_string('connect:disconnectbutton', 'local_guardlms'),
                ['class' => 'btn local-guardlms-btn-disconnect']
            );
        }
        $status .= html_writer::div($buttons, 'mt-2 mb-2');

        // Connection details sit under the button: the action comes first, the
        // dates are reference information.
        if ($details) {
            $status .= html_writer::alist($details);
        }

        $settings->add(new admin_setting_heading(
            'local_guardlms/header',
            $logo . get_string('pluginname', 'local_guardlms'),
            $status
        ));

        // Everything below is advanced: an end user only needs the button above.
        // The apikey and the verification token are connection internals written
        // by the connect flow (connect_manager::complete_connect) and are never
        // shown, so they cannot be hand-edited to a replaced key.
        if ($advanced) {
            $settings->add(new \local_guardlms\admin\setting_advanced_marker());

            $settings->add(new admin_setting_heading(
                'local_guardlms/advancedheading',
                get_string('settings:advancedheading', 'local_guardlms'),
                html_writer::div(get_string('settings:advancedwarning', 'local_guardlms'), 'alert alert-warning')
            ));

            // Point the plugin at a different GuardLMS instance. Can also be pinned in
            // config.php with $CFG->forced_plugin_settings['local_guardlms']['baseurl'].
            $settings->add(new admin_setting_configtext(
                'local_guardlms/baseurl',
                get_string('settings:baseurl', 'local_guardlms'),
                get_string('settings:baseurl_desc', 'local_guardlms'),
                \local_guardlms\local\config::DEFAULT_BASEURL,
                PARAM_URL
            ));

            $settings->add(new admin_setting_configtext(
                'local_guardlms/pushpath',
                get_string('settings:pushpath', 'local_guardlms'),
                get_string('settings:pushpath_desc', 'local_guardlms'),
                \local_guardlms\local\config::DEFAULT_PUSHPATH,
                PARAM_PATH
            ));

            // Pin the site URL registered with, and reported to, GuardLMS. Empty
            // means use this site's wwwroot. Needed on cloned/proxied sites where
            // the reported address differs from the one registered at connect.
            $settings->add(new admin_setting_configtext(
                'local_guardlms/siteurloverride',
                get_string('settings:siteurloverride', 'local_guardlms'),
                get_string('settings:siteurloverride_desc', 'local_guardlms', $CFG->wwwroot),
                '',
                PARAM_URL
            ));

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
