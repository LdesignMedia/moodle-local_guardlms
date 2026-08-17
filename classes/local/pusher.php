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
 * Shared push logic used by the scheduled and ad-hoc push tasks.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\local;

/**
 * Builds the payload and POSTs it to the configured GuardLMS endpoint.
 */
class pusher {
    /**
     * Push the site inventory to GuardLMS.
     *
     * @param bool $refreshupdates Allow the collector to refresh Moodle's
     *                             update-check data before reporting it. Cron
     *                             paths pass true; the connect callback runs in
     *                             a web request and must not block on an
     *                             outbound fetch to download.moodle.org.
     * @return string Human readable success message for mtrace.
     * @throws \moodle_exception When not configured or the push is rejected.
     */
    public static function push(bool $refreshupdates = false): string {
        global $CFG;

        $apikey = trim((string) get_config('local_guardlms', 'apikey'));
        if ($apikey === '') {
            throw new \moodle_exception('error:notconfigured', 'local_guardlms');
        }

        // The base URL and push path fall back to their defaults in config, so a
        // site that never touched the advanced settings still pushes to GuardLMS.
        $endpoint = config::pushendpoint();

        $includeconfig = (bool) get_config('local_guardlms', 'sendconfig');
        $payload = collector::build_payload($includeconfig, $refreshupdates);

        require_once($CFG->libdir . '/filelib.php');

        // ignoresecurity: the push only ever targets the admin-configured GuardLMS
        // base URL (trusted first-party), so Moodle's cURL SSRF blocklist must not
        // reject it. See the same note in api_client::exchange().
        $curl = new \curl(['ignoresecurity' => true]);
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

        if ($curl->get_errno()) {
            throw new \moodle_exception('error:pushfailed', 'local_guardlms', '', $curl->error);
        }

        // A refused key is a connection problem, not a push problem. Reporting
        // it as "GuardLMS rejected the push with HTTP status 401" sends an admin
        // looking at the push settings, which is the one thing that cannot fix
        // it, so name the actual cause and the actual remedy.
        if (connect_manager::is_rejected_status($httpcode)) {
            connect_manager::note_auth_rejected();

            throw new \moodle_exception('error:pushrejected', 'local_guardlms', '', $httpcode, $response);
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            throw new \moodle_exception('error:pushhttp', 'local_guardlms', '', $httpcode, $response);
        }

        set_config('lastpush', time(), 'local_guardlms');
        set_config('lastpushstatus', $httpcode, 'local_guardlms');

        // An accepted push proves the key is live again, whatever an earlier
        // run concluded.
        connect_manager::note_auth_accepted();

        return 'GuardLMS push succeeded (HTTP ' . $httpcode . ').';
    }
}
