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

namespace local_guardlms;

use local_guardlms\local\collector;
use local_guardlms\local\hardening_report;
use local_guardlms\local\mfa_configuration_report;

/**
 * Local detection and privacy contract for Moodle configuration monitoring.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_guardlms\local\mfa_configuration_report
 * @covers \local_guardlms\local\hardening_report
 */
final class moodle_configuration_test extends \advanced_testcase {
    /**
     * Set deterministic MFA settings without invoking MFA enrolment/session APIs.
     *
     * @param array $weights Enabled core factor weights.
     */
    private function configure_mfa(array $weights): void {
        if (!class_exists(\tool_mfa\plugininfo\factor::class)) {
            $this->markTestSkipped('Core MFA is unavailable on this Moodle version.');
        }
        set_config('enabled', 1, 'tool_mfa');
        foreach (\core_plugin_manager::instance()->get_plugins_of_type('factor') as $name => $info) {
            set_config('enabled', array_key_exists($name, $weights) ? 1 : 0, 'factor_' . $name);
            set_config('weight', $weights[$name] ?? 0, 'factor_' . $name);
        }
    }

    /**
     * The disabled policy is relevant only to active manually authenticated privileged users.
     */
    public function test_manual_admins_are_local_and_inactive_or_external_users_are_excluded(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->configure_mfa(['totp' => 100]);
        set_config('enabled', 0, 'tool_mfa');
        $report = mfa_configuration_report::collect();
        $this->assertTrue($report['manual_admins_present']);
        $this->assertFalse($report['manual_admins_mfa_required']);
        foreach (explode(',', $CFG->siteadmins) as $id) {
            $DB->set_field('user', 'auth', 'oauth2', ['id' => $id]);
        }
        $this->assertFalse(mfa_configuration_report::collect()['manual_admins_present']);
        $admin = $this->getDataGenerator()->create_user(['auth' => 'manual', 'email' => 'private-admin@example.invalid']);
        $roleid = create_role('Private administrator', 'privateadmin', '');
        assign_capability('moodle/site:config', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $admin->id, \context_system::instance()->id);
        $report = mfa_configuration_report::collect();
        $this->assertTrue($report['manual_admins_present']);
        $this->assertFalse($report['manual_admins_mfa_required']);
        $this->assertStringNotContainsString($admin->email, json_encode($report));
        $DB->set_field('user', 'suspended', 1, ['id' => $admin->id]);
        $report = mfa_configuration_report::collect();
        $this->assertFalse($report['manual_admins_present']);
        $this->assertArrayNotHasKey('manual_admins_mfa_required', $report);
    }

    /**
     * MFA on its own is not enough: static bypass combinations can reach the threshold.
     */
    public function test_manual_auth_bypass_combines_weights_and_preserves_session_and_factor_records(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->configure_mfa(['totp' => 100, 'auth' => 60, 'role' => 40]);
        set_config('goodauth', 'manual,oauth2', 'factor_auth');
        set_config('roles', '', 'factor_role');
        $before = $DB->count_records('tool_mfa');
        $userid = $USER->id;
        $report = mfa_configuration_report::collect();
        $this->assertTrue($report['enabled']);
        $this->assertTrue($report['configuration_complete']);
        $this->assertFalse($report['manual_admins_mfa_required']);
        $this->assertSame($before, $DB->count_records('tool_mfa'));
        $this->assertSame($userid, $USER->id);
        set_config('goodauth', 'oauth2', 'factor_auth');
        $this->assertTrue(mfa_configuration_report::collect()['manual_admins_mfa_required']);
    }

    /**
     * Site admins are targeted by the core admin factor, while delegated administrators are exempted.
     */
    public function test_admin_factor_does_not_protect_delegated_site_config_administrators(): void {
        $this->resetAfterTest();
        $this->configure_mfa(['totp' => 100, 'admin' => 100]);
        $this->assertTrue(mfa_configuration_report::collect()['manual_admins_mfa_required']);
        $admin = $this->getDataGenerator()->create_user();
        $roleid = create_role('Delegated administrator', 'delegatedadmin', '');
        assign_capability('moodle/site:config', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $admin->id, \context_system::instance()->id);
        $this->assertFalse(mfa_configuration_report::collect()['manual_admins_mfa_required']);
    }

