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
 * Privacy provider for local_guardlms.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as request_provider;
use core_privacy\local\request\userlist;

/**
 * Declares the data this plugin causes a user's browser to send to GuardLMS.
 *
 * The plugin stores no personal data in any Moodle table, which is why every
 * request method below is a no-op. It is nonetheless not a null_provider: with
 * real-time monitoring enabled it injects third-party JavaScript that makes a
 * logged-in user's browser transmit the page URL, the referrer, the user agent,
 * viewport and session identifiers and error stack traces to GuardLMS. Under
 * GDPR those are online identifiers plus behavioural data, so the transfer has
 * to be declared even though nothing is retained locally.
 *
 * What is deliberately NOT sent bounds that declaration: setUser() is never
 * called, so no name, email or user id leaves the site; interaction
 * breadcrumbs are off, so no click or form selectors are collected; and
 * collectUserIp is false. See head_injector::sdk_init_config().
 */
class provider implements core_userlist_provider, metadata_provider, request_provider {
    /**
     * Describe the data transmitted to GuardLMS.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        // The ipaddress field is declared even though collectUserIp is false: the
        // receiving server observes the source IP at the transport layer
        // regardless, and declaring what the third party can see is the honest
        // reading of the obligation.
        // pageviews and scrolldepth are declared because the optional analytics
        // block changes what is sent and when: with it enabled the SDK reports
        // on EVERY page view, not only when an error occurs. Declaring only the
        // error fields would understate the plugin to a data protection
        // officer - the difference between occasional fault reports and
        // continuous behavioural telemetry.
        return $collection->add_external_location_link('guardlms', [
            'pageurl' => 'privacy:metadata:guardlms:pageurl',
            'referrerurl' => 'privacy:metadata:guardlms:referrerurl',
            'useragent' => 'privacy:metadata:guardlms:useragent',
            'sessionid' => 'privacy:metadata:guardlms:sessionid',
            'errordetails' => 'privacy:metadata:guardlms:errordetails',
            'pageviews' => 'privacy:metadata:guardlms:pageviews',
            'scrolldepth' => 'privacy:metadata:guardlms:scrolldepth',
            'ipaddress' => 'privacy:metadata:guardlms:ipaddress',
        ], 'privacy:metadata:guardlms');
    }

    /**
     * Contexts holding data for a user: none, nothing is stored in Moodle.
     *
     * @param int $userid The user to search for.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
    }

    /**
     * Users with data in a context: none, nothing is stored in Moodle.
     *
     * @param userlist $userlist The userlist to add users to.
     */
    public static function get_users_in_context(userlist $userlist): void {
        // Intentionally empty: this plugin writes no user rows.
    }

    /**
     * Export a user's data: nothing to export.
     *
     * The data described by get_metadata() lives on GuardLMS, not in Moodle,
     * and the plugin has no API to retrieve it per user. That is a direct
     * consequence of never calling setUser(): the telemetry is never associated
     * with a Moodle user identity in the first place.
     *
     * @param approved_contextlist $contextlist Approved contexts to export.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        // Intentionally empty: this plugin stores no user data in Moodle.
    }

    /**
     * Delete all data in a context: nothing to delete.
     *
     * @param \context $context The context to delete in.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        // Intentionally empty: this plugin stores no user data in Moodle.
    }

    /**
     * Delete a user's data: nothing to delete.
     *
     * @param approved_contextlist $contextlist Approved contexts to delete in.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        // Intentionally empty: this plugin stores no user data in Moodle.
    }

    /**
     * Delete data for several users in a context: nothing to delete.
     *
     * @param approved_userlist $userlist Approved users to delete.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        // Intentionally empty: this plugin stores no user data in Moodle.
    }
}
