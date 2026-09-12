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

use local_guardlms\local\server_errors;
use local_guardlms\local\sdk_config;

/**
 * Server reporting contracts, using Moodle's actual database driver and native SQL log.
 * @package    local_guardlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_guardlms\local\server_errors
 */
final class server_errors_test extends \advanced_testcase {
    /** @var array Original database options. */
    private array $dboptions;

    /**
     * Reset stored configuration and preserve the shared database driver's options.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        global $DB;
        $property = new \ReflectionProperty(\moodle_database::class, 'dboptions');
        $property->setAccessible(true);
        $this->dboptions = $property->getValue($DB);
    }

    /**
     * Restore driver options: resetAfterTest does not restore this object.
     */
    protected function tearDown(): void {
        global $DB;
        $property = new \ReflectionProperty(\moodle_database::class, 'dboptions');
        $property->setAccessible(true);
        $property->setValue($DB, $this->dboptions);
        parent::tearDown();
    }

    /**
     * Return the result.

     *

     * @return server_errors Fake transport preserving actual event construction and SQL import.
     */
    private function reporter(): server_errors {
        return new class extends server_errors {
            /** @var array Sent batches. */
            public array $batches = [];
            /** @var bool Simulated HTTP success. */
            public bool $success = true;
            /**
             * Send a test batch.
             *
             * @param array $events Events.
             * @return bool Delivery result.
             */
            protected function send(array $events): bool {
                $this->batches[] = $events;
                return $this->success;
            }
        };
    }

    /**
     * Explicit opt-in and a usable connection are required; browser opt-in is independent.
     */
    public function test_opt_in_and_forced_config(): void {
        global $CFG;
        $this->assertFalse(server_errors::enabled());
        set_config('apikey', 'test-key', 'local_guardlms');
        set_config('connectedat', time(), 'local_guardlms');
        set_config('sdkkey', 'test-sdk-key', 'local_guardlms');
        set_config('sdkerrorsendpoint', 'https://guardlms.example/api/sdk/errors/collect', 'local_guardlms');
        set_config('sdkbackendenabled', 1, 'local_guardlms');
        set_config('sdksubscriptionactive', 1, 'local_guardlms');
        $this->assertFalse(server_errors::enabled());
        $CFG->forced_plugin_settings['local_guardlms']['servererrorsenabled'] = 1;
        $this->assertTrue(server_errors::enabled());
        $CFG->forced_plugin_settings['local_guardlms']['servererrorsenabled'] = 0;
        set_config('servererrorsenabled', 1, 'local_guardlms');
        $this->assertFalse(server_errors::enabled());
    }

    /**
     * The active connection logs real caught SQL errors; options such as logslow remain unchanged.
     */
    public function test_caught_sql_error_is_delivered_once_with_trace(): void {
        global $CFG, $DB;
        $CFG->dboptions['logslow'] = 1234;
        server_errors::settings_updated();
        server_errors::force_sql_logging();
        $this->assertTrue($CFG->dboptions['logerrors']);
        $this->assertSame(1234, $CFG->dboptions['logslow']);
        try {
            $DB->get_records_sql('SELECT * FROM {guardlms_nonexistent_table} WHERE id = ?', ['private-value']);
            $this->fail('Expected a SQL exception.');
        } catch (\dml_exception $exception) {
            $this->assertNotEmpty($exception->getTrace());
        }
        $reporter = $this->reporter();
        $reporter->flush_sql_logs();
        $this->assertCount(1, $reporter->batches);
        $event = $reporter->batches[0][0];
        $this->assertSame('sql', $event['type']);
        $this->assertNotEmpty($event['stackTrace']);
        $this->assertStringNotContainsString('private-value', json_encode($event));
        $this->assertArrayNotHasKey('sqlparams', $event);
        $reporter->flush_sql_logs();
        $this->assertCount(1, $reporter->batches);
    }

