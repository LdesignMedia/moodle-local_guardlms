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
 * Test double for the GuardLMS SDK key client.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms;

use local_guardlms\local\sdk_client;

/**
 * An sdk_client that answers from a canned response instead of the network.
 *
 * Records every call so a test can assert not only what came back but that the
 * request was made at all, and with which credential.
 */
class testable_sdk_client extends sdk_client {
    /** @var array[] Every request() call, in order. */
    public array $calls = [];

    /** @var int HTTP status to answer with. */
    protected int $status;

    /** @var string Response body to answer with. */
    protected string $body;

    /** @var bool Whether to simulate a transport failure instead of answering. */
    protected bool $failtransport;

    /**
     * Constructor.
     *
     * @param int $status HTTP status to answer with.
     * @param string $body Response body to answer with.
     * @param bool $failtransport Throw a transport failure instead of answering.
     */
    public function __construct(int $status = 200, string $body = '{"data":{}}', bool $failtransport = false) {
        parent::__construct('https://guardlms.test');
        $this->status = $status;
        $this->body = $body;
        $this->failtransport = $failtransport;
    }

    /**
     * Record the call and answer from the canned response.
     *
     * @param string $action One of fetch, rotate, revoke.
     * @param string $siteurl This site's wwwroot.
     * @param string $apikey The push key used to authenticate.
     * @param int $timeout Request timeout in seconds.
     * @return array{status: int, body: string}
     * @throws \moodle_exception When configured to fail.
     */
    protected function request(string $action, string $siteurl, string $apikey, int $timeout): array {
        $this->calls[] = [
            'action' => $action,
            'siteurl' => $siteurl,
            'apikey' => $apikey,
            'timeout' => $timeout,
        ];

        if ($this->failtransport) {
            throw new \moodle_exception('error:sdkrefreshfailed', 'local_guardlms', '', 'connection refused');
        }

        return ['status' => $this->status, 'body' => $this->body];
    }
}
