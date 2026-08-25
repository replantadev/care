<?php

use PHPUnit\Framework\TestCase;

final class InvoiceBrandingTest extends TestCase {
    public function test_settings_page_exposes_a_media_library_invoice_logo_field(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/inc/settings-page.php' );
        $script = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin.js' );
        $plugin = file_get_contents( dirname( __DIR__ ) . '/replanta-care.php' );

        $this->assertStringContainsString( "wp_enqueue_media();", $source );
        $this->assertStringContainsString( 'rpcare_options[invoice_logo_id]', $source );
        $this->assertStringContainsString( 'rpcare_options[invoice_footer_image_id]', $source );
        $this->assertStringContainsString( 'wp_attachment_is_image( $logo_id )', $source );
        $this->assertStringContainsString( "[ 'image/png', 'image/jpeg' ]", $source );
        $this->assertStringContainsString( "library: {type: 'image'}", $script );
        $this->assertStringContainsString( "input: '#rpc-invoice-logo-id'", $script );
        $this->assertStringContainsString( "input: '#rpc-invoice-footer-image-id'", $script );
        $this->assertStringContainsString( 'function rpcare_get_document_logo_id()', $plugin );
        $this->assertStringContainsString( 'function rpcare_get_invoice_footer_image_id()', $plugin );
    }
}
