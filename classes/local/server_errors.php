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
 * Opt-in server error reporting. Reporting must never break Moodle's error handling.
 *
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_guardlms\local;

/**
 * Captures PHP failures and imports Moodle's native SQL error log.
 */
class server_errors {
    /** @var self|null Reporter registered for this request. */
    private static ?self $instance = null;
    /** @var callable|null Previously installed exception handler. */
    private $exceptionhandler;
    /** @var callable|null Previously installed error handler. */
    private $errorhandler;
    /** @var bool Prevent reporting recursively. */
    private bool $busy = false;
    /** @var array Bounded in-memory queue of PHP warnings. */
    private array $pending = [];
    /** @var string Ingest key, cached before a database failure. */
    private string $key;
    /** @var string Endpoint, cached before a database failure. */
    private string $endpoint;
    /** @var string Configured site origin. */
    private string $siteurl;
    /** @var string Site origin without a Moodle installation subdirectory. */
    private string $origin;
    /** @var string Application release. */
    private string $appversion;
    /** @var int Request start, used to match native SQL failures. */
    private int $started;
    /** @var string Bounded response fragment for Moodle's standard AJAX endpoint. */
    private string $ajaxbody = '';
    /** @var bool Whether the AJAX response exceeded the capture limit. */
    private bool $ajaxoverflow = false;
    /** @var \SplObjectStorage Throwables already observed on this request. */
    private \SplObjectStorage $observed;

    /**
     * Cache configuration while the database is still available.
     */
    public function __construct() {
        global $CFG;
        $this->started = time();
        $this->observed = new \SplObjectStorage();
        $this->key = sdk_config::key();
        $this->endpoint = sdk_config::errors_endpoint();
        $this->siteurl = rtrim($CFG->wwwroot, '/');
        $parts = parse_url($this->siteurl);
        $this->origin = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->appversion = sdk_config::app_version();
    }

    /**
     * Respect both the settings checkbox and Moodle's forced_plugin_settings.
     * @return bool Whether server reporting can start.
     */
    public static function enabled(): bool {
        return (bool) get_config('local_guardlms', 'servererrorsenabled')
            && connect_manager::is_connected()
            && sdk_config::backend_enabled()
            && sdk_config::subscription_active()
            && sdk_config::key() !== ''
            && sdk_config::errors_endpoint() !== '';
    }

    /**
     * Register once, after configuration, for web, AJAX and CLI requests.
     */
    public static function start(): void {
        if (self::$instance !== null) {
            return;
        }
        try {
            if (!self::enabled()) {
                return;
            }
            $reporter = new self();
            // Initialise the watermark before enabling logging: never replay historical logs on first opt-in.
            $reporter->initialise_sql_cursor();
            self::force_sql_logging();
            self::$instance = $reporter;
            $reporter->exceptionhandler = set_exception_handler([$reporter, 'handle_exception']);
            $reporter->errorhandler = set_error_handler([$reporter, 'handle_error']);
            register_shutdown_function([$reporter, 'shutdown']);
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            if (preg_match('~/lib/ajax/service(?:-nologin)?\.php$~', $script)) {
                // The core AJAX dispatcher catches exceptions itself. Observe only
                // its JSON error envelope; never inspect request arguments or success data.
                ob_start([$reporter, 'observe_ajax_output'], 16384);
            }
        } catch (\Throwable $ignored) {
            // Monitoring must not stop Moodle from booting.
            return;
        }
    }

    /**
     * Enable only error logging, preserving slow-query and other database options.
     */
    public static function force_sql_logging(): void {
        global $CFG, $DB;
        $CFG->dboptions['logerrors'] = true;
        // After_config runs after connect(). Moodle offers no public setter for
        // the driver's copied options, so update only this protected option.
        $property = new \ReflectionProperty(\moodle_database::class, 'dboptions');
        $property->setAccessible(true);
        $options = (array) $property->getValue($DB);
        $options['logerrors'] = true;
        $property->setValue($DB, $options);
    }

    /**
     * Reset the import boundary whenever the administrator changes the opt-in.
     */
    public static function settings_updated(): void {
        global $DB;
        set_config('serversqlcursor', (int) $DB->get_field_sql('SELECT MAX(id) FROM {log_queries}'), 'local_guardlms');
        $DB->delete_records('local_guardlms_sql_sent');
    }

