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

namespace local_guardlms;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\external_location;
use local_guardlms\privacy\provider;

/**
 * Tests for the privacy provider.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_guardlms\privacy\provider
 */
final class privacy_provider_test extends \advanced_testcase {
    /**
     * The single external location this plugin declares.
     *
     * @return external_location
     */
    private function external_location(): external_location {
        $items = provider::get_metadata(new collection('local_guardlms'))->get_collection();

        $this->assertCount(1, $items, 'The plugin declares exactly one external location and nothing else.');
        $this->assertInstanceOf(external_location::class, $items[0]);

        return $items[0];
    }

    /**
     * E8: the transfer to GuardLMS is declared as an external location link.
     */
    public function test_metadata_declares_the_external_transfer(): void {
        $this->assertSame('guardlms', $this->external_location()->get_name());
    }

    /**
     * E8: every declared field resolves to a lang string.
     *
     * This is what the core privacy suite enforces; asserting it here means a
     * field added without its string fails in this plugin's own suite rather
     * than in core's.
     */
    public function test_every_declared_field_resolves_to_a_lang_string(): void {
        $location = $this->external_location();

        $fields = $location->get_privacy_fields();
        $this->assertNotEmpty($fields);

        foreach ($fields as $field => $stringid) {
            $this->assertTrue(
                get_string_manager()->string_exists($stringid, 'local_guardlms'),
                "The declared field {$field} has no lang string {$stringid}."
            );
            $this->assertNotSame('', trim(get_string($stringid, 'local_guardlms')));
        }

        $summary = $location->get_summary();
        $this->assertTrue(
            get_string_manager()->string_exists($summary, 'local_guardlms'),
            "The summary string {$summary} is missing."
        );
    }

    /**
     * The declared fields are the ones the SDK actually causes to be sent.
     */
    public function test_declared_fields_cover_what_the_sdk_transmits(): void {
        $fields = array_keys($this->external_location()->get_privacy_fields());

        sort($fields);
        $this->assertSame([
            'errordetails',
            'ipaddress',
            'pageurl',
            'referrerurl',
            'sessionid',
            'useragent',
        ], $fields);
    }

    /**
     * The plugin is no longer a null provider.
     *
     * It stores nothing locally, but it causes a third-party transfer, and a
     * null_provider would tell a data protection officer the opposite.
     */
    public function test_provider_is_not_a_null_provider(): void {
        $this->assertFalse(
            is_subclass_of(provider::class, \core_privacy\local\metadata\null_provider::class),
            'A null provider would understate what this plugin causes a browser to send.'
        );
        $this->assertInstanceOf(\core_privacy\local\metadata\provider::class, new provider());
        $this->assertInstanceOf(\core_privacy\local\request\plugin\provider::class, new provider());
        $this->assertInstanceOf(\core_privacy\local\request\core_userlist_provider::class, new provider());
    }

    /**
     * Nothing is stored in Moodle, so there are no contexts and no users.
     */
    public function test_no_contexts_and_no_users_are_reported(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();

        $this->assertCount(0, provider::get_contexts_for_userid((int) $user->id));

        $userlist = new \core_privacy\local\request\userlist(\context_system::instance(), 'local_guardlms');
        provider::get_users_in_context($userlist);
        $this->assertCount(0, $userlist);
    }

    /**
     * The delete and export paths complete without touching anything.
     */
    public function test_delete_and_export_are_safe_no_ops(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();

        // A connected, monitored site: the state a real deletion request would
        // arrive at. None of it is user data, so none of it may be touched.
        set_config('apikey', 'push-key', 'local_guardlms');
        set_config('sdkkey', 'glms_' . str_repeat('c', 56), 'local_guardlms');
        set_config('sdkenabled', 1, 'local_guardlms');

        $contextlist = new \core_privacy\local\request\approved_contextlist(
            $user,
            'local_guardlms',
            [$context->id]
        );

        provider::export_user_data($contextlist);
        provider::delete_data_for_user($contextlist);
        provider::delete_data_for_all_users_in_context($context);
        provider::delete_data_for_users(
            new \core_privacy\local\request\approved_userlist($context, 'local_guardlms', [$user->id])
        );

        // Every entry point is callable with the shapes core passes, and a
        // future implementation that started deleting site configuration in
        // response to a user request would fail here.
        $this->assertSame('push-key', get_config('local_guardlms', 'apikey'));
        $this->assertSame('glms_' . str_repeat('c', 56), get_config('local_guardlms', 'sdkkey'));
        $this->assertSame('1', get_config('local_guardlms', 'sdkenabled'));
        $this->assertTrue(\core_user::get_user($user->id) !== false, 'The user must survive a no-op deletion.');
    }
}
