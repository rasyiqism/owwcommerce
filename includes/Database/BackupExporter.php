<?php
namespace OwwCommerce\Database;

use WP_Error;
use ZipArchive;

/**
 * Class BackupExporter
 * Mengekspor seluruh data OwwCommerce (tabel custom, pengaturan, dan file gambar lokal)
 * menjadi satu arsip ZIP yang dapat diunduh.
 */
class BackupExporter {

    private const TABLES = [
        'oww_products',
        'oww_customers',
        'oww_orders',
        'oww_order_items',
        'oww_categories',
        'oww_product_category_rel',
        'oww_coupons',
        'oww_attributes',
        'oww_attribute_terms',
        'oww_product_variations',
        'oww_reviews',
    ];

    /**
     * Membuat file arsip ZIP sementara berisi JSON data & folder media/.
     *
     * @return string|WP_Error Path file ZIP sementara atau WP_Error jika gagal.
     */
    public function create_backup_zip() {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error(
                'zip_missing',
                'Ekstensi PHP ZipArchive tidak tersedia di server ini.',
                [ 'status' => 500 ]
            );
        }

        if ( ! function_exists( 'wp_tempnam' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp_file = wp_tempnam( 'owwc-backup.zip' );
        if ( ! $tmp_file ) {
            return new WP_Error( 'temp_file_error', 'Gagal membuat file sementara untuk backup.', [ 'status' => 500 ] );
        }

        $zip = new ZipArchive();
        if ( $zip->open( $tmp_file, ZipArchive::OVERWRITE ) !== true ) {
            @unlink( $tmp_file );
            return new WP_Error( 'zip_open_error', 'Gagal membuka arsip ZIP.', [ 'status' => 500 ] );
        }

        $tables_data  = $this->export_tables();
        $options_data = $this->export_options();
        $media_items  = $this->collect_media_files( $tables_data );

        $added_media = [];
        foreach ( $media_items as $item ) {
            if ( is_readable( $item['abs_path'] ) ) {
                $zip->addFile( $item['abs_path'], $item['zip_path'] );
                $added_media[] = [
                    'attachment_id' => $item['attachment_id'],
                    'source_url'    => $item['source_url'],
                    'zip_path'      => $item['zip_path'],
                    'file_size'     => filesize( $item['abs_path'] ),
                ];
            }
        }

        $payload = [
            'meta' => [
                'plugin'         => 'OwwCommerce',
                'plugin_version' => defined( 'OWWCOMMERCE_VERSION' ) ? OWWCOMMERCE_VERSION : 'unknown',
                'wp_version'     => get_bloginfo( 'version' ),
                'site_url'       => home_url( '/' ),
                'exported_at'    => gmdate( 'c' ),
            ],
            'tables'      => $tables_data,
            'options'     => $options_data,
            'media_files' => $added_media,
        ];

        $zip->addFromString(
            'data/owwcommerce-backup.json',
            wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
        );

        $zip->addFromString( 'README.txt', $this->build_readme( $payload ) );
        $zip->close();

        return $tmp_file;
    }

    /**
     * Mengambil seluruh baris dari tabel custom OwwCommerce.
     */
    private function export_tables(): array {
        global $wpdb;
        $data = [];

        foreach ( self::TABLES as $short_name ) {
            $full_table = $wpdb->prefix . $short_name;
            $exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table ) );
            if ( $exists !== $full_table ) {
                $data[ $short_name ] = [];
                continue;
            }
            $rows = $wpdb->get_results( "SELECT * FROM `{$full_table}`", ARRAY_A );
            $data[ $short_name ] = is_array( $rows ) ? $rows : [];
        }