    /**
     * Static role and capability exclusions must match Moodle's actual policy semantics.
     */
    public function test_role_and_capability_targeting(): void {
        $this->resetAfterTest();
        $this->configure_mfa(['totp' => 100, 'role' => 100]);
        set_config('roles', 'admin', 'factor_role');
        $this->assertTrue(mfa_configuration_report::collect()['manual_admins_mfa_required']);
        set_config('roles', '', 'factor_role');
        $this->assertFalse(mfa_configuration_report::collect()['manual_admins_mfa_required']);
        $this->configure_mfa(['totp' => 100, 'capability' => 100]);
        set_config('adminpasses', 1, 'factor_capability');
        $this->assertTrue(mfa_configuration_report::collect()['manual_admins_mfa_required']);
        set_config('adminpasses', 0, 'factor_capability');
        $this->assertFalse(mfa_configuration_report::collect()['manual_admins_mfa_required']);
    }

    /**
     * Conditional bypasses are unknown, while definitely incomplete configuration is explicit.
     */
    public function test_mfa_unknown_and_incomplete_policies(): void {
        $this->resetAfterTest();
        $this->configure_mfa(['totp' => 100, 'nosetup' => 100]);
        $report = mfa_configuration_report::collect();
        $this->assertTrue($report['password_only_fallback']);
        $this->assertArrayNotHasKey('manual_admins_mfa_required', $report);
        $this->configure_mfa(['totp' => 50]);
        $report = mfa_configuration_report::collect();
        $this->assertFalse($report['configuration_complete']);
        $this->assertArrayNotHasKey('manual_admins_mfa_required', $report);
        $this->configure_mfa(['totp' => 50, 'nosetup' => 50]);
        $this->assertArrayNotHasKey('configuration_complete', mfa_configuration_report::collect());
        $this->configure_mfa(['admin' => 100]);
        $this->assertFalse(mfa_configuration_report::collect()['configuration_complete']);
    }

    /**
     * SMS/WebAuthn switches and weights do not prove their required local settings exist.
     */
    public function test_input_factors_require_local_configuration(): void {
        $this->resetAfterTest();
        $this->configure_mfa(['sms' => 100]);
        if (class_exists(\core_sms\manager::class)) {
            set_config('smsgateway', 0, 'factor_sms');
            $this->assertFalse(mfa_configuration_report::collect()['configuration_complete']);
            set_config('smsgateway', 987654, 'factor_sms');
            $this->assertFalse(mfa_configuration_report::collect()['configuration_complete']);
        } else {
            $this->assertArrayNotHasKey('configuration_complete', mfa_configuration_report::collect());
        }
        $this->configure_mfa(['webauthn' => 100]);
        set_config('authenticatortypes', '', 'factor_webauthn');
        $this->assertFalse(mfa_configuration_report::collect()['configuration_complete']);
        set_config('authenticatortypes', 'usb,internal', 'factor_webauthn');
        $this->assertTrue(mfa_configuration_report::collect()['configuration_complete']);
    }

