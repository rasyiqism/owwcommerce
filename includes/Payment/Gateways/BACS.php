<?php
namespace OwwCommerce\Payment\Gateways;

use OwwCommerce\Payment\AbstractPaymentGateway;

/**
 * Metode pembayaran BACS (Transfer Bank).
 */
class BACS extends AbstractPaymentGateway {

    private string $id = 'bacs';
    
    public function get_id(): string {
        return $this->id;
    }

    public function get_title(): string {
        return get_option( 'owwc_bacs_title', 'Transfer Bank' );
    }

    public function get_description(): string {
        return get_option( 'owwc_bacs_description', 'Silahkan transfer langsung ke nomor rekening kami. Pesanan Anda tidak akan dikirim sebelum dana masuk.' );
    }

    public function needs_redirect(): bool {
        return false;
    }

    public function process_payment( int $order_id ): array {
        return [
            'success'      => true,
            'redirect_url' => $this->update_order_status_and_get_redirect( $order_id, 'on-hold' ),
            'message'      => 'Pembayaran telah dipilih, silahkan lakukan transfer bank ke rekening yang tertera.'
        ];
    }
}