    /**
     * Delivery failures are retried; a late lower ID is not skipped by newer receipts.
     */
    public function test_sql_retry_and_out_of_order_commits(): void {
        global $DB;
        server_errors::settings_updated();
        $record = (object) ['qtype' => 1, 'sqltext' => 'private SQL', 'sqlparams' => 'private-value',
            'error' => 1, 'info' => "duplicate value 'sensitive'", 'backtrace' => '* file.php:12',
            'exectime' => 0.1, 'timelogged' => time()];
        $first = $DB->insert_record('log_queries', $record);
        $second = $DB->insert_record('log_queries', $record);
        // Hide the first row to model an earlier uncommitted transaction.
        $DB->set_field('log_queries', 'error', 0, ['id' => $first]);
        $reporter = $this->reporter();
        $reporter->success = false;
        $reporter->flush_sql_logs();
        $this->assertFalse($DB->record_exists('local_guardlms_sql_sent', ['logid' => $second]));
        $reporter->success = true;
        $reporter->flush_sql_logs();
        $this->assertTrue($DB->record_exists('local_guardlms_sql_sent', ['logid' => $second]));
        $DB->set_field('log_queries', 'error', 1, ['id' => $first]);
        $reporter->flush_sql_logs();
        $this->assertTrue($DB->record_exists('local_guardlms_sql_sent', ['logid' => $first]));
        $this->assertCount(3, $reporter->batches);
        $this->assertStringNotContainsString('sensitive', json_encode($reporter->batches));
    }

