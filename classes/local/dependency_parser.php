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


/**
 * Parses metadata without executing dependency PHP or resolving version constraints.
 *
 * @package local_guardlms
 * @copyright 2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dependency_parser
{
    /** @var array Component directory to frankenstyle identifier. */
    private array $components = [];

    /** @var bool Whether metadata exceeded the package limit. */
    private bool $truncated = false;

    /**
     * Parse a bounded set of metadata files.
     *
     * @param array $files Relative filenames and contents.
     * @return array Versioned package inventory.
     */
    public function parse(array $files): array {
        $this->components = [];
        $this->truncated = false;
        foreach ($files as $file => $contents) {
            if (
                self::ends_with($file, '/version.php') && is_string($contents)
                && preg_match('/\\$plugin->component\\s*=\\s*[\"\']([a-z][a-z0-9_]+)[\"\']/', $contents, $m)
            ) {
                $this->components[preg_replace('#^public/#', '', dirname($file))] = $m[1];
            }
        }
        $packages = [];
        $status = 'complete';
        foreach ($files as $file => $contents) {
            if (self::ends_with($file, 'version.php')) {
                continue;
            }
            $file = preg_replace('#^public/#', '', $file);
            if (! is_string($contents) || strlen($contents) > 2 * 1024 * 1024) {
                $status = 'partial';
                continue;
            }
            try {
                $rows = self::ends_with($file, 'thirdpartylibs.xml')
                    ? $this->xml($file, $contents)
                    : $this->json($file, $contents);
                foreach ($rows as $row) {
                    $identity = self::identity($row);
                    if (count($packages) >= 2000 && ! isset($packages[$identity])) {
                        $this->truncated = true;
                        break 2;
                    }
                    if (isset($packages[$identity])) {
                        $existing = $packages[$identity];
                        $mismatch = $existing['version'] !== null && $row['version'] !== null
                            && $existing['version'] !== $row['version'];
                        $preferred = $row['evidence_type'] === 'installed_metadata' ? $row : $existing;
                        $row = array_merge($preferred, [
                            'customised' => $existing['customised'] || $row['customised'],
                            'version_mismatch' => $existing['version_mismatch'] || $row['version_mismatch'] || $mismatch,
                            'dev' => $existing['dev'] || $row['dev'],
                        ]);
                    }
                    $packages[$identity] = $row;
                }
            } catch (\Throwable $exception) {
                $status = 'partial';
            }
        }

        return ['schema_version' => 1, 'status' => $this->truncated ? 'partial' : $status, 'packages' => array_values($packages)];
    }

    /**
     * Identify a package at one component and location.
     *
     * @param array $package Package metadata.
     * @return string Stable hash.
     */
    public static function identity(array $package): string {
        return hash('sha256', json_encode([
            $package['component'], $package['path'], $package['ecosystem'] ?? null, strtolower($package['name']),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Accept exact versions supported by package advisories.
     *
     * @param ?string $version Declared version.
     * @return bool Whether it can be matched.
     */
    public static function exact_version(?string $version): bool {
        return is_string($version) && strlen($version) <= 100 && ! preg_match('/custom|patched|moodle/i', $version)
            && preg_match('/^v?\d+\.\d+(?:\.\d+){0,2}(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version) === 1;
    }

    /**
     * Read a third-party library declaration without external entities.
     *
     * @param string $file Relative manifest filename.
     * @param string $contents XML text.
     * @return array Declared packages.
     */
    private function xml(string $file, string $contents): array {
        if (stripos($contents, '<!DOCTYPE') !== false || stripos($contents, '<!ENTITY') !== false) {
            throw new \InvalidArgumentException('External XML declarations are unsupported');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contents, \SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false || $xml->getName() !== 'libraries') {
                throw new \InvalidArgumentException('Invalid library manifest');
            }
            $rows = [];
            foreach ($xml->library as $library) {
                if (count($rows) >= 2000) {
                    $this->truncated = true;
                    break;
                }
                $repository = strtolower(preg_replace('#\.git$#', '', rtrim((string) $library->repository, '/')));
                $key = preg_replace('#^https?://(?:www\.)?github\.com/#', '', $repository);
                // Dot-containing repository keys cannot be addressed with config dot notation.
                $mapped = self::library_mappings()[$key] ?? null;
                $location = self::relative_path(dirname($file) . '/' . (string) $library->location);
                $version = trim((string) $library->version);
                $rows[] = [
                    'ecosystem' => $mapped[0] ?? null,
                    'name' => $mapped[1] ?? trim((string) $library->name),
                    'version' => self::exact_version($version) ? ltrim($version, 'v') : null,

                'declared_version' => mb_substr($version, 0, 100),
                    'component' => $this->component_for_file($file),
                    'path' => $location,
                    'evidence_type' => 'bundled_manifest',
                    'dev' => self::contains($location, '/behat/') || self::contains($location, '/tests/'),
                    'customised' => isset($library->customised),
                    'version_mismatch' => false,
                ];
            }

            return $rows;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Read pinned or installed package metadata.
     *
     * @param string $file Relative metadata filename.
     * @param string $contents JSON text.
     * @return array Package observations.
     */
    private function json(string $file, string $contents): array {
        $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new \InvalidArgumentException('Metadata must be a JSON object or package list');
        }
        $directory = dirname($file) === '.' ? '' : dirname($file) . '/';
        $rows = [];
        if (self::ends_with($file, 'composer.lock') || self::ends_with($file, 'vendor/composer/installed.json')) {
            $installed = self::ends_with($file, 'installed.json');
            if (
                (! $installed && ! isset($data['packages']))
                || (isset($data['packages']) && ! is_array($data['packages']))
                || (isset($data['packages-dev']) && ! is_array($data['packages-dev']))
            ) {
                throw new \InvalidArgumentException('Incomplete Composer metadata');
            }
            $groups = $installed ? [$data['packages'] ?? $data] : [$data['packages'] ?? [], $data['packages-dev'] ?? []];
            foreach ($groups as $groupindex => $group) {
                foreach ($group as $package) {
                    if (count($rows) >= 2000) {
                        $this->truncated = true;
                        break;
                    }
                    if (! is_array($package) || ! isset($package['name'])) {
                        throw new \InvalidArgumentException('Invalid Composer package metadata');
                    }
                    if ($installed) {
                        $path = $package['install-path'] ?? null;
                        $evidence = 'installed_metadata';
                    } else {
                        $path = 'vendor/' . $package['name'];
                        $evidence = 'lockfile';
                    }
                    if (! is_string($path)) {
                        continue;
                    }
                    $rows[] = $this->package(
                        'composer',
                        $package['name'],
                        ($package['version'] ?? ''),
                        $file,
                        self::relative_path($directory . $path),
                        $evidence,
                        $groupindex === 1 || in_array($package['name'], $data['dev-package-names'] ?? [], true)
                    );
                }
            }
        } else if (self::ends_with($file, 'package-lock.json')) {
            if (! isset($data['packages']) && isset($data['dependencies']) && is_array($data['dependencies'])) {
                $stack = [[$data['dependencies'], $directory, false]];
                while ($stack !== []) {
                    [$dependencies, $prefix, $parentdev] = array_pop($stack);
                    foreach ($dependencies as $name => $package) {
                        if (! is_array($package) || count($rows) >= 2000) {
                            throw new \InvalidArgumentException('Incomplete legacy npm inventory');
                        }
                        $path = self::relative_path($prefix . 'node_modules/' . $name);
                        $dev = $parentdev || ! empty($package['dev']);
                        $rows[] = $this->package('npm', $name, $package['version'] ?? '', $file, $path, 'lockfile', $dev);
                        if (isset($package['dependencies'])) {
                            if (! is_array($package['dependencies'])) {
                                throw new \InvalidArgumentException('Malformed npm dependency tree');
                            }
                            $stack[] = [$package['dependencies'], $path . '/', $dev];
                        }
                    }
                }
                return $rows;
            }
            if (! isset($data['packages']) || ! is_array($data['packages'])) {
                throw new \InvalidArgumentException('Missing npm package inventory');
            }

            foreach ($data['packages'] as $path => $package) {
                if (count($rows) >= 2000) {
                    $this->truncated = true;
                    break;
                }
                if ($path === '') {
                    continue;
                }
                $name = $package['name'] ?? preg_replace('#^.*node_modules/#', '', $path);
                $rows[] = $this->package(
                    'npm',
                    $name,
                    ($package['version'] ?? ''),
                    $file,
                    self::relative_path($directory . $path),
                    'lockfile',
                    (bool) ($package['dev'] ?? false)
                );
            }
        } else if (self::ends_with($file, 'package.json') && self::contains($file, 'node_modules/')) {
            if (isset($data['name']) && self::exact_version($data['version'] ?? null)) {
                $rows[] = $this->package(
                    'npm',
                    $data['name'],
                    $data['version'],
                    $file,
                    self::relative_path(dirname($file)),
                    'installed_metadata',
                    false
                );
            }
        }

        return $rows;
    }

    /**
     * Build one bounded package observation.
     *
     * @param string $ecosystem Package ecosystem.
     * @param string $name Canonical package name.
     * @param string $version Declared version.
     * @param string $file Metadata filename.
     * @param string $path Relative install location.
     * @param string $evidence Evidence type.
     * @param bool $dev Whether it is development-only.
     * @return array Package metadata.
     */
    private function package(
        string $ecosystem,
        string $name,
        string $version,
        string $file,
        string $path,
        string $evidence,
        bool $dev
    ): array {
        return ['ecosystem' => $ecosystem, 'name' => $name,
            'version' => self::exact_version($version) ? ltrim($version, 'v') : null,
            'declared_version' => mb_substr($version, 0, 100),
            'component' => $this->component_for_file($file), 'path' => $path, 'evidence_type' => $evidence,
            'dev' => $dev, 'customised' => false, 'version_mismatch' => false];
    }

    /**
     * Resolve the nearest declared Moodle component.
     *
     * @param string $file Metadata filename.
     * @return string Component identifier.
     */
    private function component_for_file(string $file): string {
        $best = '';
        $component = self::component($file);
        foreach ($this->components as $directory => $name) {
            if (self::starts_with($file, $directory . '/') && strlen($directory) > strlen($best)) {
                $best = $directory;
                $component = $name;
            }
        }
        return $component;
    }

    /**
     * Resolve standard component paths when no version declaration exists.
     *
     * @param string $path Relative filename.
     * @return string Component identifier.
     */
    public static function component(string $path): string {
        $path = preg_replace('#^public/#', '', $path);
        if (preg_match('#^admin/tool/([^/]+)/#', $path, $m)) {
            return 'tool_' . $m[1];
        }
        if (preg_match('#^lib/editor/([^/]+)/#', $path, $m)) {
            return 'editor_' . $m[1];
        }
        $pattern = '#^(mod|auth|enrol|local|blocks|theme|filter|repository|question/type|question/behaviour)/([^/]+)/#';
        if (preg_match($pattern, $path, $m)) {
            $type = ['blocks' => 'block', 'question/type' => 'qtype', 'question/behaviour' => 'qbehaviour'][$m[1]] ?? $m[1];

            return $type . '_' . $m[2];
        }

        return 'core';
    }

    /**
     * Normalize a relative path without permitting root escape.
     *
     * @param string $path Relative input.
     * @return string Normalized path.
     */
    public static function relative_path(string $path): string {
        if (strlen($path) > 500 || self::starts_with($path, '/') || self::contains($path, '\\') || self::contains($path, "\0")) {
            throw new \InvalidArgumentException('Only relative paths are accepted');
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    throw new \InvalidArgumentException('Path escapes Moodle root');
                }
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
    /**
     * Read reviewed repository to package mappings.
     *
     * @return array Mapping dictionary.
     */
    private static function library_mappings(): array {
        static $mappings = null;
        if ($mappings === null) {
            $mappings = json_decode(file_get_contents(__DIR__ . '/../../resources/dependency-libraries.json'), true);
        }
        return $mappings;
    }

    /**
     * Compare a prefix on PHP 7.4.
     *
     * @param string $value Input text.
     * @param string $prefix Prefix to check.
     * @return bool Whether it matches.
     */
    private static function starts_with(string $value, string $prefix): bool {
        return strpos($value, $prefix) === 0;
    }

    /**
     * Compare a suffix on PHP 7.4.
     *
     * @param string $value Input text.
     * @param string $suffix Suffix to check.
     * @return bool Whether it matches.
     */
    private static function ends_with(string $value, string $suffix): bool {
        return $suffix === '' || substr($value, -strlen($suffix)) === $suffix;
    }

    /**
     * Find a substring on PHP 7.4.
     *
     * @param string $value Input text.
     * @param string $needle Text to find.
     * @return bool Whether it occurs.
     */
    private static function contains(string $value, string $needle): bool {
        return strpos($value, $needle) !== false;
    }
}
