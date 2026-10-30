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
 * Static MFA policy observations; no user identities or enrolled-factor records leave Moodle.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_guardlms\local;

/**
 * Evaluates known core policy factors locally without changing the current user or session.
 */
class mfa_configuration_report {
    /** @var string[] Core factors that require a challenge. */
    private const INPUT_FACTORS = ['email', 'totp', 'webauthn', 'sms'];

    /** @var string[] Core factors whose outcome can depend on runtime/enrolment state. */
    private const CONDITIONAL_FACTORS = ['nosetup', 'grace', 'iprange', 'cohort', 'token'];

    /**
     * Return only booleans and a factor count. Missing policy verdicts mean unknown.
     *
     * @return array|null Unsupported when tool_mfa is unavailable.
     */
    public static function collect(): ?array {
        if (!class_exists(\tool_mfa\plugininfo\factor::class)) {
            return null;
        }
        $weights = [];
        foreach (\core_plugin_manager::instance()->get_plugins_of_type('factor') as $name => $info) {
            if (
                (int) get_config('factor_' . $name, 'enabled') === 1
                && class_exists('\\factor_' . $name . '\\factor')
            ) {
                $weights[$name] = (int) get_config('factor_' . $name, 'weight');
            }
        }
        $result = [
            'enabled' => !empty(get_config('tool_mfa', 'enabled')),
            'enabled_factors' => count($weights),
            'password_only_fallback' => ($weights['nosetup'] ?? 0) >= 100,
        ];
        $known = array_merge(self::INPUT_FACTORS, self::CONDITIONAL_FACTORS, ['auth', 'admin', 'role', 'capability']);
        $custom = array_diff(array_keys($weights), $known);
        $inputweights = array_intersect_key($weights, array_flip(self::INPUT_FACTORS));
        foreach ($inputweights as $name => $weight) {
            if (self::input_configured($name) === false) {
                $inputweights[$name] = 0;
            }
        }
        $inputweight = array_sum($inputweights);
        $negativeweights = (bool) array_filter($weights, static function (int $weight): bool {
            return $weight < 0;
        });
        if (!$custom && !$negativeweights) {
            // This is a configuration check, not proof of factor enrolment or provider availability.
            if ($inputweight <= 0 || array_sum($weights) < 100) {
                $result['configuration_complete'] = false;
            } else if (
                array_filter($inputweights, static function (int $weight, string $name): bool {
                    return $weight >= 100 && self::input_configured($name) === true;
                }, ARRAY_FILTER_USE_BOTH)
            ) {
                $result['configuration_complete'] = true;
            }
        }
        $admins = self::manual_admins();
        $result['manual_admins_present'] = (bool) $admins;
        if (!$admins) {
            return $result;
        }
        if (!$result['enabled']) {
            $result['manual_admins_mfa_required'] = false;
            return $result;
        }
        $required = true;
        foreach ($admins as $admin) {
            $minimum = 0;
            $maximum = 0;
            foreach ($weights as $name => $weight) {
                if (in_array($name, self::INPUT_FACTORS, true) || $weight <= 0) {
                    continue;
                }
                $passes = self::static_factor_passes($name, (int) $admin->id);
                if ($passes === true) {
                    $minimum += $weight;
                }
                if ($passes !== false) {
                    $maximum += $weight;
                }
            }
            if ($minimum >= 100 && !$negativeweights) {
                $result['manual_admins_mfa_required'] = false;
                return $result;
            }
            // Unknown/conditional combinations could bypass a challenge. Never report them as protected.
            if ($maximum >= 100 || $inputweight + $minimum < 100 || $custom || $negativeweights) {
                $required = false;
            }
        }
        if ($required) {
            $result['manual_admins_mfa_required'] = true;
        }
        return $result;
    }

    /**
     * Whether required settings for a known input factor are present, without provider requests.
     *
     * @param string $name Core input factor name.
     * @return bool|null Unknown for legacy SMS providers whose configuration contract differs.
     */
    private static function input_configured(string $name): ?bool {
        global $DB;
        if ($name === 'sms') {
            if (!class_exists(\core_sms\manager::class)) {
                return null;
            }
            $gateway = (int) get_config('factor_sms', 'smsgateway');
            return $gateway > 0 && $DB->record_exists('sms_gateways', ['id' => $gateway, 'enabled' => 1]);
        }
        if ($name === 'webauthn') {
            $types = explode(',', (string) get_config('factor_webauthn', 'authenticatortypes'));
            return (bool) array_intersect($types, ['usb', 'nfc', 'ble', 'hybrid', 'internal']);
        }
        return in_array($name, ['email', 'totp'], true) ? true : null;
    }

    /**
     * Minimal local account fields for active manual site administrators and site-config capability holders.
     *
     * @return array Local records only; never returned in the exported observation.
     */
    private static function manual_admins(): array {
        global $CFG, $DB;
        $users = get_users_by_capability(
            \context_system::instance(),
            'moodle/site:config',
            'u.id, u.auth, u.deleted, u.suspended',
            'u.id'
        );
        $siteadmins = array_filter(array_map('intval', explode(',', (string) ($CFG->siteadmins ?? ''))));
        if ($siteadmins) {
            $users += $DB->get_records_list('user', 'id', $siteadmins, '', 'id, auth, deleted, suspended');
        }
        return array_filter($users, static function (\stdClass $user): bool {
            return $user->auth === 'manual' && !$user->deleted && !$user->suspended;
        });
    }

    /**
     * Match static core factor semantics without calling get_state() or creating factor records.
     *
     * @param string $name Factor name.
     * @param int $userid Local user ID, never exported.
     * @return bool|null A provable pass/neutral outcome, or unknown.
     */
    private static function static_factor_passes(string $name, int $userid): ?bool {
        if ($name === 'admin') {
            return !is_siteadmin($userid);
        }
        if ($name === 'auth') {
            return in_array('manual', explode(',', (string) get_config('factor_auth', 'goodauth')), true);
        }
        if ($name === 'capability') {
            return !has_capability(
                'factor/capability:cannotpassfactor',
                \context_system::instance(),
                $userid,
                (bool) get_config('factor_capability', 'adminpasses')
            );
        }
        if ($name === 'role') {
            $selected = explode(',', (string) get_config('factor_role', 'roles'));
            $specials = get_user_roles_with_special(\context_system::instance(), $userid);
            $specialids = array_map(static function ($role): int {
                return (int) $role->roleid;
            }, $specials);
            foreach ($selected as $roleid) {
                if ($roleid === 'admin') {
                    if (is_siteadmin($userid)) {
                        return false;
                    }
                } else if (
                    (int) $roleid > 0 && (user_has_role_assignment($userid, (int) $roleid)
                    || in_array((int) $roleid, $specialids, true))
                ) {
                    return false;
                }
            }
            return true;
        }
        return null;
    }
}