    /**
     * Establish a single import watermark under the same lock as the importer.
     */
    private function initialise_sql_cursor(): void {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('local_guardlms')->get_lock('server_sql', 0);
        if (!$lock) {
            return;
        }
        try {
            if ($DB->get_field('config_plugins', 'value', ['plugin' => 'local_guardlms', 'name' => 'serversqlcursor']) === false) {
                self::settings_updated();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Preserve the installed handler and Moodle's normal rollback/render/exit path.
     * @param \Throwable $exception Uncaught throwable.
     */
    public function handle_exception(\Throwable $exception): void {
        $this->report_exception($exception);
        if (is_callable($this->exceptionhandler)) {
            call_user_func($this->exceptionhandler, $exception);
        } else {
            \default_exception_handler($exception);
        }
    }

    /**
     * Report without changing the exception's rendering/rollback behaviour.
     * @param \Throwable $exception Throwable to report.
     */
    private function report_exception(\Throwable $exception): void {
        if ($this->busy || $this->observed->contains($exception)) {
            return;
        }
        $this->observed->attach($exception);
        $this->busy = true;
        try {
            try {
                $logged = $this->has_sql_log($exception);
            } catch (\Throwable $ignored) {
                $logged = false;
            }
            if (!$logged) {
                $this->send([$this->exception_event($exception, false)]);
            }
        } catch (\Throwable $ignored) {
            // Always preserve the original failure when the transport is broken.
            return;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Core routers can invoke default_exception_handler directly, bypassing PHP's handler.
     * Observe that call while the error page head is rendered, without taking over the renderer.
     */
    public static function observe_rendered_exception(): void {
        if (self::$instance === null) {
            return;
        }
        foreach (debug_backtrace(0, 30) as $frame) {
            if (
                ($frame['function'] ?? '') === 'default_exception_handler'
                    && ($frame['args'][0] ?? null) instanceof \Throwable
            ) {
                self::$instance->report_exception($frame['args'][0]);
                return;
            }
        }
    }

    /**
     * Observe exceptions returned by Moodle's core AJAX dispatcher, leaving every output byte unchanged.
     * @param string $output Original response chunk.
     * @param int $phase PHP output buffer phase.
     * @return string Original response chunk.
     */
    public function observe_ajax_output(string $output, int $phase): string {
        try {
            if ($phase & PHP_OUTPUT_HANDLER_CLEAN) {
                $this->ajaxbody = '';
                return $output;
            }
            if (strlen($this->ajaxbody) + strlen($output) > 1048576) {
                $this->ajaxoverflow = true;
                $this->ajaxbody = '';
            }
            if (!$this->ajaxoverflow) {
                $this->ajaxbody .= $output;
            }
            if (!($phase & PHP_OUTPUT_HANDLER_FINAL) || $this->ajaxoverflow) {
                return $output;
            }
            $responses = json_decode($this->ajaxbody, true);
            $this->ajaxbody = '';
            if (!is_array($responses)) {
                return $output;
            }
            $events = [];
            foreach ($responses as $response) {
                if (
                    !is_array($response) || ($response['error'] ?? null) !== true
                        || !is_array($response['exception'] ?? null)
                ) {
                    continue;
                }
                $exception = $response['exception'];
                if (!is_string($exception['message'] ?? null)) {
                    continue;
                }
                $event = $this->base_event();
                $code = is_string($exception['errorcode'] ?? null) ? $exception['errorcode'] : '';
                $event['type'] = preg_match('/^(dml|ddl)/', $code) ? 'sql' : 'php';
                $event['message'] = self::scrub($exception['message'], 1000) ?: 'Moodle AJAX exception';
                $event['handled'] = true;
                $event['context'] = 'Moodle AJAX exception';
                $event['customData'] = ['moodleErrorCode' => self::scrub($code, 100)];
                if (is_string($exception['backtrace'] ?? null)) {
                    $event['stackTrace'] = self::scrub($exception['backtrace'], 10000);
                }
                $events[] = $event;
                if (count($events) >= 20) {
                    break;
                }
            }
            if ($events && !$this->busy) {
                // PHP may flush output buffers after shutdown callbacks have run.
                $this->busy = true;
                try {
                    $this->send($events);
                } finally {
                    $this->busy = false;
                }
            }
        } catch (\Throwable $ignored) {
            // A reporting failure must never corrupt the user's AJAX response.
            return $output;
        }
        return $output;
    }

    /**
     * Detect the exact native SQL log corresponding to a throwable.
     * @param \Throwable $exception Throwable to match.
     * @return bool True when the native log survives the default handler's rollback.
     */
    private function has_sql_log(\Throwable $exception): bool {
        global $DB;
        if (!($exception instanceof \dml_exception) || $DB->is_transaction_started()) {
            return false;
        }
        $logs = $DB->get_records_select(
            'log_queries',
            'error = 1 AND timelogged >= ?',
            [$this->started],
            'id DESC',
            'id, backtrace',
            0,
            50
        );
        $trace = $exception->getTrace();
        // Query_end() throws the DML exception; the SQL log omits that frame.
        array_shift($trace);
        $formatted = format_backtrace($trace, true);
        foreach ($logs as $log) {
            if ($formatted !== '' && $log->backtrace === $formatted) {
                return true;
            }
        }
        return false;
    }

    /**
     * Queue reportable warnings without changing PHP's suppression or handler behaviour.
     * @param int $severity PHP error level.
     * @param string $message Error message.
     * @param string $file Source file.
     * @param int $line Source line.
     * @return bool Whether the previous handler handled the error.
     */
    public function handle_error(int $severity, string $message, string $file, int $line): bool {
        $arguments = func_get_args();
        if (!$this->busy && (error_reporting() & $severity) && count($this->pending) < 20) {
            $this->busy = true;
            try {
                $this->pending[] = $this->exception_event(
                    new \ErrorException($message, 0, $severity, $file, $line),
                    !in_array($severity, [E_USER_ERROR, E_RECOVERABLE_ERROR], true)
                );
            } catch (\Throwable $ignored) {
                // Preserve normal PHP behaviour if capture fails.
                $this->busy = false;
            } finally {
                $this->busy = false;
            }
        }
        return is_callable($this->errorhandler)
            ? (bool) call_user_func_array($this->errorhandler, $arguments)
            : false;
    }

    /**
     * Flush bounded pending errors, fatal errors and committed SQL errors on request completion.
     */
    public function shutdown(): void {
        $last = error_get_last();
        if ($this->busy) {
            return;
        }
        $this->busy = true;
        try {
            if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $event = $this->exception_event(
                    new \ErrorException($last['message'], 0, $last['type'], $last['file'], $last['line']),
                    false
                );
                // PHP exposes only the fatal location at shutdown, not its original call stack.
                $event['stackTrace'] = self::scrub(format_backtrace([
                    ['file' => $last['file'], 'line' => $last['line']],
                ], true), 10000);
                $this->pending[] = $event;
            }
            if ($this->pending) {
                $this->send($this->pending);
                $this->pending = [];
            }
            $this->flush_sql_logs();
        } catch (\Throwable $ignored) {
            // Best effort; a failing reporter must never mask the original error.
            return;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Build a safe throwable payload; never serialize trace arguments or Moodle debuginfo.
     * @param \Throwable $exception Throwable to report.
     * @param bool $handled Whether application code handled this failure.
     * @return array Ingest event.
     */
    public function exception_event(\Throwable $exception, bool $handled): array {
        $trace = [['file' => $exception->getFile(), 'line' => $exception->getLine()]];
        $trace = array_merge($trace, $exception->getTrace());
        $event = $this->base_event();
        $event['type'] = $exception instanceof \dml_exception ? 'sql' : 'php';
        $event['message'] = self::scrub($exception->getMessage(), 1000) ?: get_class($exception);
        $event['errorClass'] = substr(get_class($exception), 0, 255);
        $event['handled'] = $handled;
        $event['severity'] = $handled ? 'medium' : 'high';
        $event['sourceFile'] = self::scrub($exception->getFile(), 500);
        $event['lineNumber'] = $exception->getLine();
        $stack = format_backtrace($trace, true);
        $previous = $exception->getPrevious();
        for ($depth = 0; $previous && $depth < 5; $depth++) {
            $stack .= "\nCaused by " . get_class($previous) . ': ' . $previous->getMessage() . "\n";
            $stack .= format_backtrace(array_merge([
                ['file' => $previous->getFile(), 'line' => $previous->getLine()],
            ], $previous->getTrace()), true);
            $previous = $previous->getPrevious();
        }
        $event['stackTrace'] = self::scrub($stack, 10000);
        return $event;
    }

    /**
     * Import at most one batch. A shared lock prevents concurrent requests advancing the same cursor.
     * Failed HTTP deliveries leave logs unacknowledged, so cron can retry.
     */
    public function flush_sql_logs(): void {
        global $DB;
        if ($DB->is_transaction_started()) {
            return;
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_guardlms')->get_lock('server_sql', 0);
        if (!$lock) {
            return;
        }
        $wasbusy = $this->busy;
        $this->busy = true;
        $logging = new \ReflectionProperty(\moodle_database::class, 'skiplogging');
        $logging->setAccessible(true);
        $skiplogging = $logging->getValue($DB);
        $logging->setValue($DB, true);
        try {
            // Read directly: plugin config may have been cached before another request flushed.
            $cursor = $DB->get_field('config_plugins', 'value', ['plugin' => 'local_guardlms', 'name' => 'serversqlcursor']);
            if ($cursor === false) {
                self::settings_updated();
                return;
            }
            // Receipts rather than a moving MAX(id) cursor: a long transaction may
            // commit an older ID after a newer request has already been delivered.
            $logs = $DB->get_records_sql(
                'SELECT q.id, q.info, q.backtrace, q.timelogged
                   FROM {log_queries} q
              LEFT JOIN {local_guardlms_sql_sent} s ON s.logid = q.id
                  WHERE q.id > ? AND q.error = 1 AND s.id IS NULL
               ORDER BY q.id ASC',
                [(int) $cursor],
                0,
                50
            );
            $events = [];
            foreach ($logs as $log) {
                $event = $this->base_event();
                $event['type'] = 'sql';
                $event['message'] = self::scrub('Database query failed: ' . $log->info, 1000);
                $event['errorClass'] = 'dml_exception';
                $event['stackTrace'] = self::scrub($log->backtrace, 10000);
                if (preg_match_all('/line (\d+) of ([^\r\n]+?):/', $log->backtrace, $frames, PREG_SET_ORDER)) {
                    foreach ($frames as $frame) {
                        if (strpos($frame[2], '/lib/dml/') === false) {
                            $event['sourceFile'] = self::scrub($frame[2], 500);
                            $event['lineNumber'] = (int) $frame[1];
                            break;
                        }
                    }
                }
                $event['timestamp'] = gmdate('c', (int) $log->timelogged);
                // Core log_queries has no request URL or handled flag; do not invent them.
                $event['pageUrl'] = $this->siteurl;
                $event['context'] = 'Moodle SQL error log';
                $events[] = $event;
            }
            if ($events && $this->send($events)) {
                foreach ($logs as $log) {
                    $DB->insert_record('local_guardlms_sql_sent', (object) ['logid' => $log->id]);
                }
            }
            $DB->delete_records_select('local_guardlms_sql_sent', 'NOT EXISTS (SELECT 1 FROM {log_queries} q WHERE q.id = logid)');
        } finally {
            $logging->setValue($DB, $skiplogging);
            $this->busy = $wasbusy;
            $lock->release();
        }
    }

    /**
     * Shared, non-identifying context. No query strings, cookies, headers or CLI arguments.
     * @return array Base event.
     */
    private function base_event(): array {
        $path = defined('CLI_SCRIPT') && CLI_SCRIPT ? '' : ($_SERVER['SCRIPT_NAME'] ?? '');
        return [
            'pageUrl' => substr($path !== '' ? $this->origin . '/' . ltrim($path, '/') : $this->siteurl, 0, 1000),
            'timestamp' => gmdate('c'),
            'severity' => 'high',
            'appVersion' => substr($this->appversion, 0, 50),
            'context' => 'Moodle server',
        ];
    }

    /**
     * Remove common secrets and literals; SQL text/parameters and exception debuginfo are never sent.
     * @param string $value Input text.
     * @param int $limit Maximum output length.
     * @return string Redacted text.
     */
    public static function scrub(string $value, int $limit): string {
        global $CFG;
        $value = str_replace([$CFG->dirroot, $CFG->dataroot], ['[dirroot]', '[dataroot]'], $value);
        $value = preg_replace(
            '/\b(?:password|secret|[a-z_]*token|api_?key|authorization|sesskey|nonce)\s*[=:]\s*[^\s&,;]+/i',
            '[redacted]',
            $value
        );
        $value = preg_replace('/([\'"])(?:\\\\.|(?!\1).)*\1/s', '[redacted]', $value);
        $value = preg_replace('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/i', '[email]', $value);
        return \core_text::substr($value, 0, $limit);
    }

    /**
     * Bounded, database-independent transport, also safe after a database connection failure.
     * @param array $events Ingest events.
     * @return bool Whether the backend accepted the batch.
     */
    protected function send(array $events): bool {
        $curl = curl_init($this->endpoint);
        if ($curl === false) {
            return false;
        }
        try {
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['errors' => $events], JSON_INVALID_UTF8_SUBSTITUTE),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-SDK-Key: ' . $this->key, 'Origin: ' . $this->origin],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT_MS => 500,
                CURLOPT_TIMEOUT_MS => 1500,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            return $response !== false && $status >= 200 && $status < 300;
        } finally {
            curl_close($curl);
        }
    }
}
