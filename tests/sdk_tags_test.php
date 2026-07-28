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

use local_guardlms\local\head_injector;
use local_guardlms\local\sdk_config;

/**
 * Tests for the real-time monitoring script injection.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_guardlms\local\head_injector
 */
final class sdk_tags_test extends \advanced_testcase {
    /** @var string A plausible SDK bundle URL with the content-hash cache buster. */
    private const SDK_URL = 'https://app.guardlms.com/sdk/guardlms.min.js?v=abc123def456';

    /**
     * A request environment for an ordinary logged-in page view.
     *
     * @param array $overrides Fields to replace.
     * @return array
     */
    private function env(array $overrides = []): array {
        return $overrides + [
            'cli' => false,
            'ajax' => false,
            'pagelayout' => 'standard',
            'selftest' => false,
        ];
    }

    /**
     * Put the site into the state where injection is expected.
     */
    private function set_up_injectable(): void {
        global $CFG;

        $CFG->version = sdk_config::HOOKS_API_VERSION;
        sdk_config::store_payload([
            'key' => 'glms_' . str_repeat('a', 56),
            'key_prefix' => 'glms_aaa',
            'sdk_url' => self::SDK_URL,
            'errors_endpoint' => 'https://app.guardlms.com/api/sdk/errors/collect',
            'analytics_endpoint' => 'https://app.guardlms.com/api/sdk/analytics/collect',
            'enabled' => true,
            'subscription_active' => true,
            'analytics_allowed' => true,
            'sample_rate' => 1.0,
            'analytics_sample_rate' => 0.5,
            'max_breadcrumbs' => 50,
            'max_errors_per_minute' => 60,
            'ignored_errors' => [],
            'allowed_domains' => [],
            'allowed_domains_match' => true,
        ]);
        set_config('sdkenabled', 1, 'local_guardlms');
        set_config('sdkanalytics', 0, 'local_guardlms');
    }

    /**
     * Pull the emitted GuardLMS.init payload back out of the rendered tags.
     *
     * Compares against a re-encode of the config under test rather than parsing
     * the HTML, so the assertion is exact about the bytes that ship.
     *
     * @param string $tags Rendered tags.
     * @return array The decoded init config.
     */
    private function emitted_config(string $tags): array {
        $config = head_injector::sdk_init_config();
        $json = json_encode(
            $config,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );

        $this->assertStringContainsString(
            'window.GuardLMS.init(' . $json . ');',
            $tags,
            'The emitted payload must be exactly the config sdk_init_config() built.'
        );

        return $config;
    }

    /**
     * E2: an ordinary page gets the SDK, and so does an admin page.
     *
     * The declared difference from the WordPress plugin, which has is_admin()
     * to exclude. Moodle has no front-end/back-end split, and admin-page errors
     * are among the most valuable to catch.
     */
    public function test_tags_emitted_on_a_standard_and_an_admin_page(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        foreach (['standard', 'admin', 'course', 'incourse', 'report'] as $pagelayout) {
            $tags = head_injector::sdk_tags_for($this->env(['pagelayout' => $pagelayout]));
            $this->assertStringContainsString('guardlms.min.js', $tags, "Layout {$pagelayout} must be monitored.");
            $this->assertStringContainsString('window.GuardLMS.init(', $tags);
        }
    }

    /**
     * E1: the login layout is excluded.
     *
     * Login pages carry credentials in form fields, which is the highest-risk
     * page on the site for near-zero diagnostic value.
     */
    public function test_nothing_emitted_on_the_login_layout(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $this->assertSame('', head_injector::sdk_tags_for($this->env(['pagelayout' => 'login'])));
    }

    /**
     * E1: no injection under CLI or in an AJAX response.
     */
    public function test_nothing_emitted_under_cli_or_ajax(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $this->assertSame('', head_injector::sdk_tags_for($this->env(['cli' => true])));
        $this->assertSame('', head_injector::sdk_tags_for($this->env(['ajax' => true])));
    }

    /**
     * E1: the zero-argument entry point honours the real request environment.
     *
     * This suite runs under Moodle's PHPUnit bootstrap, which defines
     * CLI_SCRIPT as true, so a bare sdk_tags() call genuinely exercises the CLI
     * guard against the real constant rather than against a passed-in array.
     * That is also precisely why every other guard is tested through
     * sdk_tags_for(): under CLI they would all be unreachable.
     */
    public function test_bare_entry_point_reads_the_real_request_environment(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $this->assertTrue(CLI_SCRIPT, 'This test is only meaningful while the suite runs under CLI.');
        $this->assertTrue(head_injector::current_request_env()['cli']);
        $this->assertSame('', head_injector::sdk_tags());
    }

