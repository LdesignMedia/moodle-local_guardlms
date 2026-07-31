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
     * How stale Moodle's cached update-check response may be before this plugin
     * refetches it itself, in seconds.
     *
     * Mirrors the freshness window Moodle's own cron uses
     * (\core\update\checker::cron_has_fresh_fetch()), so a site whose cron is
     * healthy is never fetched twice, while a site whose cron is broken still
     * reports current data instead of a months-old snapshot.
     */
    protected const UPDATE_MAX_AGE = 24 * 60 * 60;

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
     * @param bool $refreshupdates When true, allow an outbound refresh of Moodle's
     *                             update-check data before reporting it. Only cron
     *                             paths pass true; see update_check_state().
     * @return array Typed envelope ready to be JSON encoded.
     */
    public static function build_payload(bool $includeconfig = false, bool $refreshupdates = false): array {
        // Resolved first, and deliberately before moodle_info() asks
        // core_plugin_manager for the plugin list: a refresh calls
        // \core\update\checker::fetch(), which resets the plugin manager's
        // caches. Reading the inventory afterwards means the plugin info
        // objects carry the update data we just fetched rather than the stale
        // set the singleton was holding.
        $updatecheck = self::update_check_state($refreshupdates);

        $payload = [
            'platform' => 'moodle',
            'siteurl' => config::siteurl(),
            'generatedtime' => time(),
            'moodle' => self::moodle_info($updatecheck),
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
     * @param array $updatecheck State returned by update_check_state().
     * @return array
     */
    protected static function moodle_info(array $updatecheck): array {
        global $CFG;

        $pluginman = core_plugin_manager::instance();
        $plugins = [];

        // Only claim anything about updates when the underlying data is known
        // to be current. See the 'updates' key comment below.
        $updatesknown = !$updatecheck['stale'];

        foreach ($pluginman->get_plugins() as $type => $pluginsoftype) {
            foreach ($pluginsoftype as $name => $info) {
                // Only report plugins that are actually installed in the database.
                $version = $info->versiondb ?? $info->versiondisk ?? null;
                if (empty($version)) {
                    continue;
                }

                $plugin = [
                    'component' => $info->component,
                    'type' => $type,
                    'name' => $name,
                    'version' => (string) $version,
                    // Moodle compares available updates against the code on disk
                    // while 'version' above reports the version the database is
                    // upgraded to. The two differ only while an upgrade is
                    // pending, and
                    // reporting both lets GuardLMS tell that case apart from a
                    // plugin that is genuinely behind.
                    'versiondisk' => (string) ($info->versiondisk ?? ''),
                    'release' => (string) ($info->release ?? ''),
                    'displayname' => (string) $info->displayname,
                    'isstandard' => (bool) $info->is_standard(),
                    'enabled' => self::enabled_state($info),
                ];

                // Tri-state, and the distinction is the whole point of this key:
                // present-but-empty means "checked, nothing available", absent
                // means "nobody checked". Without it GuardLMS cannot tell an
                // up-to-date plugin from one whose site never ran an update
                // check, and would render both as fine.
                if ($updatesknown) {
                    $plugin['updates'] = self::plugin_updates($info);
                }

                $plugins[] = $plugin;
            }
        }

        $moodle = [
            'release' => (string) $CFG->release,
            'version' => (string) $CFG->version,
            'branch' => (string) $CFG->branch,
            'plugincount' => count($plugins),
            'plugins' => $plugins,
            'updatecheck' => $updatecheck,
        ];

        if ($updatesknown) {
            $moodle['coreupdates'] = self::core_updates();
        }

        return $moodle;
    }

    /**
     * Resolve the state of Moodle's update checker, refreshing it when allowed.
     *
     * \core\update\checker::get_update_info() only ever reads the response
     * cached by the last fetch; it never contacts download.moodle.org itself.
     * On a site whose cron is broken, or whose automatic check is switched off,
     * that cache is stale or empty — and reporting it verbatim would tell
     * GuardLMS "no updates available" when the truth is "nobody looked". So the
     * cron paths refetch whenever the cache is older than UPDATE_MAX_AGE, and
     * anything that cannot be refreshed is reported as stale rather than as
     * good news.
     *
     * @param bool $refresh Allow an outbound fetch. Only cron paths pass true:
     *                      a web request must not block on download.moodle.org.
     * @return array{enabled: bool, lastfetched: int|null, stale: bool, fetcherror: string|null}
     */
    protected static function update_check_state(bool $refresh): array {
        // Guarded rather than assumed: if a future Moodle moves or drops the
        // class, the site keeps pushing its inventory and simply reports that
        // update information is unavailable.
        if (!class_exists('\core\update\checker')) {
            return [
                'enabled' => false,
                'lastfetched' => null,
                'stale' => true,
                'fetcherror' => null,
            ];
        }

        $checker = \core\update\checker::instance();

        // The checker's own enabled flag is $CFG->disableupdatenotifications.
        // With it off, Moodle returns no update info for any component, so
        // there is nothing to refresh and nothing to report.
        $enabled = (bool) $checker->enabled();
        $lastfetched = $enabled ? $checker->get_last_timefetched() : null;
        $lastfetched = empty($lastfetched) ? null : (int) $lastfetched;
        $fetcherror = null;

        // PHPUNIT_TEST guard: fetch() performs a real request to
        // download.moodle.org, which a test run must never do.
        $mayfetch = $enabled && $refresh && !(defined('PHPUNIT_TEST') && PHPUNIT_TEST);

        if ($mayfetch && self::update_data_is_stale($lastfetched)) {
            try {
                $checker->fetch();
                $fetched = $checker->get_last_timefetched();
                $lastfetched = empty($fetched) ? null : (int) $fetched;
            } catch (\Throwable $e) {
                // A site behind an egress firewall must still push its
                // inventory; it just pushes it without update information, and
                // says why.
                $fetcherror = \core_text::substr($e->getMessage(), 0, 500);
            }
        }

        return [
            'enabled' => $enabled,
            'lastfetched' => $lastfetched,
            'stale' => !$enabled || self::update_data_is_stale($lastfetched),
            'fetcherror' => $fetcherror,
        ];
    }

    /**
     * Whether a fetch timestamp is missing or older than the freshness window.
     *
     * @param int|null $lastfetched Unix timestamp of the last fetch.
     * @return bool
     */
    protected static function update_data_is_stale(?int $lastfetched): bool {
        if (empty($lastfetched)) {
            return true;
        }

        // A timestamp in the future is clock skew we cannot reason about.
        // Moodle's own cron treats it as fresh rather than refetching every
        // run, and so do we.
        if ($lastfetched > time()) {
            return false;
        }

        return (time() - $lastfetched) > self::UPDATE_MAX_AGE;
    }

    /**
     * Available updates for one plugin, exactly as Moodle itself reports them.
     *
     * \core\plugininfo\base::available_updates() already applies the site's
     * minimum maturity setting and only returns versions newer than the code on
     * disk, so this is the same list the admin sees on the plugin overview
     * screen — not a guess made by comparing version strings elsewhere.
     *
     * @param mixed $info Plugin info object from core_plugin_manager.
     * @return array List of update descriptors; empty when the plugin is current.
     */
    protected static function plugin_updates($info): array {
        $updates = [];

        foreach ($info->available_updates() ?: [] as $update) {
            $updates[] = self::update_descriptor($update);
        }

        return $updates;
    }

    /**
     * Available updates for Moodle core.
     *
     * available_updates() covers plugins only, so core goes through the checker
     * directly, with the site's own maturity and build-notification preferences
     * applied the same way \core\update\checker::cron_notifications() applies
     * them.
     *
     * @return array List of update descriptors; empty when core is current.
     */
    protected static function core_updates(): array {
        global $CFG;

        $options = [
            'minmaturity' => $CFG->updateminmaturity ?? MATURITY_STABLE,
            'notifybuilds' => !empty($CFG->updatenotifybuilds),
        ];

        $updates = [];

        foreach (\core\update\checker::instance()->get_update_info('core', $options) ?: [] as $update) {
            $updates[] = self::update_descriptor($update);
        }

        return $updates;
    }

    /**
     * Flatten a \core\update\info object into the reported shape.
     *
     * The download URL and its hash are deliberately dropped: GuardLMS reports
     * that an update exists, it never installs one.
     *
     * @param mixed $update A \core\update\info instance.
     * @return array
     */
    protected static function update_descriptor($update): array {
        return [
            'version' => (string) $update->version,
            'release' => (string) ($update->release ?? ''),
            'maturity' => isset($update->maturity) ? (int) $update->maturity : null,
            'url' => (string) ($update->url ?? ''),
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

        [$webservername, $webserverversion] = self::split_server_signature((string) $webserver);

        // os_family, os and webserver stay as they were: an older GuardLMS keeps
        // reading them while the split fields below are what CVE matching needs.
        return array_merge([
            'os_family' => PHP_OS_FAMILY,
            'os' => PHP_OS,
            'hostname' => gethostname() ?: null,
            'webserver' => $webserver ?: null,
            'webserver_name' => $webservername,
            'webserver_version' => $webserverversion,
            // Which external session store the site uses (empty is the built-in
            // file/database default). A Redis or Memcached class here tells
            // GuardLMS an external service is in play beyond the loaded extensions.
            'sessionhandler' => self::session_handler(),
        ], self::os_info());
    }

    /**
     * Distribution level operating system detail.
     *
     * PHP only reports the kernel ("Linux"), which is not enough to match an OS
     * against known vulnerabilities or an end-of-life date. On Linux the release
     * is read from the os-release file, the standard the distributions publish.
     * Reads only: no shell commands, so it also works where exec() is disabled.
     *
     * @return array
     */
    protected static function os_info(): array {
        $info = [
            'os_name' => PHP_OS_FAMILY,
            'os_id' => strtolower(PHP_OS_FAMILY),
            'os_version' => '',
            'os_pretty' => '',
            'kernel' => php_uname('r'),
            'arch' => php_uname('m'),
        ];

        $release = self::os_release_values();
        if ($release) {
            $info['os_name'] = $release['NAME'] ?? $info['os_name'];
            $info['os_id'] = $release['ID'] ?? $info['os_id'];
            $info['os_version'] = $release['VERSION_ID'] ?? '';
            $info['os_pretty'] = $release['PRETTY_NAME'] ?? '';
        } else if (PHP_OS_FAMILY === 'Darwin') {
            // macOS has no os-release file; the Darwin kernel version is the only
            // version PHP exposes without shelling out to sw_vers.
            $info['os_name'] = 'macOS';
            $info['os_id'] = 'macos';
            $info['os_version'] = php_uname('r');
        } else if (PHP_OS_FAMILY === 'Windows') {
            $info['os_name'] = php_uname('s');
            $info['os_id'] = 'windows';
            $info['os_version'] = php_uname('r');
        }

        if ($info['os_pretty'] === '') {
            $info['os_pretty'] = trim($info['os_name'] . ' ' . $info['os_version']);
        }

        return $info;
    }

    /**
     * Parse /etc/os-release into key => value pairs.
     *
     * @return array Empty when the file is absent or unreadable.
     */
    protected static function os_release_values(): array {
        $candidates = ['/etc/os-release', '/usr/lib/os-release'];
        $values = [];

        foreach ($candidates as $path) {
            if (!is_readable($path)) {
                continue;
            }

            $contents = @file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            foreach (preg_split('/\R/', $contents) as $line) {
                if (strpos($line, '=') === false || strpos(trim($line), '#') === 0) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $values[trim($key)] = trim(trim($value), "\"'");
            }

            if ($values) {
                return $values;
            }
        }

        return $values;
    }

    /**
     * Split a web server signature into its product name and version.
     *
     * "Apache/2.4.68 (Debian)" becomes ['Apache', '2.4.68'], "nginx/1.24.0"
     * becomes ['nginx', '1.24.0']. A signature without a version (some hosts
     * suppress it) keeps the name and returns an empty version.
     *
     * @param string $signature Raw SERVER_SOFTWARE value.
     * @return array{0: string, 1: string} Name and version, both possibly empty.
     */
    protected static function split_server_signature(string $signature): array {
        $signature = trim($signature);
        if ($signature === '') {
            return ['', ''];
        }

        // Only the leading product token matters; the rest is "(Debian) PHP/8.2".
        $product = strtok($signature, ' ');
        if ($product === false) {
            return ['', ''];
        }

        if (strpos($product, '/') === false) {
            return [$product, ''];
        }

        [$name, $version] = explode('/', $product, 2);

        return [$name, $version];
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
