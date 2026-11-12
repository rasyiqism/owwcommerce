<?php
namespace OwwCommerce\Shipping;

/**
 * Base class untuk metode pengiriman agar tidak menduplikasi perhitungan subtotal keranjang.
 */
abstract class AbstractShippingMethod implements ShippingMethod {

    /**
     * Menghitung subtotal dari daftar item keranjang/paket.
     * Mendukung payload yang sudah memiliki key 'price' maupun raw cart item (product_id, variation_id, qty).
     */
    protected function calculate_packages_subtotal( array $packages ): float {
        global $wpdb;
        $subtotal = 0.0;

        foreach ( $packages as $item ) {
            $qty   = isset( $item['qty'] ) ? (int) $item['qty'] : ( isset( $item['quantity'] ) ? (int) $item['quantity'] : 1 );
            $price = 0.0;

            if ( isset( $item['price'] ) ) {
                $price = (float) $item['price'];
            } elseif ( ! empty( $item['product_id'] ) ) {
                if ( ! empty( $item['variation_id'] ) ) {
                    $var_price = $wpdb->get_var( $wpdb->prepare(
                        "SELECT COALESCE(sale_price, price) FROM {$wpdb->prefix}oww_product_variations WHERE id = %d",
                        (int) $item['variation_id']
                    ) );
                    if ( $var_price !== null && $var_price !== '' ) {
                        $price = (float) $var_price;
                    }
                }
                if ( $price <= 0 ) {
                    $prod_price = $wpdb->get_var( $wpdb->prepare(
                        "SELECT COALESCE(sale_price, price) FROM {$wpdb->prefix}oww_products WHERE id = %d",
                        (int) $item['product_id']
                    ) );
                    $price = (float) ( $prod_price ?: 0 );
                }
            }

            $subtotal += ( $price * $qty );
        }

        return $subtotal;
    }
}
