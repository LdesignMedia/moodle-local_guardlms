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
 * Connection defaults for local_guardlms.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\local;

/**
 * Single source for the GuardLMS endpoint an admin never has to think about.
 *
 * The base URL and push path are not shown on the plugin settings page. They are
 * only editable in advanced mode (settings.php?section=local_guardlms&mode=advanced)
 * and can be pinned for good in config.php with:
 *
 *     $CFG->forced_plugin_settings['local_guardlms']['baseurl'] = 'https://guardlms.example.com';
 */
class config {
    /** @var string GuardLMS service the plugin talks to unless an admin overrides it. */
    public const DEFAULT_BASEURL = 'https://dashboard.guardlms.com';

    /**
     * @var string Base URL shipped as the default before 1.5.2. The host was never
     * provisioned in production; stored configs pointing at it are rewritten to
     * DEFAULT_BASEURL by the 2026082100 upgrade step.
     */
    public const LEGACY_BASEURL = 'https://app.guardlms.com';

    /** @var string Inventory push endpoint on the GuardLMS host. */
    public const DEFAULT_PUSHPATH = '/api/externalpush/moodle';

    /**
     * The GuardLMS base URL, without a trailing slash.
     *
     * @return string
     */
    public static function baseurl(): string {
        $baseurl = trim((string) get_config('local_guardlms', 'baseurl'));
        if ($baseurl === '') {
            $baseurl = self::DEFAULT_BASEURL;
        }

        return rtrim($baseurl, '/');
    }

    /**
     * The inventory push path, with a leading slash.
     *
     * @return string
     */
    public static function pushpath(): string {
        $pushpath = trim((string) get_config('local_guardlms', 'pushpath'));
        if ($pushpath === '') {
            $pushpath = self::DEFAULT_PUSHPATH;
        }

        return '/' . ltrim($pushpath, '/');
    }

    /**
     * The absolute inventory push endpoint.
     *
     * @return string
     */
    public static function pushendpoint(): string {
        return self::baseurl() . self::pushpath();
    }

    /**
     * The site URL to register with, and report to, GuardLMS.
     *
     * Defaults to this site's wwwroot. Admins on cloned, proxied or
     * multi-hostname sites can pin an explicit value via the siteurloverride
     * setting so the URL registered during connect always matches the URL sent
     * by the daily push, avoiding the "siteurl does not match" rejection.
     *
     * @return string Non-empty site URL, trailing slash trimmed.
     */
    public static function siteurl(): string {
        global $CFG;

        $override = trim((string) get_config('local_guardlms', 'siteurloverride'));

        return rtrim($override !== '' ? $override : (string) $CFG->wwwroot, '/');
    }
}
