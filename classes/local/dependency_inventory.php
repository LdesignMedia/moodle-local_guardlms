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

namespace local_guardlms\local;

/**
 * Bounded, metadata-only inventory; never executes PHP supplied by dependencies.
 *
 * @package local_guardlms
 * @copyright 2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dependency_inventory {
    /** Metadata files and returned packages are independently bounded. */
    public const MAX_FILES = 2000;
    /** Bound directory traversal independently of how many manifests are found. */
    public const MAX_ENTRIES = 150000;
    /** Bound individual metadata file reads. */
    public const MAX_BYTES = 2097152;
    /** Bound retained metadata memory independently of individual file size. */
    public const MAX_TOTAL_BYTES = 16777216;
    /** Bound time spent walking the code tree. */
    public const MAX_SECONDS = 10;

    /**
     * Read the active Moodle code tree and project-level dependency metadata.
     *
     * @return array Versioned dependency envelope.
     */
    public static function collect(): array {
        global $CFG;
        $coderoot = realpath($CFG->dirroot);
        if (!$coderoot) {
            return self::unavailable();
        }
        $projectroot = basename($coderoot) === 'public' && is_file(dirname($coderoot) . '/composer.json')
            ? dirname($coderoot) : $coderoot;
        return self::collect_from_root($projectroot, isset($CFG->dataroot) ? (realpath($CFG->dataroot) ?: '') : '');
    }

    /**
     * Collect one code root, also usable for offline release-layout fixtures.
     *
     * @param string $root Project root; never the data directory.
     * @param string $excludedroot Data directory to exclude when it is inside the code root.
     * @return array Only software metadata and relative paths.
     */
    public static function collect_from_root(string $root, string $excludedroot = ''): array {
        $root = realpath($root);
        if (!$root) {
            return self::unavailable();
        }
        $files = [];
        $bytes = 0;
        $partial = false;
        foreach (
            ['lib/thirdpartylibs.xml', 'public/lib/thirdpartylibs.xml', 'composer.lock',
                'package-lock.json', 'vendor/composer/installed.json'] as $seed
        ) {
            $path = $root . '/' . $seed;
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            if (!is_readable($path) || filesize($path) > self::MAX_BYTES) {
                $partial = true;
                continue;
            }
            $contents = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
            if ($contents === false || strlen($contents) > self::MAX_BYTES) {
                $partial = true;
                continue;
            }
            $bytes += strlen($contents);
            $files[$seed] = $contents;
        }
        $entries = 0;
        $started = microtime(true);
        // No FOLLOW_SYMLINKS: code-root symlinks must not expose files outside the project.
        try {
            $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
            $filter = new \RecursiveCallbackFilterIterator($directory, static function ($file) use (&$partial, $excludedroot) {
                if ($excludedroot !== '' && $file->getPathname() === $excludedroot) {
                    return false;
                }
                if ($file->isLink()) {
                    if (
                        $file->isDir() || in_array(
                            strtolower($file->getFilename()),
                            ['thirdpartylibs.xml', 'installed.json', 'composer.lock', 'package-lock.json', 'package.json'],
                            true
                        )
                    ) {
                        $partial = true;
                    }
                    return false;
                }
                return !in_array(
                    $file->getFilename(),
                    ['.git', '.svn', '.hg', 'moodledata', 'localcache', 'temp',
                        'coverage', 'vendor-bin', 'tests', 'docs', '.github'],
                    true
                );
            });
            $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $file) {
                if (++$entries > self::MAX_ENTRIES || microtime(true) - $started > self::MAX_SECONDS) {
                    $partial = true;
                    break;
                }
                if (!$file->isFile()) {
                    continue;
                }
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
                if (isset($files[$relative])) {
                    continue;
                }
                $name = $file->getFilename();
                if (
                    !in_array(
                        $name,
                        ['thirdpartylibs.xml', 'composer.lock', 'package-lock.json', 'installed.json', 'version.php'],
                        true
                    )
                        && !($name === 'package.json' && strpos($relative, 'node_modules/') !== false)
                ) {
                    continue;
                }
                if (count($files) >= self::MAX_FILES) {
                    $partial = true;
                    break;
                }
                if (!$file->isReadable() || $file->getSize() > self::MAX_BYTES) {
                    $partial = true;
                    continue;
                }
                $contents = file_get_contents($file->getPathname(), false, null, 0, self::MAX_BYTES + 1);
                if ($contents === false || strlen($contents) > self::MAX_BYTES) {
                    $partial = true;
                    continue;
                }
                $bytes += strlen($contents);
                if ($bytes > self::MAX_TOTAL_BYTES) {
                    $partial = true;
                    break;
                }
                $files[$relative] = $contents;
            }
        } catch (\Throwable $exception) {
            $partial = true;
        }
        $inventory = (new dependency_parser())->parse($files);
        // Missing core metadata is unavailable coverage, never a genuinely empty install.
        if (!isset($files['lib/thirdpartylibs.xml']) && !isset($files['public/lib/thirdpartylibs.xml'])) {
            $partial = true;
        }
        if ($partial || count($inventory['packages']) > self::MAX_FILES) {
            $inventory['status'] = 'partial';
            $inventory['packages'] = array_slice($inventory['packages'], 0, self::MAX_FILES);
        }
        foreach ($inventory['packages'] as &$package) {
            if ($package['evidence_type'] !== 'installed_metadata') {
                continue;
            }
            $installed = realpath($root . '/' . $package['path']);
            if (!$installed && is_dir($root . '/public')) {
                $installed = realpath($root . '/public/' . $package['path']);
            }
            if (!$installed || !is_dir($installed) || strpos($installed, $root . DIRECTORY_SEPARATOR) !== 0) {
                $package['evidence_type'] = 'lockfile';
                $inventory['status'] = 'partial';
            }
        }
        unset($package);
        $inventory['observed_at'] = gmdate('c');
        return $inventory;
    }

    /**
     * Failed collection carries no clean claim and no absolute paths or error details.
     *
     * @return array Failed inventory envelope.
     */
    private static function unavailable(): array {
        return ['schema_version' => 1, 'status' => 'failed', 'observed_at' => gmdate('c'), 'packages' => []];
    }
}
