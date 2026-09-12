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

/**
 * Privacy and configuration observations.
 * @package local_guardlms
 * @copyright 2026 Luuk Verhoeven, ldesignmedia.nl
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_guardlms\local\hardening_report
 */
final class hardening_report_test extends \advanced_testcase {
    /**
     * Sensitive configuration never appears in the complete exported inventory.
     */
    public function test_inventory_excludes_private_configuration(): void {
        $this->resetAfterTest();
        $secret = 'private-person@example.invalid/secret-token';
        foreach (
            ['cronremotepassword', 'recaptchaprivatekey', 'allowedip', 'blockedip',
                'curlsecurityblockedhosts', 'curlsecurityallowedport', 'notifyloginfailures'] as $key
        ) {
            set_config($key, $secret);
        }
        set_config('pathtoclam', $secret, 'antivirus_clamav');
        $payload = collector::build_payload();
        $this->assertStringNotContainsString($secret, json_encode($payload));
        foreach ($payload['securitychecks'] as $check) {
            $this->assertSame('', $check['summary']);
            $this->assertSame('', $check['details']);
        }
    }

    /**
     * Unix socket and TCP engines do not require an executable path.
     */
    public function test_antivirus_method_and_fail_open_are_reported_without_paths(): void {
        $this->resetAfterTest();
        set_config('antiviruses', 'clamav');
        set_config('runningmethod', 'unixsocket', 'antivirus_clamav');
        set_config('pathtounixsocket', '/private/clam.sock', 'antivirus_clamav');
        set_config('clamfailureonupload', 'donothing', 'antivirus_clamav');
        $report = hardening_report::collect();
        $this->assertSame('observed', $report['antivirus']['status']);
        $this->assertTrue($report['antivirus']['configuration_complete']);
        $this->assertTrue($report['antivirus']['fail_open']);
        $this->assertStringNotContainsString('/private/', json_encode($report));
        set_config('runningmethod', 'commandline', 'antivirus_clamav');
        set_config('pathtoclam', '', 'antivirus_clamav');
        $this->assertFalse(hardening_report::collect()['antivirus']['configuration_complete']);
    }

    /**
     * A missing engine differs from an unsupported custom engine.
     */
    public function test_unknown_antivirus_does_not_report_a_false_pass(): void {
        $this->resetAfterTest();
        set_config('antiviruses', 'customscanner');
        $report = hardening_report::collect();
        $this->assertSame('unsupported', $report['antivirus']['status']);
        $this->assertArrayNotHasKey('enabled', $report['antivirus']);
        set_config('antiviruses', '');
        $this->assertFalse(hardening_report::collect()['antivirus']['enabled']);
    }

    /**
     * Effective configuration honours forced deployment settings.
     */
    public function test_forced_web_install_setting(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->disableupdateautodeploy = true;
        $this->assertTrue(hardening_report::collect()['deployment']['web_install_disabled']);
    }

    /**
     * Aggregate query sections must actually run, rather than silently return failed.
     */
    public function test_aggregate_sections_are_observed(): void {
        $this->resetAfterTest();
        $report = hardening_report::collect();
        foreach (['tokens', 'tasks', 'enrolments'] as $section) {
            $this->assertSame('observed', $report[$section]['status'], $section);
        }
    }

