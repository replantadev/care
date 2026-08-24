<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-pipeline-client.php';

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'ActionScheduler_Store' ) ) {
    class ActionScheduler_Store {
        public const STATUS_FAILED = 'failed';
        public static array $queries = [];
        public static array $results = [];

        public static function instance(): self {
            return new self();
        }

        public function query_actions( array $query ): array {
            self::$queries[] = $query;
            return self::$results[ $query['hook'] ] ?? [];
        }
    }
}

final class PipelineFailureCountTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [];
        ActionScheduler_Store::$queries = [];
        ActionScheduler_Store::$results = [];
    }

    public function test_failed_actions_use_last_attempt_datetime_after_last_heartbeat(): void {
        update_option( RP_Care_Pipeline_Client::OPT_LAST_POLL, '2026-08-24 12:34:56' );

        $this->assertSame( 0, RP_Care_Pipeline_Client::count_failed_actions() );
        $this->assertCount( 2, ActionScheduler_Store::$queries );

        foreach ( ActionScheduler_Store::$queries as $query ) {
            $this->assertArrayNotHasKey( 'date', $query );
            $this->assertInstanceOf( DateTime::class, $query['modified'] );
            $this->assertSame( '>', $query['modified_compare'] );
            $this->assertSame( '2026-08-24 12:34:56', $query['modified']->format( 'Y-m-d H:i:s' ) );
        }
    }

    public function test_failed_actions_are_summed_only_for_pipeline_hooks(): void {
        ActionScheduler_Store::$results = [
            'rpcare_task_pipeline_poll' => [ 11 ],
            'rpcare_deliver_pipeline_outbox' => [ 12, 13 ],
        ];

        $this->assertSame( 3, RP_Care_Pipeline_Client::count_failed_actions() );
        $this->assertSame(
            [ 'rpcare_task_pipeline_poll', 'rpcare_deliver_pipeline_outbox' ],
            array_column( ActionScheduler_Store::$queries, 'hook' )
        );
    }
}
