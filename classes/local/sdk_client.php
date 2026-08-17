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
 * HTTP client for the GuardLMS SDK key endpoint.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\local;

/**
 * Fetches, rotates and revokes this site's SDK key.
 *
 * Authenticated with the site-bound push key the connect flow already stored,
 * so the admin never sees or pastes an SDK credential.
 */
class sdk_client {
    /** @var string Path of the SDK key endpoint on the GuardLMS host. */
    public const PATH = '/api/integrations/sdk-key';

    /** @var string Platform identifier sent with every request. */
    public const PLATFORM = 'moodle';

    /** @var int Default request timeout in seconds, used by cron and the adhoc task. */
    public const DEFAULT_TIMEOUT = 30;

    /** @var int Request timeout for the synchronous settings-page bootstrap. */
    public const BOOTSTRAP_TIMEOUT = 5;

    /** @var string Base URL of the GuardLMS instance, without trailing slash. */
    protected string $baseurl;

    /**
     * Constructor.
     *
     * @param string|null $baseurl GuardLMS base URL, defaulting to the configured one.
     */
    public function __construct(?string $baseurl = null) {
        $this->baseurl = rtrim(trim($baseurl ?? config::baseurl()), '/');
    }

    /**
     * Run an SDK key action and fold the outcome into stored config.
     *
     * Never throws: a failed refresh is a state the settings page renders, not
     * an exception that breaks a page load or an adhoc task.
     *
     * @param string $action One of fetch, rotate, revoke.
     * @param int $timeout Request timeout in seconds.
     * @param self|null $client Client override for tests.
     * @return bool True when the backend answered with a usable payload.
     */
    public static function resolve(
        string $action,
        int $timeout = self::DEFAULT_TIMEOUT,
        ?self $client = null
    ): bool {
        global $CFG;

        $apikey = trim((string) get_config(sdk_config::COMPONENT, 'apikey'));
        if ($apikey === '') {
            // Not connected: there is no credential to authenticate with and
            // nothing to report. Not a refresh failure.
            return false;
        }

        $client = $client ?? new self();

        try {
            $result = $client->request($action, $CFG->wwwroot, $apikey, $timeout);
        } catch (\Throwable $e) {
            sdk_config::record_refresh_error($e->getMessage());

            return false;
        }

        // 404/405 mean this GuardLMS predates the endpoint. A quiet no-op:
        // there is nothing the admin can do and nothing is broken (§5.3 row 2).
        if ($result['status'] === 404 || $result['status'] === 405) {
            sdk_config::record_backend_unsupported();

            return false;
        }

        // A refused key is a connection problem, not a real-time problem.
        // Reporting it as "HTTP 401" here sends the admin looking at the
        // real-time settings, which is the one thing that cannot fix it, so
        // record it as the connection state it actually is.
        if (connect_manager::is_rejected_status($result['status'])) {
            connect_manager::note_auth_rejected();
            sdk_config::record_refresh_error(
                get_string('error:pushrejected', sdk_config::COMPONENT, $result['status'])
            );

            return false;
        }

        if ($result['status'] < 200 || $result['status'] >= 300) {
            sdk_config::record_refresh_error(self::failure_message($result));

            return false;
        }

        $decoded = json_decode($result['body'], true);
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        if (!is_array($data)) {
            sdk_config::record_refresh_error(
                get_string('error:sdkrefreshfailed', sdk_config::COMPONENT, 'malformed response body')
            );

            return false;
        }

        // The key just authenticated, so clear any refusal an earlier call
        // recorded - a reconnect elsewhere, or a backend that has come back,
        // must not leave the settings page stuck on "Reconnect required".
        connect_manager::note_auth_accepted();

        if ($action === 'revoke') {
            // Nothing to store: the caller is tearing the connection down.
            return true;
        }

        sdk_config::store_payload($data);

        return true;
    }

    /**
     * Issue the request.
     *
     * Separated from resolve() so tests can substitute a transport without a
     * network, and kept protected so nothing outside this class talks HTTP.
     *
     * @param string $action One of fetch, rotate, revoke.
     * @param string $siteurl This site's wwwroot, which the backend matches against the website record.
     * @param string $apikey The site-bound push key from the connect flow.
     * @param int $timeout Request timeout in seconds.
     * @return array{status: int, body: string}
     * @throws \moodle_exception On a transport failure.
     */
    protected function request(string $action, string $siteurl, string $apikey, int $timeout): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        // ignoresecurity: this call only ever targets the admin-configured
        // GuardLMS base URL (a trusted first-party endpoint), so it must not be
        // rejected by Moodle's cURL SSRF blocklist. Without this, a site whose
        // GuardLMS host resolves to a blocked range (e.g. a self-hosted GuardLMS
        // on a private network, or any site with hardened curlsecurityblockedhosts)
        // would never be able to refresh its SDK key.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader([
            'Authorization: Bearer ' . $apikey,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        $response = $curl->post($this->baseurl . self::PATH, json_encode([
            'siteurl' => $siteurl,
            'platform' => self::PLATFORM,
            'action' => $action,
        ]), [
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => min($timeout, 10),
        ]);

        if ($curl->get_errno()) {
            throw new \moodle_exception(
                'error:sdkrefreshfailed',
                sdk_config::COMPONENT,
                '',
                $curl->error
            );
        }

        $info = $curl->get_info();

        return [
            'status' => (int) ($info['http_code'] ?? 0),
            'body' => (string) $response,
        ];
    }

    /**
     * Turn a non-2xx response into a sentence an admin can act on.
     *
     * @param array $result Result of request().
     * @return string
     */
    protected static function failure_message(array $result): string {
        $decoded = json_decode($result['body'], true);
        $reason = is_array($decoded) && !empty($decoded['message'])
            ? (string) $decoded['message']
            : 'HTTP ' . $result['status'];

        return get_string('error:sdkrefreshfailed', sdk_config::COMPONENT, $reason);
    }
}
