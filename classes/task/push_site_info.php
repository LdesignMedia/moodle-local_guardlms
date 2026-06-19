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
 * Scheduled task that pushes the site reporting payload to GuardLMS.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\task;

use core\task\scheduled_task;
use local_guardlms\local\collector;

/**
 * Daily push of the Moodle, server and PHP inventory to the GuardLMS endpoint.
 */
class push_site_info extends scheduled_task {
    /**
     * Human readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:pushsiteinfo', 'local_guardlms');
    }

    /**
     * Build the payload and POST it to GuardLMS.
     */
    public function execute(): void {
        global $CFG;

        if (!get_config('local_guardlms', 'enabled')) {
            mtrace('GuardLMS push disabled, skipping.');
            return;
        }

        $baseurl = trim((string) get_config('local_guardlms', 'baseurl'));
        $apikey = trim((string) get_config('local_guardlms', 'apikey'));
        if ($baseurl === '' || $apikey === '') {
            mtrace('GuardLMS base URL or API key not configured, skipping.');
            return;
        }

        $pushpath = trim((string) get_config('local_guardlms', 'pushpath'));
        if ($pushpath === '') {
            $pushpath = '/api/externalpush/moodle';
        }
        $endpoint = rtrim($baseurl, '/') . '/' . ltrim($pushpath, '/');

        $includeconfig = (bool) get_config('local_guardlms', 'sendconfig');
        $payload = collector::build_payload($includeconfig);

        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $apikey,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        $response = $curl->post($endpoint, json_encode($payload), [
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);
        $errno = $curl->get_errno();

        if ($errno) {
            throw new \moodle_exception('error:pushfailed', 'local_guardlms', '', $curl->error);
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            throw new \moodle_exception('error:pushhttp', 'local_guardlms', '', $httpcode, $response);
        }

        mtrace('GuardLMS push succeeded (HTTP ' . $httpcode . ').');
    }
}
