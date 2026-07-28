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
 * Hook callbacks for local_guardlms (Moodle 4.4+).
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms;

use local_guardlms\local\head_injector;

/**
 * Callback implementations for core output hooks.
 */
class hook_callbacks {
    /**
     * Add the GuardLMS head content: ownership meta tag and real-time SDK tags.
     *
     * Both are appended from this one callback rather than a second hook
     * registration. They land in the same place in the same order, and a
     * separate registration would only add a second dispatch and a second
     * chance for the two to drift apart.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook The hook instance.
     */
    public static function before_standard_head_html_generation(
        \core\hook\output\before_standard_head_html_generation $hook
    ): void {
        $tag = head_injector::meta_tag();
        $sdktag = head_injector::sdk_tags();
        if ($tag !== '' || $sdktag !== '') {
            $hook->add_html($tag . $sdktag);
        }
    }
}
