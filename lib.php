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
 * Legacy callbacks for local_guardlms.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Inject the GuardLMS ownership verification meta tag into the page head.
 *
 * Used on Moodle <= 4.3; from 4.4 the before_standard_head_html_generation
 * hook in classes/hook_callbacks.php takes over (this callback returns an
 * empty string there to avoid emitting the tag twice).
 *
 * @return string
 */
function local_guardlms_before_standard_html_head(): string {
    if (class_exists(\core\hook\output\before_standard_head_html_generation::class)) {
        return '';
    }

    // Only the meta tag. The SDK is deliberately not injected on Moodle below
    // 4.4: this legacy callback was removed in 4.4, so a site old enough to
    // reach it is a site the real-time feature does not support, and the
    // settings page says so rather than pretending the toggle worked.
    return \local_guardlms\local\head_injector::meta_tag();
}

/**
 * Queue an SDK configuration refresh after a real-time monitoring setting changed.
 *
 * Registered with set_updatedcallback() on both toggles. The refresh is queued
 * rather than performed inline so a slow or unreachable GuardLMS can never
 * block a settings save.
 *
 * @param string $name The setting that changed, unused: both toggles want the same refresh.
 */
function local_guardlms_sdk_setting_updated(string $name): void {
    if (!\local_guardlms\local\connect_manager::is_connected()) {
        return;
    }

    \local_guardlms\task\refresh_sdk_config::queue();
}
