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

    $settings->add(new admin_setting_heading(
        'local_guardlms/info',
        get_string('settings:infoheading', 'local_guardlms'),
        get_string('settings:infodesc', 'local_guardlms')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_guardlms/enabled',
        get_string('settings:enabled', 'local_guardlms'),
        get_string('settings:enabled_desc', 'local_guardlms'),
        1
    ));

    $baseurl = new admin_setting_configtext(
        'local_guardlms/baseurl',
        get_string('settings:baseurl', 'local_guardlms'),
        get_string('settings:baseurl_desc', 'local_guardlms'),
        'https://app.guardlms.com',
        PARAM_URL
    );
    $baseurl->set_advanced_flag_options(admin_setting_flag::ENABLED, true);
    $settings->add($baseurl);

    $pushpath = new admin_setting_configtext(
        'local_guardlms/pushpath',
        get_string('settings:pushpath', 'local_guardlms'),
        get_string('settings:pushpath_desc', 'local_guardlms'),
        '/api/externalpush/moodle',
        PARAM_RAW
    );
    $pushpath->set_advanced_flag_options(admin_setting_flag::ENABLED, true);
    $settings->add($pushpath);

    $settings->add(new admin_setting_configpasswordunmask(
        'local_guardlms/apikey',
        get_string('settings:apikey', 'local_guardlms'),
        get_string('settings:apikey_desc', 'local_guardlms'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_guardlms/sendconfig',
        get_string('settings:sendconfig', 'local_guardlms'),
        get_string('settings:sendconfig_desc', 'local_guardlms'),
        0
    ));
}
