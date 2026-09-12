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
 * Privacy-preserving hardening observations. No individual records leave Moodle.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_guardlms\local;

/**
 * Collects fixed booleans and aggregate counts, never arbitrary configuration or records.
 */
class hardening_report {
    /**
     * Each independently observed section fails closed to unknown, not zero.
     *
     * @return array Bounded, versioned observations.
     */
    public static function collect(): array {
        $result = ['version' => 1];
        foreach (['deployment', 'tokens', 'enrolments', 'tasks', 'mfa', 'antivirus', 'ssrf', 'filesystem'] as $section) {
            try {
                $metrics = self::$section();
                $result[$section] = ['status' => $metrics === null ? 'unsupported' : 'observed',
                    'observed_at' => time()] + ($metrics ?? []);
            } catch (\Throwable $e) {
                // Exceptions can contain SQL, identifiers, paths and credentials.
                $result[$section] = ['status' => 'failed', 'observed_at' => time()];
            }
        }
        return $result;
    }

    /**
     * Effective deployment setting, including config.php overrides.
     *
     * @return array
     */
    private static function deployment(): array {
        global $CFG;
        return ['web_install_disabled' => !empty($CFG->disableupdateautodeploy)];
    }

    /**
     * Counts of unexpired persistent tokens for enabled services and active accounts.
     *
     * @return array
     */
    private static function tokens(): array {
        global $DB;
        $now = time();
        $where = "tokentype = 0 AND (validuntil = 0 OR validuntil > :now)
            AND userid IN (SELECT id FROM {user} WHERE deleted = 0 AND suspended = 0)
            AND externalserviceid IN (SELECT id FROM {external_services} WHERE enabled = 1)";
        $params = ['now' => $now];
        return [
            'active' => $DB->count_records_select('external_tokens', $where, $params),
            'nonexpiring' => $DB->count_records_select('external_tokens', $where . ' AND validuntil = 0', $params),
            'unrestricted' => $DB->count_records_select(
                'external_tokens',
                $where . " AND (iprestriction IS NULL OR iprestriction = '')",
                $params
            ),
            'longlived' => $DB->count_records_select(
                'external_tokens',
                $where . ' AND validuntil > :limit',
                $params + ['limit' => $now + 90 * DAYSECS]
            ),
        ];
    }

    /**
     * Counts of enabled instances in visible courses, not enrolment/user records.
     *
     * @return array
     */
    private static function enrolments(): array {
        global $DB;
        $where = 'status = 0 AND courseid IN (SELECT id FROM {course} WHERE visible = 1 AND id <> :siteid)';
        $params = ['siteid' => SITEID];
        $enabled = explode(',', (string) get_config('moodle', 'enrol_plugins_enabled'));
        $guest = $where . " AND enrol = 'guest'";
        // Self enrolment: new enrolments permitted, and within the configured enrolment window.
        $self = $where . " AND enrol = 'self' AND customint6 = 1
            AND (enrolstartdate = 0 OR enrolstartdate <= :startnow)
            AND (enrolenddate = 0 OR enrolenddate > :endnow)";
        $selfparams = $params + ['startnow' => time(), 'endnow' => time()];
        return [
            'guest_instances' => in_array('guest', $enabled, true)
                ? $DB->count_records_select('enrol', $guest, $params) : 0,
            'guest_without_key' => in_array('guest', $enabled, true)
                ? $DB->count_records_select('enrol', $guest . " AND (password IS NULL OR password = '')", $params) : 0,
            'self_instances' => in_array('self', $enabled, true)
                ? $DB->count_records_select('enrol', $self, $selfparams) : 0,
            'self_without_key' => in_array('self', $enabled, true)
                ? $DB->count_records_select('enrol', $self . " AND (password IS NULL OR password = '')", $selfparams) : 0,
        ];
    }

    /**
     * Aggregate retry state; intentional schedules are not treated as failures.
     *
     * @return array
     */
    private static function tasks(): array {
        global $DB;
        return [
            'scheduled_failing' => $DB->count_records_select('task_scheduled', 'disabled = 0 AND faildelay > 0'),
            'adhoc_failing' => $DB->count_records_select('task_adhoc', 'faildelay > 0'),
        ];
    }

