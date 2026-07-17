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
 * Minimal HTTP client for the GuardLMS connect API.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\local;

/**
 * Talks to the GuardLMS platform during the keyless connect flow.
 */
class api_client {
    /** @var string Base URL of the GuardLMS instance, without trailing slash. */
    protected string $baseurl;

    /**
     * Constructor.
     *
     * @param string $baseurl GuardLMS base URL.
     */
    public function __construct(string $baseurl) {
        $this->baseurl = rtrim(trim($baseurl), '/');
    }

    /**
     * Exchange a one-time connect code for the site-bound push key.
     *
     * @param string $code One-time code delivered via the callback redirect.
     * @param string $siteurl This site's wwwroot.
     * @param string $state The state value the plugin generated for this attempt.
     * @return array Decoded response data: token, siteurl, pushpath, verification_token, website_id, expires_at.
     * @throws \moodle_exception When GuardLMS rejects the exchange.
     */
    public function exchange(string $code, string $siteurl, string $state): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        $response = $curl->post($this->baseurl . '/api/integrations/exchange', json_encode([
            'code' => $code,
            'siteurl' => $siteurl,
            'state' => $state,
        ]), [
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);

        if ($curl->get_errno()) {
            throw new \moodle_exception('error:connectfailed', 'local_guardlms', '', $curl->error);
        }

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);
        $decoded = json_decode((string) $response, true);

        if ($httpcode < 200 || $httpcode >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) && !empty($decoded['message'])
                ? $decoded['message']
                : 'HTTP ' . $httpcode;
            throw new \moodle_exception('error:connectrejected', 'local_guardlms', '', $message);
        }

        $data = $decoded['data'] ?? [];
        if (empty($data['token'])) {
            throw new \moodle_exception('error:connectrejected', 'local_guardlms', '', 'missing token in response');
        }

        return $data;
    }
}