    /**
     * Counts exclude expired tokens; no token or account data is exported.
     */
    public function test_token_counts_do_not_export_records(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['email' => 'private-person@example.invalid']);
        $serviceid = $DB->insert_record('external_services', (object) [
            'name' => 'Private integration', 'enabled' => 1, 'restrictedusers' => 0, 'timecreated' => time(),
        ]);
        $base = ['tokentype' => 0, 'userid' => $user->id, 'externalserviceid' => $serviceid,
            'contextid' => \context_system::instance()->id, 'timecreated' => time(), 'iprestriction' => ''];
        $DB->insert_record('external_tokens', (object) ($base + ['token' => 'secret-active-token', 'validuntil' => 0]));
        $DB->insert_record('external_tokens', (object) ($base + ['token' => 'secret-expired-token', 'validuntil' => time() - 60]));
        $report = hardening_report::collect();
        $this->assertSame('observed', $report['tokens']['status']);
        $this->assertSame(1, $report['tokens']['active']);
        $this->assertSame(1, $report['tokens']['nonexpiring']);
        $this->assertSame(1, $report['tokens']['unrestricted']);
        $this->assertStringNotContainsString('secret-', json_encode($report));
        $this->assertStringNotContainsString($user->email, json_encode($report));
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $this->assertSame(0, hardening_report::collect()['tokens']['active']);
    }

    /**
     * A password-only fallback is configuration evidence, not a count of protected users.
     */
    public function test_mfa_fallback_configuration(): void {
        $this->resetAfterTest();
        if (!class_exists(\tool_mfa\plugininfo\factor::class)) {
            $this->assertSame('unsupported', hardening_report::collect()['mfa']['status']);
            return;
        }
        set_config('enabled', 1, 'tool_mfa');
        set_config('enabled', 1, 'factor_nosetup');
        set_config('weight', 100, 'factor_nosetup');
        $mfa = hardening_report::collect()['mfa'];
        $this->assertSame('observed', $mfa['status']);
        $this->assertTrue($mfa['enabled']);
        $this->assertTrue($mfa['password_only_fallback']);
        set_config('weight', 0, 'factor_nosetup');
        $this->assertFalse(hardening_report::collect()['mfa']['password_only_fallback']);
    }

    /**
     * Exceptions and rendered report text never cross the reporting boundary.
     */
    public function test_security_report_does_not_export_exception_or_rendered_text(): void {
        $describe = new \ReflectionMethod(\local_guardlms\local\security_report::class, 'describe');
        $describe->setAccessible(true);
        $check = $this->createMock(\core\check\check::class);
        $check->method('get_ref')->willReturn('core_defaultuserrole');
        $check->expects($this->never())->method('get_name');
        $check->method('get_result')->willThrowException(new \RuntimeException('private@example.invalid secret=/private/path'));
        $result = $describe->invoke(null, $check);
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('', $result['summary']);
        $this->assertSame('', $result['details']);
        $this->assertStringNotContainsString('private', json_encode($result));

        $unknown = $this->createMock(\core\check\check::class);
        $unknown->method('get_ref')->willReturn('private@example.invalid');
        $unknown->expects($this->never())->method('get_result');
        $this->assertSame([], $describe->invoke(null, $unknown));
    }

    /**
     * Null keys count as unprotected; hidden courses and disabled plugins do not count.
     */
    public function test_guest_enrolment_counts_respect_visibility_and_enabled_plugin(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enrol_plugins_enabled', 'manual,guest');
        $course = $this->getDataGenerator()->create_course(['visible' => 1, 'fullname' => 'Private course name']);
        $DB->delete_records('enrol', ['courseid' => $course->id]);
        $DB->insert_record('enrol', (object) ['enrol' => 'guest', 'status' => 0,
            'courseid' => $course->id, 'password' => null]);
        $report = hardening_report::collect();
        $this->assertSame(1, $report['enrolments']['guest_without_key']);
        $this->assertStringNotContainsString('Private course', json_encode($report));
        $DB->set_field('course', 'visible', 0, ['id' => $course->id]);
        $this->assertSame(0, hardening_report::collect()['enrolments']['guest_without_key']);
        $DB->set_field('course', 'visible', 1, ['id' => $course->id]);
        set_config('enrol_plugins_enabled', 'manual');
        $this->assertSame(0, hardening_report::collect()['enrolments']['guest_without_key']);
    }

    /**
     * A nonempty but incomplete blocklist must not imply metadata protection.
     */
    public function test_ssrf_policy_samples_and_permitted_ip_evidence(): void {
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', "127.0.0.0/8\nprivate-name.example.invalid\nsecret=value");
        set_config('curlsecurityallowedport', "80\n443");
        $report = hardening_report::collect()['ssrf'];
        $this->assertSame('observed', $report['status']);
        $this->assertFalse($report['metadata_ip_blocked']);
        $this->assertSame(['127.0.0.0/8'], $report['blocked_ip_rules']);
        $this->assertSame(2, $report['omitted_rule_count']);
        $this->assertFalse($report['non_http_ports_allowed']);
        $this->assertStringNotContainsString('private-name', json_encode($report));
        $this->assertStringNotContainsString('secret=', json_encode($report));
        set_config('curlsecurityblockedhosts', "127.0.0.0/8\n169.254.0.0/16\n10.0.0.0/8\n172.16.0.0/12"
            . "\n192.168.0.0/16\n::1\nfc00::/7\nfe80::/10");
        $report = hardening_report::collect()['ssrf'];
        foreach (
            ['metadata_ip_blocked', 'loopback_samples_blocked', 'private_samples_blocked',
                'ipv6_local_samples_blocked'] as $key
        ) {
            $this->assertTrue($report[$key], $key);
        }
        set_config('curlsecurityallowedport', "080\n0443");
        $this->assertFalse(hardening_report::collect()['ssrf']['non_http_ports_allowed']);
        set_config('curlsecurityallowedport', 'invalid-port');
        $this->assertArrayNotHasKey('non_http_ports_allowed', hardening_report::collect()['ssrf']);
        set_config('curlsecurityallowedport', '');
        $this->assertTrue(hardening_report::collect()['ssrf']['non_http_ports_allowed']);
    }

    /**
     * Inspect writable code/data placement without exporting local paths or file contents.
     */
    public function test_filesystem_reports_context_and_handles_missing_paths(): void {
        $this->resetAfterTest();
        $root = make_request_directory();
        mkdir($root . '/public');
        mkdir($root . '/public/data');
        file_put_contents($root . '/config.php', 'private fixture contents');
        $report = hardening_report::inspect_filesystem($root . '/public', $root, $root . '/public/data');
        $this->assertSame('cli', $report['runtime']);
        $this->assertTrue($report['webroot_writable']);
        $this->assertTrue($report['dataroot_in_webroot']);
        $this->assertSame(1, $report['writable_code_files']);
        $this->assertStringNotContainsString($root, json_encode($report));
        mkdir($root . '/public-other');
        $report = hardening_report::inspect_filesystem($root . '/public', $root, $root . '/public-other');
        $this->assertFalse($report['dataroot_in_webroot']);
        $report = hardening_report::inspect_filesystem($root . '/missing', $root . '/missing', $root . '/missing');
        $this->assertArrayNotHasKey('webroot_writable', $report);
        $this->assertArrayNotHasKey('writable_code_files', $report);
    }
}