    /**
     * CAPTCHA keys, signup switches and login switches have distinct meanings.
     */
    public function test_recaptcha_configuration_and_effective_registration(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('recaptchapublickey', 'private-public-key');
        set_config('recaptchaprivatekey', 'private-secret-key');
        set_config('auth', 'manual,email');
        set_config('registerauth', 'email');
        set_config('authpreventaccountcreation', 0);
        set_config('recaptcha', 0, 'auth_email');
        $CFG->enableloginrecaptcha = 1;
        $report = hardening_report::collect()['recaptcha'];
        $this->assertTrue($report['keys_configured']);
        $this->assertTrue($report['self_registration_enabled']);
        $this->assertFalse($report['signup_enabled']);
        $this->assertTrue($report['login_enabled']);
        $this->assertStringNotContainsString('private-', json_encode($report));
        set_config('recaptcha', 1, 'auth_email');
        $this->assertTrue(hardening_report::collect()['recaptcha']['signup_enabled']);
        set_config('recaptchaprivatekey', ' ');
        $this->assertFalse(hardening_report::collect()['recaptcha']['keys_configured']);
        set_config('authpreventaccountcreation', 1);
        $this->assertFalse(hardening_report::collect()['recaptcha']['self_registration_enabled']);
        set_config('authpreventaccountcreation', 0);
        set_config('auth', 'manual');
        $this->assertFalse(hardening_report::collect()['recaptcha']['self_registration_enabled']);
        unset($CFG->enableloginrecaptcha);
        $this->assertArrayNotHasKey('login_enabled', hardening_report::collect()['recaptcha']);
    }

    /**
     * Mobile web services require all global, protocol and official-service switches.
     */
    public function test_mobile_api_effective_configuration(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enablewebservices', 1);
        set_config('enablemobilewebservice', 1);
        set_config('webserviceprotocols', 'rest');
        $service = $DB->get_record('external_services', ['shortname' => 'moodle_mobile_app']);
        if (!$service) {
            $service = (object) ['shortname' => 'moodle_mobile_app', 'name' => 'Mobile app',
                'enabled' => 1, 'restrictedusers' => 0, 'timecreated' => time()];
            $service->id = $DB->insert_record('external_services', $service);
        }
        $DB->set_field('external_services', 'enabled', 1, ['id' => $service->id]);
        $this->assertTrue(hardening_report::collect()['mobile_api']['enabled']);
        foreach (['enablewebservices', 'enablemobilewebservice'] as $key) {
            set_config($key, 0);
            $this->assertFalse(hardening_report::collect()['mobile_api']['enabled']);
            set_config($key, 1);
        }
        set_config('webserviceprotocols', 'soap');
        $this->assertFalse(hardening_report::collect()['mobile_api']['enabled']);
        set_config('webserviceprotocols', 'rest');
        $DB->set_field('external_services', 'enabled', 0, ['id' => $service->id]);
        $this->assertFalse(hardening_report::collect()['mobile_api']['enabled']);
    }

    /**
     * Whole-inventory regression prevents PII, reCAPTCHA secrets and configured IP rules escaping.
     */
    public function test_complete_payload_does_not_export_sensitive_configuration_or_accounts(): void {
        global $CFG;
        $this->resetAfterTest();
        $sentinel = 'private-person-2776@example.invalid';
        $admin = $this->getDataGenerator()->create_user([
            'username' => 'private-user-2776', 'email' => $sentinel, 'lastip' => '198.51.100.197',
        ]);
        $CFG->siteadmins .= ',' . $admin->id;
        set_config('recaptchapublickey', 'private-public-2776');
        set_config('recaptchaprivatekey', 'private-secret-2776');
        set_config('curlsecurityblockedhosts', "198.51.100.197\n192.0.2.0/24\nprivate-host-2776.invalid");
        $payload = collector::build_payload();
        $json = json_encode($payload);
        foreach (
            [$sentinel, 'private-user-2776', '198.51.100.197', '192.0.2.0/24',
                'private-host-2776.invalid', 'private-public-2776', 'private-secret-2776'] as $private
        ) {
            $this->assertStringNotContainsString($private, $json);
        }
        $hardening = $payload['security_config']['hardening'];
        $this->assertArrayNotHasKey('blocked_ip_rules', $hardening['ssrf']);
        foreach (['recaptcha', 'mobile_api'] as $section) {
            $this->assertSame('observed', $hardening[$section]['status']);
            $this->assertIsInt($hardening[$section]['observed_at']);
            foreach (array_diff_key($hardening[$section], ['status' => true, 'observed_at' => true]) as $value) {
                $this->assertIsBool($value);
            }
        }
    }
}
