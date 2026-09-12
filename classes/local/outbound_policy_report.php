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
 * Offline cURL IP-policy evaluation using Moodle's own parser.
 *
 * @package local_guardlms
 * @copyright 2026 Luuk Verhoeven, ldesignmedia.nl
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_guardlms\local;

/**
 * Evaluates address rules only: no DNS lookups, HTTP fetches or metadata reads.
 */
class outbound_policy_report extends \core\files\curl_security_helper {
    /**
     * Read a bounded diagnostic snapshot without exporting arbitrary configuration strings.
     *
     * @return array
     */
    public function inspect(): array {
        global $DB;
        $result = ['url_repository_enabled' => $DB->record_exists('repository', ['type' => 'url', 'visible' => 1])];
        $samples = [
            'metadata_ip_blocked' => ['169.254.169.254'],
            'loopback_samples_blocked' => ['127.0.0.1', '127.0.0.2', '::1'],
            'private_samples_blocked' => ['10.0.0.1', '172.16.0.1', '172.31.255.254', '192.168.0.1'],
            'ipv6_local_samples_blocked' => ['fc00::1', 'fd00::1', 'fe80::1'],
        ];
        foreach ($samples as $key => $addresses) {
            $result[$key] = true;
            foreach ($addresses as $address) {
                if (!$this->address_explicitly_blocked($address)) {
                    $result[$key] = false;
                }
            }
        }
        $ports = $this->get_allowed_ports();
        $validports = array_filter($ports, function ($port) {
            return ctype_digit($port) && (int) $port > 0 && (int) $port <= 65535;
        });
        if (count($validports) === count($ports)) {
            $result['non_http_ports_allowed'] = !$ports || (bool) array_diff(array_map('intval', $ports), [80, 443]);
        }
        $result['blocked_ip_rules'] = [];
        $result['omitted_rule_count'] = 0;
        foreach ($this->get_blocked_hosts() as $rule) {
            // IP addresses and CIDRs are explicitly permitted telemetry. Keep names/other strings local.
            $parts = explode('/', $rule);
            $valid = filter_var($parts[0], FILTER_VALIDATE_IP) !== false;
            if (count($parts) > 1) {
                $max = strpos($parts[0], ':') === false ? 32 : 128;
                $valid = $valid && count($parts) === 2 && ctype_digit($parts[1]) && (int) $parts[1] <= $max;
            }
            if ($valid && count($result['blocked_ip_rules']) < 200) {
                $result['blocked_ip_rules'][] = $rule;
            } else {
                $result['omitted_rule_count']++;
            }
        }
        return $result;
    }
}