        return $data;
    }

    /**
     * Mengambil seluruh opsi pengaturan OwwCommerce dari wp_options.
     */
    private function export_options(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE (option_name LIKE 'owwc\_%' OR option_name LIKE 'owwcommerce\_%')
             AND option_name NOT LIKE '\_transient\_%'",
            ARRAY_A
        );

        $options = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $options[ $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
            }
        }

        return $options;
    }

    /**
     * Mengumpulkan daftar file gambar fisik dari produk (image_id, gallery_ids, image_url lokal)
     * dan bukti pembayaran pesanan.
     */
    private function collect_media_files( array $tables_data ): array {
        $upload_dir = wp_upload_dir();
        $basedir    = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );
        $baseurl    = trailingslashit( $upload_dir['baseurl'] );

        $files_by_path = [];

        $register_attachment = function( int $att_id, string $fallback_url = '' ) use ( &$files_by_path, $basedir ) {
            if ( $att_id <= 0 ) {
                return;
            }
            $file = get_attached_file( $att_id );
            if ( ! $file || ! is_file( $file ) ) {
                return;
            }
            $norm = wp_normalize_path( $file );
            if ( isset( $files_by_path[ $norm ] ) ) {
                return;
            }
            $rel = str_starts_with( $norm, $basedir ) ? substr( $norm, strlen( $basedir ) ) : basename( $norm );
            $files_by_path[ $norm ] = [
                'attachment_id' => $att_id,
                'source_url'    => wp_get_attachment_url( $att_id ) ?: $fallback_url,
                'abs_path'      => $norm,
                'zip_path'      => 'media/' . ltrim( $rel, '/' ),
            ];
        };

        $register_local_url = function( string $url ) use ( &$files_by_path, $basedir, $baseurl, $register_attachment ) {
            if ( empty( $url ) ) {
                return;
            }
            if ( str_starts_with( $url, $baseurl ) ) {
                $rel  = substr( $url, strlen( $baseurl ) );
                $path = wp_normalize_path( $basedir . ltrim( $rel, '/' ) );
                if ( is_file( $path ) && ! isset( $files_by_path[ $path ] ) ) {
                    $att_id = function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $url ) : 0;
                    $files_by_path[ $path ] = [
                        'attachment_id' => $att_id ?: null,
                        'source_url'    => $url,
                        'abs_path'      => $path,
                        'zip_path'      => 'media/' . ltrim( $rel, '/' ),
                    ];
                    return;
                }
            }
            if ( function_exists( 'attachment_url_to_postid' ) ) {
                $att_id = (int) attachment_url_to_postid( $url );
                if ( $att_id > 0 ) {
                    $register_attachment( $att_id, $url );
                }
            }
        };

        foreach ( $tables_data['oww_products'] ?? [] as $product ) {
            if ( ! empty( $product['image_id'] ) ) {
                $register_attachment( (int) $product['image_id'], (string) ( $product['image_url'] ?? '' ) );
            }
            if ( ! empty( $product['image_url'] ) ) {
                $register_local_url( (string) $product['image_url'] );
            }
            if ( ! empty( $product['gallery_ids'] ) ) {
                $ids = array_filter( array_map( 'intval', explode( ',', (string) $product['gallery_ids'] ) ) );
                foreach ( $ids as $gid ) {
                    $register_attachment( $gid );
                }
            }
        }

        foreach ( $tables_data['oww_orders'] ?? [] as $order ) {
            if ( ! empty( $order['payment_proof'] ) ) {
                $register_local_url( (string) $order['payment_proof'] );
            }
        }

        return array_values( $files_by_path );
    }

    /**
     * Membuat ringkasan teks di dalam arsip backup.
     */
    private function build_readme( array $payload ): string {
        $lines = [
            '=== OwwCommerce Full Backup ===',
            'Site URL      : ' . $payload['meta']['site_url'],
            'Plugin Version: ' . $payload['meta']['plugin_version'],
            'WP Version    : ' . $payload['meta']['wp_version'],
            'Exported (UTC): ' . $payload['meta']['exported_at'],
            '',
            '--- Ringkasan Data Tabel ---',
        ];

        foreach ( $payload['tables'] as $table => $rows ) {
            $lines[] = sprintf( '- %-26s : %d baris', $table, count( $rows ) );
        }

        $lines[] = '';
        $lines[] = sprintf( '- %-26s : %d pengaturan', 'wp_options (owwc_*)', count( $payload['options'] ) );
        $lines[] = sprintf( '- %-26s : %d file gambar', 'media/', count( $payload['media_files'] ) );
        $lines[] = '';
        $lines[] = 'File data lengkap tersimpan di: data/owwcommerce-backup.json';

        return implode( "\n", $lines ) . "\n";
    }
}
