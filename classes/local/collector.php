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
 * Builds the site reporting payload pushed to GuardLMS.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\local;

use core_plugin_manager;

/**
 * Collects the Moodle, server, PHP and (optionally) config information for GuardLMS.
 *
 * GuardLMS matches CVEs on the frankenstyle component name and version, so the
 * plugin inventory keeps the raw component names and installed version numbers
 * exactly as Moodle records them.
 */
class collector {
    /**
     * Moodle config keys reported when the admin opts in to send configuration.
     *
     * Limited to security and session relevant settings so GuardLMS can review
     * how a site is hardened. Never includes secrets.
     */
    protected const CONFIG_KEYS = [
        'cookiehttponly',
        'cookiesecure',
        'cookiesamesite',
        'sessiontimeout',
        'passwordpolicy',
        'minpasswordlength',
        'lockoutthreshold',
        'opentogoogle',
        'registerauth',
        'authloginviaemail',
        'protectusernames',
    ];

    /**
     * Build the full reporting payload.
     *
     * @param bool $includeconfig When true, add the opt-in Moodle config section.
     * @return array Typed envelope ready to be JSON encoded.
     */
    public static function build_payload(bool $includeconfig = false): array {
        global $CFG;

        $payload = [
            'platform' => 'moodle',
            'siteurl' => (string) $CFG->wwwroot,
            'generatedtime' => time(),
            'moodle' => self::moodle_info(),
            'server' => self::server_info(),
            'php' => self::php_info(),
            'database' => self::database_info(),
        ];

        if ($includeconfig) {
            $payload['config'] = self::config_info();
        }

        return $payload;
    }

    /**
     * Moodle release and installed plugin inventory.
     *
     * @return array
     */
    protected static function moodle_info(): array {
        global $CFG;

        $pluginman = core_plugin_manager::instance();
        $plugins = [];

        foreach ($pluginman->get_plugins() as $type => $pluginsoftype) {
            foreach ($pluginsoftype as $name => $info) {
                // Only report plugins that are actually installed in the database.
                $version = $info->versiondb ?? $info->versiondisk ?? null;
                if (empty($version)) {
                    continue;
                }

                $plugins[] = [
                    'component' => $info->component,
                    'type' => $type,
                    'name' => $name,
                    'version' => (string) $version,
                    'release' => (string) ($info->release ?? ''),
                    'displayname' => (string) $info->displayname,
                    'isstandard' => (bool) $info->is_standard(),
                    'enabled' => self::enabled_state($info),
                ];
            }
        }

        return [
            'release' => (string) $CFG->release,
            'version' => (string) $CFG->version,
            'branch' => (string) $CFG->branch,
            'plugincount' => count($plugins),
            'plugins' => $plugins,
        ];
    }

    /**
     * Operating system and webserver information.
     *
     * The webserver software is only known during a web request, so it is cached
     * to plugin config from the admin settings page and read back here because the
     * daily task runs under CLI cron where the superglobal is empty.
     *
     * @return array
     */
    protected static function server_info(): array {
        $webserver = get_config('local_guardlms', 'webserver');
        if (empty($webserver)) {
            $webserver = $_SERVER['SERVER_SOFTWARE'] ?? null;
        }

        return [
            'os_family' => PHP_OS_FAMILY,
            'os' => PHP_OS,
            'hostname' => gethostname() ?: null,
            'webserver' => $webserver ?: null,
            // Which external session store the site uses (empty is the built-in
            // file/database default). A Redis or Memcached class here tells
            // GuardLMS an external service is in play beyond the loaded extensions.
            'sessionhandler' => self::session_handler(),
        ];
    }

    /**
     * The configured session handler class, or empty for the built-in default.
     *
     * @return string
     */
    protected static function session_handler(): string {
        global $CFG;

        return (string) ($CFG->session_handler_class ?? '');
    }

    /**
     * Database engine and version.
     *
     * GuardLMS matches CVEs and end-of-life status against the database vendor
     * and version, so both the Moodle-facing type and the server's own reported
     * version are included.
     *
     * @return array
     */
    protected static function database_info(): array {
        global $CFG, $DB;

        $server = $DB->get_server_info();

        return [
            'type' => (string) $CFG->dbtype,
            'library' => (string) ($CFG->dblibrary ?? 'native'),
            'family' => (string) $DB->get_dbfamily(),
            'vendor' => method_exists($DB, 'get_dbvendor') ? (string) $DB->get_dbvendor() : null,
            'version' => (string) ($server['version'] ?? ''),
            'description' => (string) ($server['description'] ?? ''),
        ];
    }

    /**
     * PHP runtime information.
     *
     * @return array
     */
    protected static function php_info(): array {
        $extensions = get_loaded_extensions();
        sort($extensions);

        return [
            'version' => PHP_VERSION,
            'sapi' => php_sapi_name(),
            'ini' => php_ini_loaded_file() ?: 'none',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'timezone' => date_default_timezone_get(),
            'extensions' => $extensions,
        ];
    }

    /**
     * Selected Moodle configuration values (opt-in).
     *
     * @return array Keyed by setting name, value cast to string, missing settings omitted.
     */
    protected static function config_info(): array {
        $config = [];

        foreach (self::CONFIG_KEYS as $key) {
            $value = get_config('moodle', $key);
            if ($value === false) {
                continue;
            }
            $config[$key] = (string) $value;
        }

        return $config;
    }

    /**
     * Normalise a plugin enabled state into a tri-state integer.
     *
     * @param mixed $info Plugin info object from core_plugin_manager.
     * @return int 1 enabled, 0 disabled, -1 not applicable or unknown.
     */
    protected static function enabled_state($info): int {
        $enabled = $info->is_enabled();
        if ($enabled === null) {
            return -1;
        }

        return $enabled ? 1 : 0;
    }
}
