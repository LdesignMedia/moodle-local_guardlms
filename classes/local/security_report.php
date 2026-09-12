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
 * The Moodle security report, flattened for the inventory push.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\local;

/**
 * Runs the checks behind Site administration > Reports > Security and
 * reports each verdict, so GuardLMS can audit the site without an admin
 * opening the report.
 *
 * The checks come from \core\check\manager (Moodle 3.9 and later), which is
 * also what the report page itself renders, so the verdicts are exactly the
 * ones an admin would see there, with only recognised check identifiers exported.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class security_report {
    /**
     * Run every security check and describe its outcome.
     *
     * A check that throws is reported as 'unknown' without exception text
     * rather than aborting the whole push: one broken check must not hide the
     * other verdicts.
     *
     * @return array List of ['ref', 'component', 'name', 'status', 'summary', 'details'].
     */
    public static function collect(): array {
        if (!class_exists(\core\check\manager::class)) {
            return [];
        }

        $checks = [];
        try {
            $available = \core\check\manager::get_checks('security');
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($available as $check) {
            $entry = self::describe($check);
            if ($entry) {
                $checks[] = $entry;
            }
        }

        return $checks;
    }

    /**
     * Describe one check and its result.
     *
     * @param \core\check\check $check The check to run.
     * @return array
     */
    protected static function describe(\core\check\check $check): array {
        $ref = $check->get_ref();
        $names = [
            'core_displayerrors' => 'Display errors', 'core_unsecuredataroot' => 'Data directory protection',
            'core_publicpaths' => 'Public paths', 'core_configrw' => 'Configuration file permissions',
            'core_preventexecpath' => 'Executable paths', 'core_embed' => 'Embedded content',
            'core_openprofiles' => 'Public profiles', 'core_crawlers' => 'Search engine access',
            'core_passwordpolicy' => 'Password policy', 'core_emailchangeconfirmation' => 'Email change confirmation',
            'core_webcron' => 'Web cron access', 'core_cookiesecure' => 'Secure cookies',
            'core_riskadmin' => 'Administrator privileges', 'core_riskxss' => 'Trusted content privileges',
            'core_riskbackup' => 'Backup privileges', 'core_defaultuserrole' => 'Default authenticated user role',
            'core_guestrole' => 'Guest role', 'core_frontpagerole' => 'Front page role',
            'auth_none_noauth' => 'No authentication enabled',
        ];
        // Unknown extensions may put personal information even in their ref or name.
        if (!isset($names[$ref])) {
            return [];
        }
        $entry = ['ref' => $ref, 'component' => $ref === 'auth_none_noauth' ? 'auth_none' : 'core',
            'name' => $names[$ref], 'status' => 'unknown', 'summary' => '', 'details' => ''];
        try {
            $status = $check->get_result()->get_status();
            if (in_array($status, ['ok', 'info', 'unknown', 'warning', 'error', 'critical', 'na'], true)) {
                $entry['status'] = $status;
            }
        } catch (\Throwable $e) {
            // Never transmit exception messages, rendered summaries or details.
            $entry['status'] = 'unknown';
        }
        return $entry;
    }
}
