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
 * Synthetic external security probes containing no user data.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\local;

/**
 * Issues expiring credentials scoped only to two synthetic marker resources.
 */
class external_probe {
    /**
     * Create a fresh descriptor for the next inventory push.
     * @return array Descriptor; never contains Moodle account credentials.
     */
    public static function collect(): array {
        $state = json_decode((string) get_config('local_guardlms', 'externalprobe'), true);
        if (
            !is_array($state) || !preg_match('/^[a-f0-9]{64}$/', $state['token'] ?? '')
                || !preg_match('/^[a-f0-9]{64}$/', $state['marker'] ?? '')
                || ($state['expires'] ?? 0) < time() + 3600
        ) {
            $state = [
                'token' => bin2hex(random_bytes(32)),
                'marker' => bin2hex(random_bytes(32)),
                'expires' => time() + 172800,
            ];
            $context = \context_system::instance();
            $fs = get_file_storage();
            $fs->delete_area_files($context->id, 'local_guardlms', 'securityprobe');
            $fs->create_file_from_string([
                'contextid' => $context->id, 'component' => 'local_guardlms',
                'filearea' => 'securityprobe', 'itemid' => 0, 'filepath' => '/', 'filename' => 'marker.txt',
            ], $state['marker']);
            set_config('externalprobe', json_encode($state), 'local_guardlms');
        }
        $context = \context_system::instance();
        return $state + [
            'privatePath' => '/pluginfile.php/' . $context->id . '/local_guardlms/securityprobe/0/marker.txt',
            'cachePath' => '/local/guardlms/securityprobe.php',
        ];
    }

    /**
     * Validate a probe-only bearer credential, without creating a user session.
     * @param string $authorization HTTP Authorization header.
     * @return bool Whether the synthetic resource may be served.
     */
    public static function authorized(string $authorization): bool {
        $state = json_decode((string) get_config('local_guardlms', 'externalprobe'), true);
        return is_array($state) && ($state['expires'] ?? 0) > time()
            && is_string($state['token'] ?? null)
            && hash_equals('Bearer ' . $state['token'], $authorization);
    }

    /**
     * Return the current synthetic marker after the caller has checked authorization.
     * @return string Marker with no user data.
     */
    public static function marker(): string {
        $state = json_decode((string) get_config('local_guardlms', 'externalprobe'), true);
        return (string) ($state['marker'] ?? '');
    }
}
