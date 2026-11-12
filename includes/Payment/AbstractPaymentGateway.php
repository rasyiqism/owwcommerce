<?php
namespace OwwCommerce\Payment;

/**
 * Base class untuk payment gateway agar logika update status order
 * dan pembuatan redirect URL order-received tidak terduplikasi.
 */
abstract class AbstractPaymentGateway implements PaymentGateway {

    /**
     * Memperbarui status pesanan dan mengembalikan URL halaman order-received lengkap dengan token ?key=.
     */
    protected function update_order_status_and_get_redirect( int $order_id, string $status ): string {
        global $wpdb;
        $table = $wpdb->prefix . 'oww_orders';

        $wpdb->update(
            $table,
            [ 'status' => $status ],
            [ 'id' => $order_id ],
            [ '%s' ],
            [ '%d' ]
        );

        $order_key = $wpdb->get_var( $wpdb->prepare(
            "SELECT order_key FROM {$table} WHERE id = %d",
            $order_id
        ) );

        $redirect_url = home_url( '/checkout/order-received/' . $order_id );
        if ( ! empty( $order_key ) ) {
            $redirect_url = add_query_arg( 'key', $order_key, $redirect_url );
        }

        return $redirect_url;
    }
}
