<?php
namespace OwwCommerce\Api;

use WP_REST_Server;
use WP_Error;
use OwwCommerce\Repositories\ProductRepository;
use OwwCommerce\Models\Product;
use OwwCommerce\Database\BackupExporter;
use OwwCommerce\Database\BackupImporter;

class ImportController extends BaseController {

    private ProductRepository $repository;

    public function __construct() {
        $this->namespace  = 'owwc/v1';
        $this->rest_base  = 'import';
        $this->repository = new ProductRepository();
        
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/excel', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'import_excel' ],
                'permission_callback' => [ $this, 'admin_permission_check' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/export', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'export_excel' ],
                'permission_callback' => [ $this, 'admin_permission_check' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/backup', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'download_backup' ],
                'permission_callback' => [ $this, 'admin_permission_check' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/restore', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'restore_backup' ],
                'permission_callback' => [ $this, 'admin_permission_check' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/test-lib', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'test_lib' ],
                'permission_callback' => [ $this, 'admin_permission_check' ],
            ],
        ] );
    }

    /**
     * Impor produk dari file CSV (menggantikan Excel).
     */
    public function import_excel( $request ) {
        set_time_limit( 0 );

        $files = $request->get_file_params();
        if ( empty( $files['excel_file'] ) ) {
            return new WP_Error( 'rest_no_file', 'No CSV file uploaded.', [ 'status' => 400 ] );
        }

        $file_path = $files['excel_file']['tmp_name'];
        
        try {
            // Bersihkan SEMUA buffer untuk mencegah output tak terduga (Warning/Notice)
            while ( ob_get_level() ) {
                ob_end_clean();
            }
            ob_start();

            if ( ( $handle = fopen( $file_path, 'r' ) ) === false ) {
                throw new \Exception( 'Gagal membuka file CSV.' );
            }

            // Membaca header
            $header = fgetcsv( $handle, 0, ',' );
            if ( ! $header ) {
                fclose( $handle );
                return new WP_Error( 'rest_empty_file', 'File CSV kosong atau tidak memiliki data.', [ 'status' => 400 ] );
            }
            $header = array_map( 'trim', $header );

            $imported = 0;
            while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
                // Skip empty rows
                if ( empty( array_filter( $row ) ) ) continue;

                if ( count( $header ) !== count( $row ) ) {
                    continue;
                }

                $data = array_combine( $header, $row );
                if ( ! $data ) continue;

                // Skip jika title kosong
                if ( empty( trim( $data['title'] ?? '' ) ) ) {
                    continue;
                }

                $product = new Product( [
                    'title'       => $data['title'] ?? '',
                    'slug'        => sanitize_title( $data['title'] ?? '' ),
                    'description' => $data['description'] ?? '',
                    'price'       => floatval( $data['price'] ?? 0 ),
                    'sale_price'  => !empty($data['sale_price']) && trim($data['sale_price']) !== '' ? floatval($data['sale_price']) : null,
                    'sku'         => $data['sku'] ?? '',
                    'stock_qty'   => intval( $data['stock'] ?? 0 ),
                    'image_url'   => $data['image_url'] ?? null,
                    'status'      => 'publish',
                    'type'        => 'simple'
                ] );

                $this->repository->save( $product );
                $imported++;
            }
            fclose( $handle );

            return rest_ensure_response( [
                'success'  => true,
                'imported' => $imported
            ] );

        } catch ( \Exception $e ) {
            error_log( "OwwCommerce CSV Import Error: " . $e->getMessage() );
            return new WP_Error( 'rest_import_failed', 'Gagal membaca file CSV: ' . $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    /**
     * Ekspor produk ke file CSV (menggantikan Excel).
     */
    public function export_excel() {
        set_time_limit( 0 );

        try {
            $products = $this->repository->get_all( 9999, 0 );
            
            // Bersihkan buffer
            while ( ob_get_level() ) {
                ob_end_clean();
            }
            ob_start();

            $filename = 'owwcommerce-products-' . date('Y-m-d') . '.csv';
            
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            header( 'Cache-Control: max-age=0' );

            $output = fopen( 'php://output', 'w' );
            
            // Tulis Header
            $headers = [ 'title', 'description', 'price', 'sale_price', 'sku', 'stock', 'image_url' ];
            fputcsv( $output, $headers );

            // Tulis Data
            foreach ( $products as $p ) {
                if ( empty( trim( $p->title ) ) ) continue;

                fputcsv( $output, [
                    $p->title,
                    $p->description,
                    $p->price,
                    $p->sale_price,
                    $p->sku,
                    $p->stock_qty,
                    $p->image_url
                ] );
            }
            
            fclose( $output );
            exit;

        } catch ( \Exception $e ) {
            if ( ob_get_length() ) ob_clean();
            wp_die( "Gagal membuat file CSV: " . $e->getMessage() );
        }
    }

    /**
     * Mengunduh backup lengkap OwwCommerce (semua tabel, opsi, dan gambar lokal) sebagai ZIP.
     */
    public function download_backup() {
        $exporter = new BackupExporter();
        $zip_path = $exporter->create_backup_zip();

        if ( is_wp_error( $zip_path ) ) {
            return $zip_path;
        }

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        $filename = 'owwcommerce-backup-' . gmdate( 'Y-m-d-His' ) . '.zip';

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $zip_path ) );
        header( 'Cache-Control: no-store, no-cache, must-revalidate' );
        header( 'Pragma: no-cache' );

        readfile( $zip_path );
        @unlink( $zip_path );
        exit;
    }

    /**
     * Memulihkan seluruh data & gambar OwwCommerce dari file ZIP backup.
     */
    public function restore_backup( $request ) {
        $files = $request->get_file_params();

        if ( empty( $files['backup_file'] ) || empty( $files['backup_file']['tmp_name'] ) ) {
            return new WP_Error( 'no_file', 'File backup (.zip) tidak ditemukan dalam permintaan.', [ 'status' => 400 ] );
        }

        $file = $files['backup_file'];
        $ext  = strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) );
        if ( $ext !== 'zip' ) {
            return new WP_Error( 'invalid_file_type', 'Hanya file arsip .zip backup OwwCommerce yang didukung.', [ 'status' => 400 ] );
        }

        $importer = new BackupImporter();
        $result   = $importer->restore_from_zip( $file['tmp_name'] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function test_lib() {
        ob_start();
        return rest_ensure_response( [
            'class_exists' => true,
            'version' => 'native-csv',
            'php_version' => PHP_VERSION,
            'extensions' => [
                'gd' => extension_loaded('gd'),
                'zip' => extension_loaded('zip'),
                'xml' => extension_loaded('xml'),
            ]
        ] );
    }
}
