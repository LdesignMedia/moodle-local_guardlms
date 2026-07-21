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

// Connect flow.
$string['connect:title'] = 'Connect to GuardLMS';
$string['connect:settingheading'] = 'Connect to GuardLMS';
$string['connect:settingdesc'] = 'Connect this site to GuardLMS with one click. You only need a GuardLMS account (a free account is enough), no API keys to copy.';
$string['connect:intro'] = 'Connecting registers this site with your GuardLMS account, verifies site ownership automatically, and sets up the daily inventory push, all in one click.';
$string['connect:freeaccount'] = 'You only need a GuardLMS account; a free account is enough. If you are not logged in yet, you can log in or register during the connection.';
$string['connect:button'] = 'Connect to GuardLMS';
$string['connect:reconnectbutton'] = 'Reconnect to GuardLMS';
$string['connect:reconnectinfo'] = 'Reconnecting issues a fresh push key for this site. Use it if pushes fail or the key is about to expire.';
$string['connect:statusconnected'] = 'This site is connected to GuardLMS.';
$string['connect:websiteid'] = 'GuardLMS website ID: {$a}';
$string['connect:connectedat'] = 'Connected on: {$a}';
$string['connect:keyexpires'] = 'Push key expires: {$a}';
$string['connect:lastpush'] = 'Last successful push: {$a}';
$string['connect:success'] = 'Your site is now connected to GuardLMS. The first inventory push has been queued.';

// Settings.
$string['settings:advancedheading'] = 'Advanced settings';
$string['settings:advancedwarning'] = 'These settings are only needed for a self-hosted GuardLMS instance or for support. Changing them on a normal site breaks the connection.';
$string['settings:pushpath'] = 'Push path';
$string['settings:pushpath_desc'] = 'Path of the inventory push endpoint on the GuardLMS host. The connection sets this automatically.';
$string['settings:enabled'] = 'Enable daily push';
$string['settings:enabled_desc'] = 'When enabled, the site reporting payload is sent to GuardLMS once a day.';
$string['settings:baseurl'] = 'GuardLMS base URL';
$string['settings:baseurl_desc'] = 'Base URL of your GuardLMS instance, for example https://app.guardlms.com. Leave the default unless you run a self-hosted GuardLMS.';
$string['settings:sendconfig'] = 'Include Moodle configuration';
$string['settings:sendconfig_desc'] = 'Optional. Also send selected security and session settings (such as the cookie policy) so GuardLMS can review how the site is configured. Off by default.';

// Scheduled task.
$string['task:pushsiteinfo'] = 'Push site information to GuardLMS';

// Errors.
$string['error:pushfailed'] = 'The push to GuardLMS failed: {$a}';
$string['error:pushhttp'] = 'GuardLMS rejected the push with HTTP status {$a}.';
$string['error:notconfigured'] = 'GuardLMS is not connected yet. Use the Connect to GuardLMS button on the plugin settings page to set it up.';
$string['error:connectstate'] = 'The connection attempt is invalid or has expired. Please start the connection again from the Connect to GuardLMS page.';
$string['error:connectfailed'] = 'Could not reach GuardLMS to complete the connection: {$a}';
$string['error:connectrejected'] = 'GuardLMS rejected the connection: {$a}';

// Privacy.
$string['privacy:metadata'] = 'The GuardLMS plugin stores no personal data. It pushes the Moodle version, installed plugin inventory and server environment to GuardLMS for security monitoring.';
