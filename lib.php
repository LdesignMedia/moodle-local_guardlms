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

// The real-time monitoring settings callback deliberately does NOT live here.
// admin_setting::write_setting() guards its updated callback with is_callable()
// and skips it silently when the function is not loaded, and this file is only
// included for plugins that declare before_session_start or after_config. See
// \local_guardlms\task\refresh_sdk_config::queue_if_connected().
