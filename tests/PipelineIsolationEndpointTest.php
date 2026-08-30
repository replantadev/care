<?php

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'RP_Care_Pipeline_Client' ) ) {
    class RP_Care_Pipeline_Client {
        public const OPT_ENVIRONMENT = 'rpcare_pipeline_environment';
    }
}

require_once __DIR__ . '/../inc/class-rest.php';

use PHPUnit\Framework\TestCase;

class PipelineIsolationEndpointTest extends TestCase {
    private const TOKEN = 'isolation-endpoint-token';
    private RP_Care_REST $rest;

    protected function setUp(): void {
        $GLOBALS['_wp_options']  = [];
        $GLOBALS['_rest_routes'] = [];
        update_option( 'rpcare_options', [ 'site_token' => self::TOKEN ] );
        RP_Care_REST::$isolation_report_reader = fn() => [
            'passed' => true,
            'checks' => [ [ 'name' => 'noindex', 'status' => 'ok', 'level' => 'critical' ] ],
        ];
        $this->rest = new RP_Care_REST();
    }

    protected function tearDown(): void {
        RP_Care_REST::$isolation_report_reader = null;
        RP_Care_REST::$pipeline_poll_runner = null;
        RP_Care_REST::$pipeline_local_runner = null;
    }

    private function request( ?string $token = null ): WP_REST_Request {
        $request = new WP_REST_Request();
        if ( null !== $token ) {
            $request->set_header( 'X-Hub-Token', $token );
        }
        return $request;
    }

    public function test_route_is_registered_as_post(): void {
        $this->rest->register_routes();
        $matches = array_values( array_filter(
            $GLOBALS['_rest_routes'],
            fn( $r ) => '/pipeline/isolation-report' === $r['route']
        ) );
        $this->assertCount( 1, $matches );
        $this->assertSame( 'POST', $matches[0]['args']['methods'] );
    }

    public function test_pipeline_poll_now_route_is_registered_as_post(): void {
        $this->rest->register_routes();
        $matches = array_values( array_filter(
            $GLOBALS['_rest_routes'],
            fn( $r ) => '/pipeline/poll-now' === $r['route']
        ) );
        $this->assertCount( 1, $matches );
        $this->assertSame( 'POST', $matches[0]['args']['methods'] );
    }

    public function test_pipeline_poll_now_is_authenticated_and_runs_once(): void {
        $calls = 0;
        RP_Care_REST::$pipeline_poll_runner = static function () use ( &$calls ): array {
            $calls++;
            return [ 'ok' => true, 'commands_processed' => 1 ];
        };

        $this->assertSame( 403, $this->rest->hub_pipeline_poll_now( $this->request() )->get_status() );
        $response = $this->rest->hub_pipeline_poll_now( $this->request( hash( 'sha256', self::TOKEN ) ) );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $response->get_data()['commands_processed'] );
        $this->assertSame( 1, $calls );
    }

    public function test_pipeline_local_run_is_authenticated_and_bound_to_ids(): void {
        $seen = [];
        RP_Care_REST::$pipeline_local_runner = static function ( string $batch_id, string $command_id ) use ( &$seen ): array {
            $seen = [ $batch_id, $command_id ];
            return [ 'ok' => true, 'result' => [ 'success' => true ] ];
        };

        $request = $this->request( hash( 'sha256', self::TOKEN ) );
        $request->set_param( 'batch_id', 'batch-1' );
        $request->set_param( 'command_id', 'command-1' );
        $response = $this->rest->hub_pipeline_run_local_command( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ 'batch-1', 'command-1' ], $seen );
        $this->assertSame( 403, $this->rest->hub_pipeline_run_local_command( new WP_REST_Request() )->get_status() );
    }

    public function test_missing_or_wrong_token_is_rejected(): void {
        $this->assertSame( 403, $this->rest->hub_pipeline_isolation_report( $this->request() )->get_status() );
        $this->assertSame( 403, $this->rest->hub_pipeline_isolation_report( $this->request( 'wrong' ) )->get_status() );
    }

    public function test_production_instance_is_rejected(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'production' );
        $response = $this->rest->hub_pipeline_isolation_report( $this->request( hash( 'sha256', self::TOKEN ) ) );
        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( 'not_staging', $response->get_data()['error'] );
    }

    public function test_staging_returns_canonical_report(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        $response = $this->rest->hub_pipeline_isolation_report( $this->request( hash( 'sha256', self::TOKEN ) ) );
        $data     = $response->get_data();
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $data['schema_version'] );
        $this->assertSame( 'staging', $data['environment'] );
        $this->assertTrue( $data['passed'] );
        $this->assertSame( 'noindex', $data['checks'][0]['name'] );
    }

    public function test_failed_isolation_is_reported_without_mutation(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        RP_Care_REST::$isolation_report_reader = fn() => [
            'passed' => false,
            'checks' => [ [ 'name' => 'db_isolated', 'status' => 'failed', 'level' => 'critical' ] ],
        ];
        $data = $this->rest->hub_pipeline_isolation_report(
            $this->request( hash( 'sha256', self::TOKEN ) )
        )->get_data();
        $this->assertFalse( $data['passed'] );
        $this->assertSame( 'db_isolated', $data['checks'][0]['name'] );
    }

    public function test_hub_config_can_prepare_the_paired_care_executor(): void {
        $request = $this->request( hash( 'sha256', self::TOKEN ) );
        $request->set_param( 'update_executor', 'care_pipeline' );
        $request->set_param( 'native_auto_updates', 'disabled' );
        $request->set_param( 'pipeline_enabled', true );
        $request->set_param( 'staging_role', 'staging' );

        $response = $this->rest->hub_config( $request );
        $this->assertSame( 200, $response->get_status() );
        $opts = get_option( 'rpcare_options', [] );
        $this->assertSame( 'care_pipeline', $opts['update_executor'] );
        $this->assertSame( 'disabled', $opts['native_auto_updates'] );
        $this->assertSame( 'staging', $opts['staging_role'] );
        $this->assertTrue( (bool) get_option( RP_Care_Pipeline_Client::OPT_ENABLED, false ) );
    }
}
