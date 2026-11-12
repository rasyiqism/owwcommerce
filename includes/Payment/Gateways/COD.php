<?php
namespace OwwCommerce\Payment\Gateways;

use OwwCommerce\Payment\AbstractPaymentGateway;

/**
 * Metode pembayaran COD (Bayar di Tempat).
 */
class COD extends AbstractPaymentGateway {

    private string $id = 'cod';
    
    public function get_id(): string {
        return $this->id;
    }

    public function get_title(): string {
        return get_option( 'owwc_cod_title', 'Bayar di Tempat (COD)' );
    }

    public function get_description(): string {
        return get_option( 'owwc_cod_description', 'Bayar tunai kepada kurir saat pesanan Anda tiba.' );
    }

    public function needs_redirect(): bool {
        return false;
    }

    public function process_payment( int $order_id ): array {
        return [
            'success'      => true,
            'redirect_url' => $this->update_order_status_and_get_redirect( $order_id, 'processing' ),
            'message'      => 'Pesanan diterima, siapkan uang tunai saat kurir tiba.'
        ];
    }
}
