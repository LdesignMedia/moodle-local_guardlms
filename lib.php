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
 * Inject the GuardLMS head content into the page head.
 *
 * This is the Moodle 3.9 to 4.3 path: those releases have no Hooks API, so the
 * ownership verification meta tag and the real-time monitoring SDK are both
 * emitted from here. From 4.4 the before_standard_head_html_generation hook
 * registered in db/hooks.php takes over, and this callback returns an empty
 * string there so the tags are never emitted twice.
 *
 * @return string
 */
function local_guardlms_before_standard_html_head(): string {
    \local_guardlms\local\server_errors::observe_rendered_exception();
    // Core skips this callback for plugins that register the deprecating hook,
    // but the guard stays explicit rather than relying on that behaviour.
    return \local_guardlms\local\head_injector::legacy_head_html(
        class_exists(\core\hook\output\before_standard_head_html_generation::class)
    );
}

// Settings callbacks use autoloadable classes so they also work when lib.php is
// not loaded (for example, on releases using the after_config hook).

/**
 * Start opt-in server reporting after Moodle has loaded configuration.
 */
function local_guardlms_after_config(): void {
    if (class_exists(\core\hook\after_config::class)) {
        return;
    }
    \local_guardlms\local\server_errors::start();
}
