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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_guardlms;

use local_guardlms\local\dependency_inventory;
use local_guardlms\local\dependency_parser;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../classes/local/dependency_parser.php');
require_once(__DIR__ . '/../classes/local/dependency_inventory.php');

/**
 * Offline metadata fixtures for old and public/ Moodle layouts.
 *
 * @package local_guardlms
 * @copyright 2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_guardlms\local\dependency_inventory
 * @covers \local_guardlms\local\dependency_parser
 */
final class dependency_inventory_test extends \PHPUnit\Framework\TestCase {
    /** @var string[] Disposable fixture files and directories. */
    private $temporary = [];

    /**
     * Remove disposable metadata fixtures.
     *
     * @return void
     */
    protected function tearDown(): void {
        foreach (array_reverse($this->temporary) as $path) {
            if (is_dir($path) && !is_link($path)) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    /**
     * Return a bundled JWT library declaration.
     *
     * @return string Minimal library metadata, absent from Composer.
     */
    private function xml(): string {
        return '<libraries><library><location>php-jwt</location><name>JWT</name><version>6.11.1</version>' .
            '<repository>https://github.com/firebase/php-jwt</repository><customised/></library></libraries>';
    }

    /**
     * Release layouts cover bundled library without composer.
     *
     * @return void
     */
    public function test_release_layouts_cover_bundled_library_without_composer(): void {
        foreach (['4.1' => '', '4.5' => '', '5.3' => 'public/'] as $release => $prefix) {
            $result = (new dependency_parser())->parse([$prefix . 'lib/thirdpartylibs.xml' => $this->xml()]);
            $this->assertSame('complete', $result['status'], $release);
            $this->assertSame('firebase/php-jwt', $result['packages'][0]['name'], $release);
            $this->assertSame('lib/php-jwt', $result['packages'][0]['path'], $release);
            $this->assertTrue($result['packages'][0]['customised'], $release);
        }
    }

    /**
     * External xml unknown versions and escaping paths are unassessed.
     *
     * @return void
     */
    public function test_external_xml_unknown_versions_and_escaping_paths_are_unassessed(): void {
        $result = (new dependency_parser())->parse(['lib/thirdpartylibs.xml' =>
            '<!DOCTYPE libraries [<!ENTITY x SYSTEM "file:///etc/passwd">]><libraries/>']);
        $this->assertSame('partial', $result['status']);
        $this->assertSame([], $result['packages']);
        $this->assertFalse(dependency_parser::exact_version('^6.0'));
        $this->assertFalse(dependency_parser::exact_version('6.1-custom'));
        $this->expectException(\InvalidArgumentException::class);
        dependency_parser::relative_path('../outside');
    }

    /**
     * Two plugin copies are retained and installed versions are cross checked.
     *
     * @return void
     */
    public function test_two_plugin_copies_are_retained_and_installed_versions_are_cross_checked(): void {
        $files = [];
        foreach (['saml2', 'oidc'] as $name) {
            $files['auth/' . $name . '/version.php'] = '<?php $plugin->component = "auth_' . $name . '";';
            $files['auth/' . $name . '/composer.lock'] = json_encode(['packages' => [
                ['name' => 'firebase/php-jwt', 'version' => '6.11.1'],
            ]]);
            $files['auth/' . $name . '/vendor/composer/installed.json'] = json_encode(['packages' => [
                ['name' => 'firebase/php-jwt', 'version' => '7.0.0', 'install-path' => '../firebase/php-jwt'],
            ]]);
        }
        $packages = (new dependency_parser())->parse($files)['packages'];
        $this->assertCount(2, $packages);
        $this->assertSame('7.0.0', $packages[0]['version']);
        $this->assertSame('installed_metadata', $packages[0]['evidence_type']);
        $this->assertTrue($packages[0]['version_mismatch']);
        $this->assertSame('auth_saml2', $packages[0]['component']);
        $this->assertSame('auth_oidc', $packages[1]['component']);
    }

    /**
     * Missing manifest is partial and source code is never executed.
     *
     * @return void
     */
    public function test_missing_manifest_is_partial_and_source_code_is_never_executed(): void {
        $root = sys_get_temp_dir() . '/guardlms-dependencies-' . bin2hex(random_bytes(8));
        mkdir($root);
        $this->temporary[] = $root;
        $file = $root . '/version.php';
        file_put_contents($file, '<?php throw new RuntimeException("MUST NEVER EXECUTE");');
        $this->temporary[] = $file;
        $result = dependency_inventory::collect_from_root($root);
        $this->assertSame('partial', $result['status']);
        $this->assertSame([], $result['packages']);
        $this->assertArrayHasKey('observed_at', $result);
        $this->assertStringNotContainsString($root, json_encode($result));
    }

    /**
     * Npm instances and unpinned composer packages are not lost.
     *
     * @return void
     */
    public function test_npm_instances_and_unpinned_composer_packages_are_not_lost(): void {
        $result = (new dependency_parser())->parse([
            'composer.lock' => json_encode(['packages' => [['name' => 'vendor/library', 'version' => 'dev-main']]]),
            'package-lock.json' => json_encode(['packages' => [
                'node_modules/jquery' => ['version' => '3.4.0'],
                'node_modules/parent/node_modules/jquery' => ['version' => '3.5.0'],
            ]]),
        ]);
        $this->assertCount(3, $result['packages']);
        $this->assertNull($result['packages'][0]['version']);
        $this->assertNotSame($result['packages'][1]['path'], $result['packages'][2]['path']);
    }
}
