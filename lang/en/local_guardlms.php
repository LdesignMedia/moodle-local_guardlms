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
 * English strings for local_guardlms.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'GuardLMS';

// Settings.
$string['settings:infoheading'] = 'GuardLMS site reporting';
$string['settings:infodesc'] = 'GuardLMS monitors this site for known vulnerabilities. Once a day the plugin pushes the Moodle version, installed plugin inventory and server environment to GuardLMS over HTTPS. To connect, paste the API key from your GuardLMS dashboard below. No web service, token or service user setup is needed.';
$string['settings:enabled'] = 'Enable daily push';
$string['settings:enabled_desc'] = 'When enabled, the site reporting payload is sent to GuardLMS once a day.';
$string['settings:baseurl'] = 'GuardLMS base URL';
$string['settings:baseurl_desc'] = 'Base URL of your GuardLMS instance, for example https://app.guardlms.com.';
$string['settings:pushpath'] = 'Push endpoint path';
$string['settings:pushpath_desc'] = 'Path appended to the base URL that receives the push. Leave the default unless GuardLMS support tells you otherwise.';
$string['settings:apikey'] = 'API key';
$string['settings:apikey_desc'] = 'API key from your GuardLMS dashboard. Sent as a bearer token to authenticate the push.';
$string['settings:sendconfig'] = 'Include Moodle configuration';
$string['settings:sendconfig_desc'] = 'Optional. Also send selected security and session settings (such as the cookie policy) so GuardLMS can review how the site is configured. Off by default.';

// Scheduled task.
$string['task:pushsiteinfo'] = 'Push site information to GuardLMS';

// Errors.
$string['error:pushfailed'] = 'The push to GuardLMS failed: {$a}';
$string['error:pushhttp'] = 'GuardLMS rejected the push with HTTP status {$a}.';

// Privacy.
$string['privacy:metadata'] = 'The GuardLMS plugin stores no personal data. It pushes the Moodle version, installed plugin inventory and server environment to GuardLMS for security monitoring.';
