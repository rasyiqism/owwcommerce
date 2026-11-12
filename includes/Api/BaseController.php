<?php
namespace OwwCommerce\Api;

use WP_REST_Controller;

/**
 * Base REST API Controller untuk menyediakan otorisasi standar admin OwwCommerce.
 */
abstract class BaseController extends WP_REST_Controller {

    /**
     * Memastikan pengguna memiliki hak akses admin toko (manage_options).
     */
    public function admin_permission_check(): bool {
        return current_user_can( 'manage_options' );
    }
}