    /**
     * E1: each stored precondition suppresses injection on its own.
     */
    public function test_nothing_emitted_when_a_precondition_fails(): void {
        $this->resetAfterTest();

        $cases = [
            'sdkenabled' => 0,
            'sdkbackendenabled' => 0,
            'sdksubscriptionactive' => 0,
            'sdkkey' => '',
            'sdkurl' => '',
        ];

        foreach ($cases as $key => $value) {
            $this->set_up_injectable();
            // Prove the fixture injects before the knockout, so a broken fixture
            // cannot make this test pass by emitting nothing either way.
            $this->assertNotSame('', head_injector::sdk_tags_for($this->env()));

            set_config($key, $value, 'local_guardlms');
            $this->assertSame(
                '',
                head_injector::sdk_tags_for($this->env()),
                "Injection must stop when {$key} is {$value}."
            );
        }
    }

    /**
     * E3: the src survives clean_param(PARAM_URL) unchanged, cache buster included.
     */
    public function test_script_src_survives_clean_param(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $this->assertSame(self::SDK_URL, head_injector::script_src());
        $this->assertStringContainsString('?v=abc123def456', head_injector::script_src());
    }

    /**
     * E3: anything that is not https is refused.
     */
    public function test_non_https_script_url_is_rejected(): void {
        $this->resetAfterTest();

        foreach (
            [
            'http://app.guardlms.com/sdk/guardlms.min.js',
            'javascript:alert(1)',
            '//app.guardlms.com/sdk/guardlms.min.js',
            '/sdk/guardlms.min.js',
            'ftp://app.guardlms.com/sdk/guardlms.min.js',
            'not a url at all',
            ] as $url
        ) {
            $this->set_up_injectable();
            set_config('sdkurl', $url, 'local_guardlms');

            $this->assertSame('', head_injector::script_src(), "{$url} must not be emitted as a script source.");
            $this->assertSame('', head_injector::sdk_tags_for($this->env()));
        }
    }

    /**
     * E4 / §4.1: the emitted option set is exactly what the plan specifies.
     */
    public function test_emitted_config_matches_the_specified_option_set(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $config = $this->emitted_config(head_injector::sdk_tags_for($this->env()));

        $this->assertSame([
            'apiKey',
            'endpoint',
            'appVersion',
            'releaseStage',
            'sampleRate',
            'maxBreadcrumbs',
            'maxErrorsPerMinute',
            'collectUserIp',
            'interactionBreadcrumbsEnabled',
            'enabledBreadcrumbTypes',
            'redactedKeys',
            'ignoreErrors',
        ], array_keys($config));

        $this->assertSame('production', $config['releaseStage']);
        $this->assertFalse($config['collectUserIp']);
        $this->assertFalse($config['interactionBreadcrumbsEnabled']);
        $this->assertMatchesRegularExpression('/^moodle-/', $config['appVersion']);
    }

    /**
     * With analytics active the key set is the full canonical §4.1 set.
     *
     * The canonical set is locked in the SDK repo's README, but that repo's
     * structural test compares the README against its own constant and has no
     * access to this one. It catches README drift, not plugin drift. This is
     * the only guard on what this plugin actually emits.
     */
    public function test_emitted_config_matches_the_canonical_set_with_analytics(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();
        set_config('sdkanalytics', 1, 'local_guardlms');

        $this->assertSame([
            'apiKey',
            'endpoint',
            'appVersion',
            'releaseStage',
            'sampleRate',
            'maxBreadcrumbs',
            'maxErrorsPerMinute',
            'collectUserIp',
            'interactionBreadcrumbsEnabled',
            'enabledBreadcrumbTypes',
            'redactedKeys',
            'ignoreErrors',
            'analytics',
        ], array_keys(head_injector::sdk_init_config()));
    }

    /**
     * The redaction list is exactly the canonical one, in order.
     *
     * Asserted as a whole rather than only spot-checking sesskey: the list is a
     * full replacement of the SDK's defaults, so a dropped entry is a silent
     * leak with nothing else in the system to catch it.
     */
    public function test_redacted_keys_are_exactly_the_canonical_list(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $this->assertSame([
            'password',
            'secret',
            'token',
            'apiKey',
            'api_key',
            'authorization',
            'sesskey',
            'nonce',
        ], head_injector::sdk_init_config()['redactedKeys']);
    }

    /**
     * §4.1: batchInterval is never emitted.
     *
     * Its stored value drifted three ways across the backend and is enforced
     * nowhere, so emitting it could only regress flush latency.
     */
    public function test_batch_interval_is_never_emitted(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $tags = head_injector::sdk_tags_for($this->env());

        $this->assertArrayNotHasKey('batchInterval', head_injector::sdk_init_config());
        $this->assertStringNotContainsString('batchInterval', $tags);
    }

