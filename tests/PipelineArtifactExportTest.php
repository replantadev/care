<?php

use PHPUnit\Framework\TestCase;

final class PipelineArtifactExportTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [];
        RP_Care_Pipeline_Client::$artifact_downloader = null;
        RP_Care_Pipeline_Client::$artifact_uploader   = null;
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'production' );
    }

    protected function tearDown(): void {
        RP_Care_Pipeline_Client::$artifact_downloader = null;
        RP_Care_Pipeline_Client::$artifact_uploader   = null;
    }

    private function invoke( array $payload ): array {
        $method = new ReflectionMethod( RP_Care_Pipeline_Client::class, 'handle_export_update_artifact' );
        $method->setAccessible( true );
        return $method->invoke( null, [ 'command_id' => 'cmd-artifact-1', 'payload' => $payload ], 'https://pc.example', 'raw-token', 'prod-id' );
    }

    private function seedUpdate(): void {
        $transient = new stdClass();
        $transient->response = [
            'vendor/pro.php' => (object) [
                'new_version' => '2.0.0',
                'package'     => 'https://vendor.example/private.zip?token=secret',
            ],
        ];
        set_site_transient( 'update_plugins', $transient );
    }

    public function test_exports_exact_inventory_package_without_exposing_source_url(): void {
        $this->seedUpdate();
        RP_Care_Pipeline_Client::$artifact_downloader = static function ( string $url ): string {
            $tmp = tempnam( sys_get_temp_dir(), 'care_export_' );
            file_put_contents( $tmp, 'licensed-pro-zip-bytes' );
            return $tmp;
        };
        RP_Care_Pipeline_Client::$artifact_uploader = function ( string $url, array $args ): array {
            $this->assertStringEndsWith( '/pipeline/artifact-ingest/cmd-artifact-1', $url );
            $this->assertSame( 'raw-token', $args['headers']['X-Pipeline-Token'] );
            $this->assertArrayNotHasKey( 'X-Package-URL', $args['headers'] );
            $sha = hash( 'sha256', 'licensed-pro-zip-bytes' );
            $this->assertSame( $sha, $args['headers']['X-Artifact-SHA256'] );
            return [
                'response' => [ 'code' => 200 ],
                'body'     => wp_json_encode( [ 'artifact' => [ 'id' => 'artifact-1', 'sha256' => $sha, 'size' => 22 ] ] ),
            ];
        };

        $result = $this->invoke( [ 'plugin_file' => 'vendor/pro.php', 'target_version' => '2.0.0' ] );
        $this->assertTrue( $result['success'] );
        $this->assertSame( 'artifact-1', $result['artifact_id'] );
        $this->assertArrayNotHasKey( 'package_url', $result );
    }

    public function test_rejects_inventory_version_drift_before_download(): void {
        $this->seedUpdate();
        $called = false;
        RP_Care_Pipeline_Client::$artifact_downloader = static function () use ( &$called ): string {
            $called = true;
            return '';
        };
        $result = $this->invoke( [ 'plugin_file' => 'vendor/pro.php', 'target_version' => '2.0.1' ] );
        $this->assertFalse( $result['success'] );
        $this->assertSame( 'artifact_not_in_current_inventory', $result['error'] );
        $this->assertFalse( $called );
    }

    public function test_staging_cannot_export_vendor_artifacts(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        $result = $this->invoke( [ 'plugin_file' => 'vendor/pro.php', 'target_version' => '2.0.0' ] );
        $this->assertFalse( $result['success'] );
        $this->assertSame( 'artifact_export_requires_production', $result['error'] );
    }

    public function test_product_code_contains_no_tls_verification_bypass(): void {
        $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/inc' ) );
        foreach ( $files as $file ) {
            if ( ! $file->isFile() || 'php' !== $file->getExtension() ) continue;
            $this->assertDoesNotMatchRegularExpression(
                "/sslverify'\\s*=>\\s*false/",
                file_get_contents( $file->getPathname() ),
                $file->getPathname()
            );
        }
    }
}
