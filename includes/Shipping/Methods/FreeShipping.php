<?php
namespace OwwCommerce\Shipping\Methods;

use OwwCommerce\Shipping\AbstractShippingMethod;

/**
 * Metode pengiriman Gratis Ongkir.
 */
class FreeShipping extends AbstractShippingMethod {
    
    private string $title;
    private float $min_amount;

    public function __construct() {
        // Option/Settings: owwc_free_shipping_title
        // Option/Settings: owwc_free_shipping_min_amount
        $this->title = get_option( 'owwc_free_shipping_title', 'Gratis Ongkir' );
        $this->min_amount = (float) get_option( 'owwc_free_shipping_min_amount', 0.00 );
    }

    public function get_id(): string {
        return 'free_shipping';
    }

    public function get_title(): string {
        return $this->title;
    }

    public function calculate_shipping( array $packages ): float {
        return 0.00; // Selalu gratis
    }

    public function is_available( array $packages ): bool {
        if ( $this->min_amount > 0 ) {
            return $this->calculate_packages_subtotal( $packages ) >= $this->min_amount;
        }

        return true;
    }
}
