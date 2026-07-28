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
$string['connect:button'] = 'Connect';
$string['connect:disconnectbutton'] = 'Disconnect';
$string['connect:disconnected'] = 'This site is no longer connected to GuardLMS. It has stopped reporting.';
$string['connect:reconnectbutton'] = 'Reconnect';
$string['connect:reconnectinfo'] = 'Reconnecting issues a fresh push key for this site. Use it if pushes fail or the key is about to expire.';
$string['connect:statuslabel'] = 'Status:';
$string['connect:statusconnected'] = 'Connected';
$string['connect:statusdisconnected'] = 'Not connected';
$string['connect:connectedat'] = 'Connected at: {$a}';
$string['connect:keyexpires'] = 'Expires at: {$a}';
$string['connect:lastpush'] = 'Last push: {$a}';
$string['connect:success'] = 'Your site is now connected to GuardLMS and its first inventory has been sent.';

// Settings.
$string['settings:advancedheading'] = 'Advanced settings';
$string['settings:advancedwarning'] = 'These settings are only needed for a self-hosted GuardLMS instance or for support. Changing them on a normal site breaks the connection.';
$string['settings:pushpath'] = 'Push path';
$string['settings:pushpath_desc'] = 'Path of the inventory push endpoint on the GuardLMS host. The connection sets this automatically.';
$string['settings:enabled'] = 'Enable daily push';
$string['settings:enabled_desc'] = 'When enabled, the site reporting payload is sent to GuardLMS once a day.';
$string['settings:baseurl'] = 'GuardLMS base URL';
$string['settings:baseurl_desc'] = 'Base URL of your GuardLMS instance, for example https://app.guardlms.com. Leave the default unless you run a self-hosted GuardLMS.';
$string['settings:siteurloverride'] = 'Site URL override';
$string['settings:siteurloverride_desc'] = 'Optional. The site URL registered with GuardLMS and sent on every push. Leave empty to use this site\'s address ({$a}). Set an explicit value only if GuardLMS rejects pushes with a "siteurl does not match" error, for example on cloned, staging or reverse-proxied sites where the reported address differs from the one registered when you connected. Reconnect after changing this so the push key is reissued for the new URL.';
$string['settings:sendconfig'] = 'Include Moodle configuration';
$string['settings:sendconfig_desc'] = 'Optional. Also send selected security and session settings (such as the cookie policy) so GuardLMS can review how the site is configured. Off by default.';

// Real-time monitoring settings.
$string['settings:realtimeheading'] = 'Real-time monitoring';
$string['settings:sdkenabled'] = 'Enable real-time monitoring';
$string['settings:sdkenabled_desc'] = 'When enabled, JavaScript errors from your site are reported to GuardLMS as they happen. No learner names, email addresses or user IDs are sent, and clicks and form entries are never recorded.';
$string['settings:sdkanalytics'] = 'Enable page analytics';
$string['settings:sdkanalytics_desc'] = 'Optional. Also send anonymous page view and scroll depth information. Requires a GuardLMS plan that includes analytics.';

// Real-time monitoring status.
$string['sdk:statusactive'] = 'Real-time monitoring is active. Errors from this site are being reported to GuardLMS.';
$string['sdk:statusready'] = 'This site is ready for real-time monitoring. Tick the box below and save to switch it on.';
$string['sdk:statusnokey'] = 'The monitoring key has not been fetched yet. Use Refresh now to fetch it.';
$string['sdk:statusnotconnected'] = 'Connect this site to GuardLMS first. Real-time monitoring needs the connection to fetch its key.';
$string['sdk:backendunsupportedactive'] = 'This GuardLMS instance no longer offers real-time monitoring, but monitoring is still switched on here and the GuardLMS script is still being loaded on every page. Untick the box below and save to stop it.';
$string['sdk:statusnosubscription'] = 'No active GuardLMS subscription - real-time data is not being collected.';
$string['sdk:statusdashboardoff'] = 'Real-time monitoring is turned off in the GuardLMS dashboard.';
$string['sdk:statusrefresherror'] = 'The last attempt to reach GuardLMS failed: {$a}';
$string['sdk:analyticsnotinplan'] = 'Analytics is not included in your GuardLMS plan - error monitoring is still active.';
$string['sdk:domainmismatch'] = 'GuardLMS only accepts data from {$a->allowed}; this site reports as {$a->actual}. Update Allowed domains in the GuardLMS dashboard.';
$string['sdk:requires44'] = 'Real-time monitoring requires Moodle 4.4 or later. The toggle has no effect on this site.';
$string['sdk:backendunsupported'] = 'This GuardLMS instance does not support real-time monitoring yet.';
$string['sdk:norefreshyet'] = 'No successful refresh yet.';
$string['sdk:lastrefresh'] = 'Last successful refresh: {$a}';
$string['sdk:refreshnow'] = 'Refresh now';
$string['sdk:refreshsuccess'] = 'The GuardLMS real-time monitoring settings have been refreshed.';
$string['sdk:testerror'] = 'Send a test error';

// Scheduled task.
$string['task:pushsiteinfo'] = 'Push site information to GuardLMS';

// Errors.
$string['error:pushfailed'] = 'The push to GuardLMS failed: {$a}';
$string['error:pushhttp'] = 'GuardLMS rejected the push with HTTP status {$a}.';
$string['error:notconfigured'] = 'GuardLMS is not connected yet. Use the Connect to GuardLMS button on the plugin settings page to set it up.';
$string['error:connectstate'] = 'The connection attempt is invalid or has expired. Please start the connection again from the Connect to GuardLMS page.';
$string['error:connectfailed'] = 'Could not reach GuardLMS to complete the connection: {$a}';
$string['error:connectrejected'] = 'GuardLMS rejected the connection: {$a}';
$string['error:sdkrefreshfailed'] = 'Could not refresh the real-time monitoring settings: {$a}';

// Privacy.
$string['privacy:metadata:guardlms'] = 'With real-time monitoring enabled, this plugin loads GuardLMS JavaScript that reports browser errors from this site to GuardLMS. If page analytics is also enabled, it reports a record of every page view as well, not only pages where an error happened. No name, email address or user ID is ever sent, and clicks and form entries are never recorded.';
$string['privacy:metadata:guardlms:pageurl'] = 'The address of the page the error happened on. Session keys and similar tokens are removed before it is sent.';
$string['privacy:metadata:guardlms:referrerurl'] = 'The address of the page that linked to the page the error happened on.';
$string['privacy:metadata:guardlms:useragent'] = 'The browser and operating system reported by the browser, plus the window size.';
$string['privacy:metadata:guardlms:sessionid'] = 'An anonymous identifier that groups errors from one browsing session together. It is not the Moodle user ID and cannot be traced back to an account by GuardLMS.';
$string['privacy:metadata:guardlms:errordetails'] = 'The error message, the script and line it came from, the stack trace, and a short trail of preceding page loads, network requests and console messages.';
$string['privacy:metadata:guardlms:pageviews'] = 'Only when page analytics is enabled: a record of each page visited, sent on every page view rather than only when something goes wrong.';
$string['privacy:metadata:guardlms:scrolldepth'] = 'Only when page analytics is enabled: how far down each page was scrolled.';
$string['privacy:metadata:guardlms:ipaddress'] = 'The IP address the report is sent from. The plugin does not include it in the report, but the receiving server sees it as part of the connection.';
