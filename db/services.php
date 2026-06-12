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
 * Web service definitions for local_guardlms.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_guardlms_get_site_info' => [
        'classname' => 'local_guardlms\external\get_site_info',
        'methodname' => 'execute',
        'description' => 'Return the Moodle version and installed plugin inventory for GuardLMS security monitoring.',
        'type' => 'read',
        'capabilities' => 'local/guardlms:viewsiteinfo',
        'ajax' => false,
    ],
];

$services = [
    'GuardLMS site info' => [
        'shortname' => 'local_guardlms_service',
        'functions' => ['local_guardlms_get_site_info'],
        'restrictedusers' => 1,
        'enabled' => 1,
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
