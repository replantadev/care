<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ZZPipelineEmailSuppressionTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [];
    }

    public function test_ecommerce_runner_accepts_the_active_staging_email_sink(): void {
		$this->loadRunner();
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        $suite  = new RP_Care_Test_Suite_Ecommerce();
        $method = new ReflectionMethod( $suite, 'check_emails_disabled' );
        $method->setAccessible( true );

        $result = $method->invoke( $suite );

        self::assertSame( 'ok', $result['status'] );
        self::assertTrue( RP_Care_Staging_Email_Sink::is_active() );
    }

	private function loadRunner(): void {
		require_once __DIR__ . '/../inc/class-pipeline-client.php';
		require_once __DIR__ . '/../inc/class-staging-email-sink.php';
		require_once __DIR__ . '/../inc/class-test-runner.php';
	}
}