    /**
     * Events omit sensitive trace arguments, absolute roots and query strings.
     */
    public function test_exception_redaction_and_classification(): void {
        global $CFG;
        $event = $this->reporter()->exception_event(new \RuntimeException(
            "Failure password=hunter2 sesskey=abc user@example.com 'private' " . $CFG->dataroot
        ), false);
        $this->assertSame('php', $event['type']);
        $this->assertSame('RuntimeException', $event['errorClass']);
        $this->assertFalse($event['handled']);
        $this->assertNotEmpty($event['stackTrace']);
        foreach (['hunter2', 'abc', 'user@example.com', 'private', $CFG->dataroot, $CFG->dirroot] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($event));
        }
    }

    /**
     * Monitoring must still delegate if sending throws.
     */
    public function test_previous_exception_handler_survives_transport_failure(): void {
        $reporter = new class extends server_errors {
            /**
             * Send a test batch.
             *
             * @param array $events Events.
             * @return bool Never returns.
             */
            protected function send(array $events): bool {
                throw new \RuntimeException('transport failed');
            }
        };
        $seen = null;
        $property = new \ReflectionProperty(server_errors::class, 'exceptionhandler');
        $property->setAccessible(true);
        $property->setValue($reporter, function (\Throwable $exception) use (&$seen): void {
            $seen = $exception;
        });
        $exception = new \RuntimeException('original error');
        $reporter->handle_exception($exception);
        $this->assertSame($exception, $seen);
    }

    /**
     * Native SQL logs and uncaught exceptions must not report the same failure twice.
     */
    public function test_uncaught_sql_log_is_not_duplicated(): void {
        global $DB;
        server_errors::settings_updated();
        server_errors::force_sql_logging();
        $reporter = $this->reporter();
        $property = new \ReflectionProperty(server_errors::class, 'exceptionhandler');
        $property->setAccessible(true);
        $property->setValue($reporter, static function (\Throwable $exception): void {
        });
        try {
            $DB->get_records_sql('SELECT * FROM {guardlms_nonexistent_table}');
        } catch (\dml_exception $exception) {
            $reporter->handle_exception($exception);
        }
        $reporter->flush_sql_logs();
        $this->assertCount(1, $reporter->batches);
    }
    /**
     * Suppressed warnings are skipped and the previous error handler keeps its return value.
     */
    public function test_warning_suppression_and_previous_handler(): void {
        server_errors::settings_updated();
        $reporter = $this->reporter();
        $calls = 0;
        $property = new \ReflectionProperty(server_errors::class, 'errorhandler');
        $property->setAccessible(true);
        $property->setValue($reporter, static function () use (&$calls): bool {
            $calls++;
            return true;
        });
        $level = error_reporting(0);
        try {
            $this->assertTrue($reporter->handle_error(E_USER_WARNING, 'suppressed', __FILE__, __LINE__));
        } finally {
            error_reporting($level);
        }
        $reporter->shutdown();
        $this->assertEmpty($reporter->batches);
        $level = error_reporting(E_ALL);
        try {
            $this->assertTrue($reporter->handle_error(E_USER_WARNING, 'visible warning', __FILE__, __LINE__));
        } finally {
            error_reporting($level);
        }
        $reporter->shutdown();
        $this->assertSame(2, $calls);
        $this->assertCount(1, $reporter->batches);
        $this->assertSame('visible warning', $reporter->batches[0][0]['message']);
    }

    /**
     * Exception chains retain their diagnostic context with redacted messages.
     */
    public function test_previous_exception_is_in_the_stack(): void {
        $previous = new \RuntimeException('Connection failed password=private-value');
        $event = $this->reporter()->exception_event(new \RuntimeException('Operation failed', 0, $previous), false);
        $this->assertStringContainsString('Caused by RuntimeException: Connection failed', $event['stackTrace']);
        $this->assertStringNotContainsString('private-value', $event['stackTrace']);
    }

    /**
     * An uncaught SQL exception is reported before core discards its transactional log.
     */
    public function test_transactional_sql_exception_survives_rollback(): void {
        global $DB;
        server_errors::settings_updated();
        server_errors::force_sql_logging();
        $reporter = $this->reporter();
        $property = new \ReflectionProperty(server_errors::class, 'exceptionhandler');
        $property->setAccessible(true);
        $property->setValue($reporter, static function (\Throwable $exception): void {
        });
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->get_records_sql('SELECT * FROM {guardlms_nonexistent_table}');
            $this->fail('Expected SQL failure.');
        } catch (\dml_exception $exception) {
            $reporter->handle_exception($exception);
            try {
                $transaction->rollback($exception);
            } catch (\dml_exception $rolledback) {
                $this->assertSame($exception, $rolledback);
            }
        }
        $reporter->flush_sql_logs();
        $this->assertCount(1, $reporter->batches);
        $this->assertSame('sql', $reporter->batches[0][0]['type']);
        $this->assertFalse($reporter->batches[0][0]['handled']);
    }
    /**
     * The AJAX dispatcher returns caught failures instead of calling the global handler.
     */
    public function test_ajax_exceptions_are_forwarded_without_changing_response(): void {
        $reporter = $this->reporter();
        $body = json_encode([
            ['error' => false, 'data' => ['password' => 'private-response-data']],
            ['error' => true, 'exception' => ['errorcode' => 'invalidparameter',
                'message' => 'Visible error password=secret-value', 'debuginfo' => 'private-debug-data',
                'backtrace' => '* line 12 of /local/example/lib.php']],
        ]);
        $first = substr($body, 0, 30);
        $last = substr($body, 30);
        $this->assertSame($first, $reporter->observe_ajax_output($first, PHP_OUTPUT_HANDLER_START));
        $this->assertEmpty($reporter->batches);
        $this->assertSame($last, $reporter->observe_ajax_output($last, PHP_OUTPUT_HANDLER_FINAL));
        $this->assertCount(1, $reporter->batches[0]);
        $event = $reporter->batches[0][0];
        $this->assertSame('php', $event['type']);
        $this->assertSame('* line 12 of /local/example/lib.php', $event['stackTrace']);
        foreach (['private-response-data', 'private-debug-data', 'secret-value'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($event));
        }
    }

    /**
     * Production AJAX errors with no disclosed backtrace still reach GuardLMS.
     */
    public function test_ajax_sql_error_without_debug_trace(): void {
        $reporter = $this->reporter();
        $body = json_encode([['error' => true, 'exception' => [
            'errorcode' => 'dmlreadexception', 'message' => 'Error reading from database',
        ]]]);
        $this->assertSame($body, $reporter->observe_ajax_output($body, PHP_OUTPUT_HANDLER_FINAL));
        $this->assertSame('sql', $reporter->batches[0][0]['type']);
        $this->assertArrayNotHasKey('stackTrace', $reporter->batches[0][0]);
    }

    /**
     * Non-JSON, success-only and oversized AJAX responses are not captured.
     */
    public function test_ajax_non_errors_and_oversized_output_are_ignored(): void {
        foreach (['not JSON', '[{"error":false,"data":"private"}]', str_repeat('a', 1048577)] as $body) {
            $reporter = $this->reporter();
            $this->assertSame($body, $reporter->observe_ajax_output($body, PHP_OUTPUT_HANDLER_FINAL));
            $this->assertEmpty($reporter->batches);
        }
    }
}