    /**
     * X1: setUser is never called and no user object is emitted.
     */
    public function test_no_user_identity_is_ever_emitted(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->set_up_injectable();

        $tags = head_injector::sdk_tags_for($this->env());
        $this->assertStringNotContainsString('setUser', $tags);
        $this->assertArrayNotHasKey('user', head_injector::sdk_init_config());

        // A source scan, so a future edit that reintroduces identity anywhere in
        // the plugin fails here rather than in a privacy review.
        $sources = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($CFG->dirroot . '/local/guardlms')
        );
        foreach ($directory as $file) {
            if (
                $file->isFile() && $file->getExtension() === 'php'
                && strpos($file->getPathname(), '/tests/') === false
            ) {
                $sources[$file->getPathname()] = file_get_contents($file->getPathname());
            }
        }
        $this->assertNotEmpty($sources, 'The scan found no sources, which would make it vacuous.');

        foreach ($sources as $path => $contents) {
            foreach (['->setUser(', '.setUser(', "['setUser']", '"setUser"'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$path} references setUser.");
            }
        }
    }

    /**
     * §4.1: breadcrumb collection excludes clicks and form entries.
     */
    public function test_no_interaction_breadcrumbs_are_collected(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $types = head_injector::sdk_init_config()['enabledBreadcrumbTypes'];

        $this->assertSame(['navigation', 'network', 'console', 'user'], $types);
        $this->assertNotContains('click', $types);
        $this->assertNotContains('form', $types);
    }

    /**
     * §4.1: sesskey and nonce are redacted, and a bare "key" is not.
     */
    public function test_redacted_keys_cover_the_moodle_csrf_token(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $redacted = head_injector::sdk_init_config()['redactedKeys'];

        $this->assertContains('sesskey', $redacted, 'Moodle puts its CSRF token in nearly every URL.');
        $this->assertContains('nonce', $redacted);
        $this->assertNotContains(
            'key',
            $redacted,
            'Matching is case-insensitive substring, so a bare key would redact half the payload.'
        );
    }

    /**
     * E4: the analytics block appears only with the entitlement and the opt-in.
     */
    public function test_analytics_block_needs_both_the_plan_and_the_opt_in(): void {
        $this->resetAfterTest();

        $this->set_up_injectable();
        $this->assertArrayNotHasKey('analytics', head_injector::sdk_init_config(), 'Not opted in.');

        set_config('sdkanalytics', 1, 'local_guardlms');
        $config = head_injector::sdk_init_config();
        $this->assertArrayHasKey('analytics', $config);
        $this->assertTrue($config['analytics']['enabled']);
        $this->assertSame(0.5, $config['analytics']['sampleRate']);
        $this->assertTrue($config['analytics']['trackScrollDepth']);

        set_config('sdkanalyticsallowed', 0, 'local_guardlms');
        $this->assertArrayNotHasKey('analytics', head_injector::sdk_init_config(), 'Plan does not include analytics.');
    }

    /**
     * A closing script tag in a payload value cannot terminate the inline block.
     */
    public function test_a_closing_script_tag_in_a_value_cannot_break_out(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $hostile = '</script><script>window.pwned=1;//';
        set_config('sdkerrorsendpoint', 'https://app.guardlms.com/collect?x=' . $hostile, 'local_guardlms');

        $tags = head_injector::sdk_tags_for($this->env());

        // Exactly two script elements: the src tag and the init block.
        $this->assertSame(2, substr_count($tags, '<script'), 'A third script element means the payload broke out.');
        $this->assertSame(2, substr_count($tags, '</script>'));
        $this->assertStringNotContainsString('window.pwned', $tags);
    }

    /**
     * A hostile SDK URL is refused outright rather than escaped into the page.
     *
     * clean_param(PARAM_URL) is the gate that stops this, which is worth
     * asserting explicitly: s() on the attribute is the second layer, and a
     * test that only checked the rendered output would pass just as happily if
     * the first layer were removed.
     */
    public function test_a_hostile_script_url_is_rejected_before_it_is_rendered(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $hostile = 'https://app.guardlms.com/a.js?x="><script>window.pwned=1;</script>';
        set_config('sdkurl', $hostile, 'local_guardlms');

        $this->assertSame('', head_injector::script_src(), 'clean_param must refuse this URL.');

        $tags = head_injector::sdk_tags_for($this->env());
        $this->assertSame('', $tags, 'No usable source means nothing is emitted at all.');
    }

    /**
     * A URL that clean_param does accept is still escaped into the attribute.
     */
    public function test_script_src_is_escaped_into_the_attribute(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        // Ampersands survive clean_param, and an unescaped one is malformed HTML.
        set_config('sdkurl', 'https://app.guardlms.com/a.js?v=1&x=2', 'local_guardlms');

        $tags = head_injector::sdk_tags_for($this->env());

        $this->assertNotSame('', $tags);
        $this->assertStringContainsString('src="' . s('https://app.guardlms.com/a.js?v=1&x=2') . '"', $tags);
        $this->assertStringContainsString('&amp;', $tags);
    }

    /**
     * The init call is guarded, so a bundle that fails to load raises nothing.
     *
     * Without the guard, an unreachable CDN would put a ReferenceError on every
     * page of the site.
     */
    public function test_init_is_guarded_against_a_bundle_that_never_loaded(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $this->assertStringContainsString('if(window.GuardLMS){', head_injector::sdk_tags_for($this->env()));
    }

    /**
     * E5: a charset declaration lands inside the HTML5 sniffing window.
     *
     * Core appends its own charset meta to the same hook after plugin callbacks
     * (lib/outputrenderers.php:718, collected at :734), so this block precedes
     * it and pushes it further into the head. HTML5 sniffs only the first 1024
     * bytes. This block does not fit in that budget - it measures 1151 bytes
     * with analytics on - so the plugin declares utf-8 itself, ahead of the
     * scripts. That is what this asserts: the declaration is early and before
     * any script, which stays true however large the payload grows.
     */
    public function test_charset_is_declared_before_the_scripts(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();
        set_config('sdkanalytics', 1, 'local_guardlms');

        // The realistic worst case: analytics on, plus dashboard-supplied
        // ignore entries on top of the curated list.
        set_config('sdkignorederrors', json_encode([
            'A dashboard supplied ignore pattern',
            'Another dashboard supplied ignore pattern',
        ]), 'local_guardlms');
        set_config('verificationtoken', str_repeat('t', 64), 'local_guardlms');
        set_config('enabled', 1, 'local_guardlms');

        $emitted = head_injector::meta_tag() . head_injector::sdk_tags_for($this->env());

        $charsetat = strpos($emitted, '<meta charset="utf-8">');
        $firstscriptat = strpos($emitted, '<script');

        $this->assertNotFalse($charsetat, 'The plugin must declare the charset itself.');
        $this->assertNotFalse($firstscriptat);
        $this->assertLessThan($firstscriptat, $charsetat, 'The charset must precede the scripts.');
        $this->assertLessThan(
            1024,
            $charsetat,
            'The charset declaration must land inside the window HTML5 sniffs.'
        );
    }

    /**
     * The self-test probe rides along only when the request asked for it.
     */
    public function test_self_test_probe_is_opt_in_per_request(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $this->assertStringNotContainsString('self-test', head_injector::sdk_tags_for($this->env()));

        $probed = head_injector::sdk_tags_for($this->env(['selftest' => true]));
        $this->assertStringContainsString('GuardLMS self-test error', $probed);
        // Inside the load guard, so a bundle that never arrived stays silent.
        $this->assertStringContainsString('if(window.GuardLMS){', $probed);
    }

    /**
     * The self-test flag is refused without the site configuration capability.
     */
    public function test_self_test_requires_site_config(): void {
        $this->resetAfterTest();
        $this->set_up_injectable();

        $_GET['guardlmsselftest'] = 1;
        try {
            $this->setUser($this->getDataGenerator()->create_user());
            $asuser = head_injector::current_request_env()['selftest'];

            $this->setAdminUser();
            $asadmin = head_injector::current_request_env()['selftest'];
        } finally {
            // Not covered by resetAfterTest, so it has to be undone by hand or
            // it leaks into every later test in the process.
            unset($_GET['guardlmsselftest']);
        }

        $this->assertFalse($asuser, 'An ordinary user must not be able to write into the site error feed.');
        $this->assertTrue($asadmin);
        $this->assertFalse(
            head_injector::current_request_env()['selftest'],
            'Without the query flag the probe is off even for an admin.'
        );
    }

    /**
     * The hook callback appends the SDK tags to the existing meta tag output.
     */
    public function test_hook_callback_appends_to_the_existing_head_output(): void {
        $this->resetAfterTest();

        if (!class_exists(\core\hook\output\before_standard_head_html_generation::class)) {
            $this->markTestSkipped('The Hooks API needs Moodle 4.4 or later.');
        }

        $this->set_up_injectable();
        set_config('enabled', 1, 'local_guardlms');
        set_config('verificationtoken', 'a-verification-token', 'local_guardlms');

        global $PAGE;

        $hook = new \core\hook\output\before_standard_head_html_generation($PAGE->get_renderer('core'));
        hook_callbacks::before_standard_head_html_generation($hook);
        $output = $hook->get_output();

        $this->assertStringContainsString('guardlms-verification', $output);
        // Under CLI the SDK tags are correctly absent; what this asserts is that
        // the callback emits the meta tag through the same single registration
        // rather than needing a second hook.
        $this->assertStringContainsString('a-verification-token', $output);
    }
}
