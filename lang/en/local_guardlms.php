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
 * English strings for local_guardlms.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'GuardLMS';
$string['guardlms:viewsiteinfo'] = 'Serve the site version and plugin inventory to GuardLMS';
$string['servicename'] = 'GuardLMS site info';
$string['settings:infoheading'] = 'GuardLMS site info web service';
$string['settings:infodesc'] = 'This plugin exposes a read only web service (local_guardlms_get_site_info) that returns the Moodle version and installed plugin inventory. Enable web services, then create a token on a dedicated service user with the GuardLMS site info service so GuardLMS can read the inventory for security monitoring.';
$string['privacy:metadata'] = 'The GuardLMS plugin does not store any personal data. It exposes the Moodle version and installed plugin inventory through a read only web service.';
