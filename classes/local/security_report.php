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
 * ones an admin would see there, including checks contributed by plugins.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class security_report {
    /** @var int Longest summary reported per check. */
    public const MAX_SUMMARY = 500;

    /** @var int Longest details text reported per check. */
    public const MAX_DETAILS = 2000;

    /**
     * @var string[] Core checks whose details list people rather than settings.
     *
     * riskadmin prints every administrator's name and email, riskxss every
     * user holding an XSS-risk capability, riskbackup the roles that may
     * back up user data. The verdict and summary carry the audit signal
     * ("found 3 administrators"); the names are personal data the push has
     * no business carrying, so details are withheld for these.
     */
    public const USER_LISTING_CHECKS = ['core_riskadmin', 'core_riskxss', 'core_riskbackup'];

    /**
     * Run every security check and describe its outcome.
     *
     * A check that throws is reported as 'unknown' with the exception message
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
        foreach (\core\check\manager::get_checks('security') as $check) {
            $checks[] = self::describe($check);
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
        $entry = [
            'ref' => $check->get_ref(),
            'component' => $check->get_component(),
            'name' => self::text((string) $check->get_name(), self::MAX_SUMMARY),
            'status' => \core\check\result::UNKNOWN,
            'summary' => '',
            'details' => '',
        ];

        try {
            $result = $check->get_result();
            $entry['status'] = $result->get_status();
            $entry['summary'] = self::text($result->get_summary(), self::MAX_SUMMARY);
            if (self::details_allowed($check)) {
                $entry['details'] = self::text($result->get_details(), self::MAX_DETAILS);
            }
        } catch (\Throwable $e) {
            $entry['summary'] = self::text($e->getMessage(), self::MAX_SUMMARY);
        }

        return $entry;
    }

    /**
     * Whether a check's details are known to describe settings, not people.
     *
     * Core checks are read one by one (see USER_LISTING_CHECKS). A check
     * contributed by a plugin is unknown territory, so only its summary and
     * verdict travel.
     *
     * @param \core\check\check $check The check.
     * @return bool
     */
    public static function details_allowed(\core\check\check $check): bool {
        return $check->get_component() === 'core'
            && !in_array($check->get_ref(), self::USER_LISTING_CHECKS, true);
    }

    /**
     * Reduce the report's HTML to plain, bounded text.
     *
     * @param string $html Text as the check rendered it.
     * @param int $max Longest text to keep.
     * @return string
     */
    protected static function text(string $html, int $max): string {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return \core_text::substr($text, 0, $max);
    }
}
