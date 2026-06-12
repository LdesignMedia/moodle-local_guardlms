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
 * External function that reports site version and plugin inventory.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use core_plugin_manager;
use context_system;

/**
 * Serves the Moodle release and installed plugin inventory to GuardLMS.
 *
 * GuardLMS matches CVEs on the frankenstyle component name and version, so the
 * inventory is returned with the raw component names and installed version
 * numbers exactly as Moodle records them.
 */
class get_site_info extends external_api {

    /**
     * Describes the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'onlythirdparty' => new external_value(
                PARAM_BOOL,
                'If true, only non-standard (third party) plugins are returned.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Return the site version and plugin inventory.
     *
     * @param bool $onlythirdparty Only return third party plugins when true.
     * @return array
     */
    public static function execute(bool $onlythirdparty = false): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'onlythirdparty' => $onlythirdparty,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/guardlms:viewsiteinfo', $context);

        $pluginman = core_plugin_manager::instance();
        $plugins = [];

        foreach ($pluginman->get_plugins() as $type => $pluginsoftype) {
            foreach ($pluginsoftype as $name => $info) {
                $isstandard = (bool) $info->is_standard();
                if ($params['onlythirdparty'] && $isstandard) {
                    continue;
                }

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
                    'isstandard' => $isstandard,
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
            'generatedtime' => time(),
        ];
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

    /**
     * Describes the return value for execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'release' => new external_value(PARAM_RAW, 'Full Moodle release string, e.g. "4.5.3+ (Build: 20250101)".'),
            'version' => new external_value(PARAM_RAW, 'Moodle version number, e.g. "2024100700.05".'),
            'branch' => new external_value(PARAM_RAW, 'Moodle branch, e.g. "405".'),
            'plugincount' => new external_value(PARAM_INT, 'Number of plugins returned.'),
            'plugins' => new external_multiple_structure(
                new external_single_structure([
                    'component' => new external_value(PARAM_RAW, 'Frankenstyle component name, e.g. "mod_quiz".'),
                    'type' => new external_value(PARAM_RAW, 'Plugin type, e.g. "mod".'),
                    'name' => new external_value(PARAM_RAW, 'Plugin name within its type, e.g. "quiz".'),
                    'version' => new external_value(PARAM_RAW, 'Installed plugin version, e.g. "2024100700".'),
                    'release' => new external_value(PARAM_RAW, 'Human readable plugin release, may be empty.'),
                    'displayname' => new external_value(PARAM_RAW, 'Human readable plugin name.'),
                    'isstandard' => new external_value(PARAM_BOOL, 'True when the plugin ships with Moodle core.'),
                    'enabled' => new external_value(PARAM_INT, 'Enabled state: 1 enabled, 0 disabled, -1 unknown.'),
                ])
            ),
            'generatedtime' => new external_value(PARAM_INT, 'Unix timestamp when the report was generated.'),
        ]);
    }
}
