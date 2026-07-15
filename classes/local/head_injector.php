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
 * Builds the GuardLMS ownership verification meta tag for the page head.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\local;

/**
 * Emits the guardlms-verification meta tag while the site is connected.
 */
class head_injector {
    /**
     * Build the meta tag HTML, or an empty string when not applicable.
     *
     * @return string
     */
    public static function meta_tag(): string {
        if (!get_config('local_guardlms', 'enabled')) {
            return '';
        }

        $token = trim((string) get_config('local_guardlms', 'verificationtoken'));
        if ($token === '') {
            return '';
        }

        return '<meta name="guardlms-verification" content="' . s($token) . '">' . "\n";
    }
}