    /**
     * Configuration only: never inspect user factors, secrets, roles or IP allowlists.
     *
     * @return array|null
     */
    private static function mfa(): ?array {
        if (!class_exists(\tool_mfa\plugininfo\factor::class)) {
            return null;
        }
        $enabled = get_config('tool_mfa', 'enabled');
        $factors = \tool_mfa\plugininfo\factor::get_enabled_factors();
        $fallback = false;
        foreach ($factors as $factor) {
            // No other factors at 100 permits password-only login for users without a configured factor.
            if ($factor->name === 'nosetup' && (int) $factor->get_weight() >= 100) {
                $fallback = true;
            }
        }
        return ['enabled' => !empty($enabled), 'enabled_factors' => count($factors),
            'password_only_fallback' => $fallback];
    }

    /**
     * Configuration presence is not proof that antivirus works.
     *
     * @return array|null
     */
    private static function antivirus(): ?array {
        $enabled = array_filter(explode(',', (string) get_config('moodle', 'antiviruses')));
        if (!in_array('clamav', $enabled, true)) {
            // Other engines may be valid but their configuration contract is unknown.
            return $enabled ? null : ['enabled' => false];
        }
        $method = get_config('antivirus_clamav', 'runningmethod');
        $key = ['commandline' => 'pathtoclam', 'unixsocket' => 'pathtounixsocket', 'tcpsocket' => 'tcpsockethost'];
        if (!isset($key[$method])) {
            return null;
        }
        $configured = trim((string) get_config('antivirus_clamav', $key[$method])) !== '';
        if ($method === 'tcpsocket') {
            $port = (int) get_config('antivirus_clamav', 'tcpsocketport');
            $configured = $configured && $port > 0 && $port <= 65535;
        }
        $result = ['enabled' => true, 'configuration_complete' => $configured];
        $failure = get_config('antivirus_clamav', 'clamfailureonupload');
        if (in_array($failure, ['donothing', 'actlikevirus', 'tryagain'], true)) {
            $result['fail_open'] = $failure === 'donothing';
        }
        return $result;
    }

    /**
     * Offline address-policy checks; never request cloud metadata or resolve hostnames.
     *
     * @return array|null
     */
    private static function ssrf(): ?array {
        if (!class_exists(\core\files\curl_security_helper::class)) {
            return null;
        }
        return (new outbound_policy_report())->inspect();
    }

    /**
     * Inspect the current process permissions; cron is not evidence of web-user permissions.
     *
     * @return array
     */
    private static function filesystem(): array {
        global $CFG;
        return self::inspect_filesystem($CFG->dirroot, $CFG->root ?? $CFG->dirroot, $CFG->dataroot);
    }

    /**
     * Read filesystem metadata only. No test files, contents, usernames or absolute paths are exported.
     *
     * @param string $webroot Moodle's public code directory.
     * @param string $coderoot Moodle's installation directory (different in Moodle 5.1+).
     * @param string $dataroot Moodle's data directory.
     * @return array
     */
    public static function inspect_filesystem(string $webroot, string $coderoot, string $dataroot): array {
        $result = ['runtime' => PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' ? 'cli' : 'web'];
        $webroot = realpath($webroot);
        $coderoot = realpath($coderoot);
        $dataroot = realpath($dataroot);
        if ($webroot !== false) {
            clearstatcache(true, $webroot);
            $result['webroot_writable'] = is_writable($webroot);
            if ($dataroot !== false) {
                $result['dataroot_in_webroot'] = $dataroot === $webroot
                    || strpos($dataroot, $webroot . DIRECTORY_SEPARATOR) === 0;
            }
        }
        $paths = [];
        foreach (array_unique(array_filter([$webroot, $coderoot])) as $root) {
            foreach (['config.php', 'index.php', 'version.php', 'lib/setup.php'] as $name) {
                $path = $root . DIRECTORY_SEPARATOR . $name;
                if (is_file($path)) {
                    clearstatcache(true, $path);
                    $paths[$path] = is_writable($path);
                }
            }
        }
        if ($paths) {
            $result['checked_code_files'] = count($paths);
            $result['writable_code_files'] = count(array_filter($paths));
        }
        return $result;
    }
}
