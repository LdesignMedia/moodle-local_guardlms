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
 * A checkbox rendered visible but inert.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\admin;

/**
 * A checkbox an admin can see but cannot change.
 *
 * Used where the setting exists but cannot take effect on this site - a Moodle
 * below 4.4, where db/hooks.php is ignored, or a plan without analytics. Hiding
 * the setting outright would leave the admin looking for a feature the
 * documentation promises; rendering it live would leave them believing they had
 * switched something on.
 *
 * write_setting() ignores whatever is posted, so the control is inert against a
 * hand-crafted POST as well as in the browser.
 */
class setting_configcheckbox_disabled extends \admin_setting_configcheckbox {
    /** @var string Lang-string key explaining why the control is inert. */
    protected string $reasonkey;

    /**
     * Constructor.
     *
     * @param string $name Unique setting name, in the plugin/setting form.
     * @param string $visiblename Localised setting label.
     * @param string $description Localised setting description.
     * @param string $defaultsetting Default value.
     * @param string $reasonkey Lang-string key in local_guardlms explaining why it is inert.
     */
    public function __construct(
        string $name,
        string $visiblename,
        string $description,
        string $defaultsetting,
        string $reasonkey
    ) {
        $this->reasonkey = $reasonkey;
        parent::__construct($name, $visiblename, $description, $defaultsetting);
    }

    /**
     * Render the checkbox disabled, with the reason beside it.
     *
     * @param mixed $data The current setting value.
     * @param string $query Search query for highlighting.
     * @return string
     */
    public function output_html($data, $query = ''): string {
        $checkbox = \html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'disabled' => 'disabled',
            'checked' => ((string) $data === (string) $this->yes) ? 'checked' : null,
            'id' => $this->get_id(),
        ]);

        $reason = \html_writer::div(
            get_string($this->reasonkey, 'local_guardlms'),
            'form-description text-muted'
        );

        return format_admin_setting(
            $this,
            $this->visiblename,
            $checkbox . $reason,
            $this->description,
            true,
            '',
            null,
            $query
        );
    }

    /**
     * Ignore anything posted for this setting.
     *
     * @param mixed $data Posted value, discarded.
     * @return string Always an empty string, meaning "no error, nothing written".
     */
    public function write_setting($data): string {
        return '';
    }
}
