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
 * Orchestrates the keyless "Connect to GuardLMS" flow on the plugin side.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\local;

/**
 * Starts the connect redirect and completes the callback code exchange.
 *
 * Flow: start_connect() sends the admin's browser to the GuardLMS consent
 * screen; GuardLMS redirects back to callback.php with a one-time code that
 * complete_connect() exchanges server-to-server for the site-bound push key.
 */
class connect_manager {
    /** @var int Seconds a pending connect state stays valid. */
    public const STATE_TTL = 900;

    /** @var api_client|null Client override for unit tests. */
    protected ?api_client $client;

    /**
     * Constructor.
     *
     * @param api_client|null $client Optional client override for tests.
     */
    public function __construct(?api_client $client = null) {
        $this->client = $client;
    }

    /**
     * Generate a fresh state and build the GuardLMS consent URL.
     *
     * @return \moodle_url URL to redirect the admin's browser to.
     */
    public function start_connect(): \moodle_url {
        global $CFG;

        $state = random_string(40);
        set_config('connectstate', $state, 'local_guardlms');
        set_config('connectstateexpires', time() + self::STATE_TTL, 'local_guardlms');

        $baseurl = config::baseurl();

        return new \moodle_url($baseurl . '/connect/moodle', [
            'siteurl' => config::siteurl(),
            'state' => $state,
            'callback' => $CFG->wwwroot . '/local/guardlms/callback.php',
        ]);
    }

    /**
     * Validate the callback state and exchange the code for the push key.
     *
     * @param string $code One-time code from the GuardLMS redirect.
     * @param string $state State from the GuardLMS redirect.
     * @throws \moodle_exception When the state is invalid/expired or GuardLMS rejects the exchange.
     */
    public function complete_connect(string $code, string $state): void {
        $storedstate = (string) get_config('local_guardlms', 'connectstate');
        $expires = (int) get_config('local_guardlms', 'connectstateexpires');

        // Single use: clear the pending state before talking to GuardLMS.
        unset_config('connectstate', 'local_guardlms');
        unset_config('connectstateexpires', 'local_guardlms');

        if ($storedstate === '' || !hash_equals($storedstate, $state) || $expires < time()) {
            throw new \moodle_exception('error:connectstate', 'local_guardlms');
        }

        $client = $this->client ?? new api_client(config::baseurl());
        $data = $client->exchange($code, config::siteurl(), $state);

        set_config('apikey', $data['token'], 'local_guardlms');
        set_config('enabled', 1, 'local_guardlms');
        if (!empty($data['pushpath'])) {
            set_config('pushpath', $data['pushpath'], 'local_guardlms');
        }
        if (!empty($data['verification_token'])) {
            set_config('verificationtoken', $data['verification_token'], 'local_guardlms');
        }
        if (!empty($data['website_id'])) {
            set_config('websiteid', (int) $data['website_id'], 'local_guardlms');
        }
        if (!empty($data['expires_at'])) {
            set_config('keyexpiresat', strtotime((string) $data['expires_at']) ?: '', 'local_guardlms');
        }
        set_config('connectedat', time(), 'local_guardlms');

        // The exchange already carried the SDK key, so a site that connects
        // today never needs a second round trip to switch real-time monitoring
        // on. An older GuardLMS simply omits the block.
        if (!empty($data['sdk']) && is_array($data['sdk'])) {
            sdk_config::store_payload($data['sdk']);
        }

        // Push straight away so the site shows up in GuardLMS while the admin is
        // still looking at the screen. Waiting for cron would leave a freshly
        // connected site empty for up to a day.
        try {
            pusher::push();
        } catch (\Throwable $e) {
            // A failed first push must never break the connect callback: the
            // connection itself succeeded. Retry it through cron instead.
            \core\task\manager::queue_adhoc_task(new \local_guardlms\task\initial_push());
            debugging('GuardLMS initial push failed, queued for cron: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        \core\notification::success(get_string('connect:success', 'local_guardlms'));
    }

    /**
     * Tear down the connection: drop the push key and clear the connection state.
     *
     * The base URL and the operational toggles are left alone, so reconnecting
     * later does not need the advanced settings again. The verification token
     * goes with the key: without a key the site no longer reports, so serving a
     * stale ownership tag would be misleading. The SDK key goes with it for the
     * same reason, and for one more: it is a live ingest credential.
     *
     * @param sdk_client|null $sdkclient Client override for tests.
     */
    public static function disconnect(?sdk_client $sdkclient = null): void {
        // Revoke before the push key is dropped: the revoke call authenticates
        // with that key, so clearing it first would leave the SDK key valid on
        // the GuardLMS side with no way left to revoke it. A failure here must
        // not stop the local teardown - an admin who clicked Disconnect gets a
        // disconnected site either way, and the key expires on its own.
        try {
            sdk_client::resolve('revoke', sdk_client::DEFAULT_TIMEOUT, $sdkclient);
        } catch (\Throwable $e) {
            debugging('GuardLMS SDK key revoke failed during disconnect: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        sdk_config::clear();

        unset_config('apikey', 'local_guardlms');
        unset_config('verificationtoken', 'local_guardlms');
        unset_config('websiteid', 'local_guardlms');
        unset_config('connectedat', 'local_guardlms');
        unset_config('keyexpiresat', 'local_guardlms');
        unset_config('connectstate', 'local_guardlms');
        unset_config('connectstateexpires', 'local_guardlms');
    }

    /**
     * Whether the plugin currently holds a push key from a completed connect.
     *
     * @return bool
     */
    public static function is_connected(): bool {
        return trim((string) get_config('local_guardlms', 'apikey')) !== ''
            && (int) get_config('local_guardlms', 'connectedat') > 0;
    }
}
