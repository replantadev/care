<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/task-staging-eval.php';
require_once __DIR__ . '/../inc/class-test-runner.php';
require_once __DIR__ . '/../inc/class-rest.php';

final class PipelineQualityGatesTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [];
        unset( $GLOBALS['_wp_remote_get_mock'] );
        $GLOBALS['wpdb'] = new wpdb();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['_wp_remote_get_mock'] );
        $GLOBALS['wpdb'] = new wpdb();
    }

    public function test_pipeline_baseline_is_immutable_for_the_same_batch(): void {
        $calls = 0;
        $GLOBALS['_wp_remote_get_mock'] = static function () use ( &$calls ): array {
            $calls++;
            return [
                'response' => [ 'code' => 200 ],
                'body'     => $calls === 1
                    ? '<html><body><img><p>before</p></body></html>'
                    : '<html><body><p>after with different bytes</p></body></html>',
            ];
        };

        $first = RP_Care_Task_StagingEval::capture_pipeline_baseline( 'batch-a' );
        $again = RP_Care_Task_StagingEval::capture_pipeline_baseline( 'batch-a' );
        $next  = RP_Care_Task_StagingEval::capture_pipeline_baseline( 'batch-b' );

        $this->assertIsArray( $first );
        $this->assertSame( $first, $again, 'Retry must reuse the pre-update baseline.' );
        $this->assertSame( 2, $calls, 'Only a different batch may capture a new baseline.' );
        $this->assertNotSame( $first['page_size'], $next['page_size'] );
        $this->assertSame( 'batch-b', get_option( RP_Care_Task_StagingEval::OPT_BASELINE_BATCH ) );
    }

    public function test_dom_gate_rejects_missing_or_foreign_batch_baseline(): void {
        $suite = new RP_Care_Test_Suite_DOM();
        $missing = $suite->run( [ 'batch_id' => 'batch-a' ] );
        $this->assertSame( 'critical', $missing['status'] );

        update_option( RP_Care_Task_StagingEval::OPT_BASELINE, [ 'status_code' => 200, 'page_size' => 10000 ] );
        update_option( RP_Care_Task_StagingEval::OPT_BASELINE_BATCH, 'batch-other' );
        $foreign = $suite->run( [ 'batch_id' => 'batch-a' ] );
        $this->assertSame( 'critical', $foreign['status'] );
        $this->assertSame( 'dom_baseline_batch', $foreign['checks'][0]['name'] );
    }

    public function test_dom_gate_compares_pre_update_snapshot_without_overwriting_it(): void {
        $baseline = [
            'status_code'       => 200,
            'page_size'         => 10000,
            'stylesheet_urls'   => [ '/a.css', '/b.css' ],
            'elementor_sections'=> 6,
            'elementor_widgets' => 20,
            'menu_items'        => 8,
        ];
        update_option( RP_Care_Task_StagingEval::OPT_BASELINE, $baseline );
        update_option( RP_Care_Task_StagingEval::OPT_BASELINE_BATCH, 'batch-a' );
        $GLOBALS['_wp_remote_get_mock'] = [
            'response' => [ 'code' => 200 ],
            'body'     => '<html><body>tiny</body></html>',
        ];

        $result = ( new RP_Care_Test_Suite_DOM() )->run( [ 'batch_id' => 'batch-a' ] );

        $this->assertSame( 'critical', $result['status'] );
        $this->assertSame( $baseline, get_option( RP_Care_Task_StagingEval::OPT_BASELINE ), 'Comparison must not replace the baseline.' );
        $this->assertContains( 'content_loss', array_column( $result['checks'], 'name' ) );
    }

    public function test_one_time_loopback_probe_is_consumed(): void {
        $token = 'one-time-test-probe';
        set_transient( 'rpcare_loopback_' . hash( 'sha256', $token ), '1', 60 );
        $request = new WP_REST_Request();
        $request->set_param( 'probe', $token );
        $rest = new RP_Care_REST();

        $first  = $rest->pipeline_loopback_probe( $request );
        $second = $rest->pipeline_loopback_probe( $request );

        $this->assertSame( 200, $first->get_status() );
        $this->assertTrue( $first->get_data()['ok'] );
        $this->assertSame( 403, $second->get_status() );
    }

    public function test_loopback_route_is_registered(): void {
        $GLOBALS['_rest_routes'] = [];
        ( new RP_Care_REST() )->register_routes();
        $routes = array_column( $GLOBALS['_rest_routes'], 'route' );
        $this->assertContains( '/pipeline/loopback-probe', $routes );
    }

    public function test_runner_loopback_validates_the_authenticated_response(): void {
        $GLOBALS['_wp_remote_get_mock'] = static function ( string $url ): array {
            parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
            $request = new WP_REST_Request();
            $request->set_param( 'probe', $query['probe'] ?? '' );
            $response = ( new RP_Care_REST() )->pipeline_loopback_probe( $request );
            return [
                'response' => [ 'code' => $response->get_status() ],
                'body'     => json_encode( $response->get_data() ),
            ];
        };
        $method = new ReflectionMethod( RP_Care_Test_Suite_WordPress::class, 'check_loopback' );
        $method->setAccessible( true );
        $result = $method->invoke( new RP_Care_Test_Suite_WordPress() );

        $this->assertSame( 'ok', $result['status'] );
        $this->assertStringContainsString( 'one-time', $result['message'] );
    }

    public function test_action_scheduler_global_noise_does_not_fail_care(): void {
        $GLOBALS['wpdb'] = new class extends wpdb {
            public function get_var( string $sql, int $col_offset = 0, int $row_offset = 0 ): ?string {
                if ( str_contains( $sql, 'a.args LIKE' ) ) return '0';
                if ( str_contains( $sql, 'LEFT JOIN' ) ) return '0';
                return '21';
            }
        };
        $method = new ReflectionMethod( RP_Care_Test_Suite_WordPress::class, 'check_action_scheduler' );
        $method->setAccessible( true );
        $result = $method->invoke( new RP_Care_Test_Suite_WordPress(), 'batch-a' );

        $this->assertSame( 'ok', $result['status'] );
        $this->assertSame( 21, $result['global_failed'] );
        $this->assertSame( 0, $result['care_failed'] );
        $this->assertSame( 0, $result['batch_failed'] );
    }

    public function test_current_batch_action_scheduler_failure_is_critical(): void {
        $GLOBALS['wpdb'] = new class extends wpdb {
            public function get_var( string $sql, int $col_offset = 0, int $row_offset = 0 ): ?string {
                if ( str_contains( $sql, 'a.args LIKE' ) ) return '1';
                if ( str_contains( $sql, "g.slug = 'pipeline'" ) ) return '1';
                if ( str_contains( $sql, 'LEFT JOIN' ) ) return '1';
                return '21';
            }
        };
        $method = new ReflectionMethod( RP_Care_Test_Suite_WordPress::class, 'check_action_scheduler' );
        $method->setAccessible( true );
        $result = $method->invoke( new RP_Care_Test_Suite_WordPress(), 'batch-a' );

        $this->assertSame( 'critical', $result['status'] );
        $this->assertSame( 1, $result['batch_failed'] );
    }

    public function test_pipeline_handlers_capture_baseline_before_enqueue(): void {
        $source = (string) file_get_contents( __DIR__ . '/../inc/class-pipeline-client.php' );
        $this->assertGreaterThanOrEqual( 2, substr_count( $source, 'capture_pipeline_baseline( $batch_id )' ) );
        $this->assertStringContainsString( "'dom_baseline_capture_failed'", $source );
    }

    public function test_staging_self_update_never_attempts_b2_backup(): void {
        $source = (string) file_get_contents( __DIR__ . '/../inc/class-rest.php' );
        $this->assertStringContainsString( '$is_pipeline_staging', $source );
        $this->assertStringContainsString( "'rpcare_pipeline_environment'", $source );
        $this->assertStringContainsString( "get_option( \$pipeline_environment_option, 'production' )", $source );
        $this->assertMatchesRegularExpression(
            '/if \( ! \$is_pipeline_staging && class_exists\( \'RP_Care_Task_Backup\' \)/',
            $source
        );
    }
}
