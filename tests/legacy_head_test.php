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
 * Tests for the legacy head callback that serves Moodle 3.9 to 4.3.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_guardlms\local\head_injector
 */
final class legacy_head_test extends \advanced_testcase {
    /** @var string A plausible SDK bundle URL with the content-hash cache buster. */
    private const SDK_URL = 'https://dashboard.guardlms.com/sdk/guardlms.min.js?v=abc123def456';

    /**
     * A request environment for an ordinary logged-in page view.
     *
     * Moodle's PHPUnit bootstrap defines CLI_SCRIPT as true, so sdk_tags()
     * would always take the CLI branch and emit nothing. legacy_head_html()
     * therefore takes the environment as a parameter here, exactly as
     * sdk_tags_for() does for the same reason.
     *
     * @return array
     */
    private function env(): array {
        return [
            'cli' => false,
            'ajax' => false,
            'pagelayout' => 'standard',
            'selftest' => false,
        ];
    }

    /**
     * Store the connection state that makes the ownership meta tag render.
     */
    private function set_up_connected(): void {
        set_config('enabled', 1, 'local_guardlms');
        set_config('verificationtoken', 'a-verification-token', 'local_guardlms');
    }

    /**
     * Put the site into the state where SDK injection is expected.
     */
    private function set_up_injectable(): void {
        sdk_config::store_payload([
            'key' => 'glms_' . str_repeat('a', 56),
            'key_prefix' => 'glms_aaa',
            'sdk_url' => self::SDK_URL,
            'errors_endpoint' => 'https://dashboard.guardlms.com/api/sdk/errors/collect',
            'analytics_endpoint' => 'https://dashboard.guardlms.com/api/sdk/analytics/collect',
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
     * From Moodle 4.4 the hook owns the head, so this callback emits nothing.
     *
     * Asserted while both halves would otherwise render: an empty string here
     * has to mean "the hook has it", not "there was nothing to emit".
     */
    public function test_nothing_is_emitted_when_the_hooks_api_is_available(): void {
        $this->resetAfterTest();

        $this->set_up_connected();
        $this->set_up_injectable();

        $this->assertNotSame('', head_injector::meta_tag(), 'The meta tag would render on its own.');
        $this->assertStringContainsString(
            '<script src="',
            head_injector::sdk_tags_for($this->env()),
            'The SDK would render on its own.'
        );

        $this->assertSame('', head_injector::legacy_head_html(true, $this->env()));
    }

    /**
     * A connected site with monitoring off still gets the ownership meta tag.
     */
    public function test_the_meta_tag_is_emitted_without_the_sdk(): void {
        $this->resetAfterTest();

        $this->set_up_connected();
        set_config('sdkenabled', 0, 'local_guardlms');

        $html = head_injector::legacy_head_html(false, $this->env());

        $this->assertStringContainsString('guardlms-verification', $html);
        $this->assertStringContainsString('a-verification-token', $html);
        $this->assertStringNotContainsString('<script', $html, 'Monitoring is off, so nothing loads.');
    }

    /**
     * On a Moodle without the Hooks API the SDK is injected through this path.
     *
     * This is the whole point of the legacy callback: below 4.4 it is the only
     * way into the page head, and it reaches it in the same position the 4.4
     * hook does.
     *
     * Nothing under test reads $CFG->version any more. The pin is a regression
     * guard: a reintroduced core-version gate would fail here first.
     */
    public function test_the_sdk_is_injected_through_the_legacy_callback(): void {
        global $CFG;

        $this->resetAfterTest();

        $this->set_up_connected();
        $this->set_up_injectable();
        $CFG->version = 2020061500;

        $html = head_injector::legacy_head_html(false, $this->env());

        $this->assertStringContainsString('guardlms-verification', $html);
        $this->assertStringContainsString('<script src="', $html);
        $this->assertStringContainsString(s(self::SDK_URL), $html);
        $this->assertStringContainsString('window.GuardLMS.init(', $html);
    }

    /**
     * The one-argument form lib.php uses describes the live request itself.
     *
     * Moodle's PHPUnit bootstrap defines CLI_SCRIPT, so the live request is a
     * CLI one and sdk_tags() withholds the SDK, while the meta tag is unaffected
     * by the request environment on either path. That pair is exactly what the
     * default branch has to deliver.
     */
    public function test_the_default_environment_is_the_live_request(): void {
        $this->resetAfterTest();

        $this->set_up_connected();
        $this->set_up_injectable();

        $html = head_injector::legacy_head_html(false);

        $this->assertStringContainsString('guardlms-verification', $html);
        $this->assertStringNotContainsString('<script', $html, 'CLI requests never load the SDK.');
    }
}
