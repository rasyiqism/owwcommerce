<?php
namespace OwwCommerce\Database;

use WP_Error;
use ZipArchive;

/**
 * Class BackupImporter
 * Memulihkan (restore) data lengkap OwwCommerce dan file gambar lokal
 * dari arsip ZIP yang dihasilkan oleh BackupExporter.
 */
class BackupImporter {

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
     * Memulihkan seluruh data & media dari file ZIP backup.
     *
     * @param string $zip_path Path file ZIP yang diunggah.
     * @return array|WP_Error Ringkasan hasil restore atau WP_Error jika gagal.
     */
    public function restore_from_zip( string $zip_path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'zip_missing', 'Ekstensi PHP ZipArchive tidak tersedia.', [ 'status' => 500 ] );
        }

        if ( ! file_exists( $zip_path ) ) {
            return new WP_Error( 'file_missing', 'File backup tidak ditemukan.', [ 'status' => 400 ] );
        }

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) !== true ) {
            return new WP_Error( 'zip_invalid', 'File ZIP tidak valid atau rusak.', [ 'status' => 400 ] );
        }

        $json_raw = $zip->getFromName( 'data/owwcommerce-backup.json' );
        if ( $json_raw === false ) {
            $zip->close();
            return new WP_Error( 'missing_json', 'Arsip ZIP bukan backup OwwCommerce yang valid (data/owwcommerce-backup.json tidak ditemukan).', [ 'status' => 400 ] );
        }

        $payload = json_decode( $json_raw, true );
        if ( ! is_array( $payload ) || empty( $payload['tables'] ) || ( $payload['meta']['plugin'] ?? '' ) !== 'OwwCommerce' ) {
            $zip->close();
            return new WP_Error( 'invalid_payload', 'Format data JSON backup tidak valid.', [ 'status' => 400 ] );
        }

        // 1. Restore file media dari folder media/ ke WordPress Media Library
        [ $id_map, $url_map, $media_count ] = $this->restore_media( $zip, $payload['media_files'] ?? [] );
        $zip->close();

        // 2. Restore tabel custom OwwCommerce
        $rows_restored = $this->restore_tables( $payload['tables'], $id_map, $url_map );

        // 3. Restore pengaturan (wp_options)
        $options_count = $this->restore_options( $payload['options'] ?? [] );

        return [
            'success'          => true,
            'media_restored'   => $media_count,
            'rows_restored'    => $rows_restored,
            'options_restored' => $options_count,
        ];
    }

    /**
     * Mengekstrak file gambar dari ZIP dan mendaftarkannya ke WordPress Media Library.
     */
    private function restore_media( ZipArchive $zip, array $media_files ): array {
        $id_map  = [];
        $url_map = [];
        $count   = 0;

        if ( ! function_exists( 'wp_upload_bits' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        foreach ( $media_files as $item ) {
            $zip_entry = $item['zip_path'] ?? '';
            if ( empty( $zip_entry ) ) {
                continue;
            }

            $bytes = $zip->getFromName( $zip_entry );
            if ( $bytes === false ) {
                continue;
            }

            $filename = basename( $zip_entry );
            $upload   = wp_upload_bits( $filename, null, $bytes );
            if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
                continue;
            }

            $filetype = wp_check_filetype( $upload['file'], null );
            $att_id   = wp_insert_attachment( [
                'post_mime_type' => $filetype['type'] ?: 'image/png',
                'post_title'     => sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) ),
                'post_status'    => 'inherit',
            ], $upload['file'] );

            if ( ! is_wp_error( $att_id ) && $att_id ) {
                $meta = wp_generate_attachment_metadata( $att_id, $upload['file'] );
                if ( is_array( $meta ) ) {
                    wp_update_attachment_metadata( $att_id, $meta );
                }

                $new_url = wp_get_attachment_url( $att_id ) ?: ( $upload['url'] ?? '' );
                if ( ! empty( $item['attachment_id'] ) ) {
                    $id_map[ (int) $item['attachment_id'] ] = (int) $att_id;
                }
                if ( ! empty( $item['source_url'] ) && $new_url ) {
                    $url_map[ $item['source_url'] ] = $new_url;
                }
                $count++;
            }
        }

        return [ $id_map, $url_map, $count ];
    }

    /**
     * Mengosongkan dan mengisi ulang seluruh tabel custom OwwCommerce dengan pemetaan media baru.
     */
    private function restore_tables( array $tables, array $id_map, array $url_map ): int {
        global $wpdb;
        $total_rows = 0;

        // Pastikan skema terbaru tersedia sebelum insert
        Installer::install();

        foreach ( self::TABLES as $short_name ) {
            $full_table = $wpdb->prefix . $short_name;
            $exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table ) );
            if ( $exists !== $full_table ) {
                continue;
            }

            $wpdb->query( "TRUNCATE TABLE `{$full_table}`" );

            $rows = $tables[ $short_name ] ?? [];
            if ( ! is_array( $rows ) ) {
                continue;
            }

            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }

                if ( $short_name === 'oww_products' ) {
                    if ( ! empty( $row['image_id'] ) && isset( $id_map[ (int) $row['image_id'] ] ) ) {
                        $row['image_id'] = $id_map[ (int) $row['image_id'] ];
                    }
                    if ( ! empty( $row['image_url'] ) && isset( $url_map[ $row['image_url'] ] ) ) {
                        $row['image_url'] = $url_map[ $row['image_url'] ];
                    }
                    if ( ! empty( $row['gallery_ids'] ) ) {
                        $old_ids = array_filter( array_map( 'intval', explode( ',', (string) $row['gallery_ids'] ) ) );
                        $new_ids = array_map( fn( $gid ) => $id_map[ $gid ] ?? $gid, $old_ids );
                        $row['gallery_ids'] = implode( ',', $new_ids );
                    }
                } elseif ( $short_name === 'oww_orders' ) {
                    if ( ! empty( $row['payment_proof'] ) && isset( $url_map[ $row['payment_proof'] ] ) ) {
                        $row['payment_proof'] = $url_map[ $row['payment_proof'] ];
                    }
                }

                if ( $wpdb->insert( $full_table, $row ) !== false ) {
                    $total_rows++;
                }
            }
        }

        return $total_rows;
    }

    /**
     * Memulihkan opsi pengaturan OwwCommerce (kecuali ID halaman jika post tidak ada).
     */
    private function restore_options( array $options ): int {
        $count = 0;
        $page_options = [ 'owwc_page_shop_id', 'owwc_page_cart_id', 'owwc_page_checkout_id', 'owwc_page_account_id' ];

        foreach ( $options as $name => $value ) {
            if ( ! is_string( $name ) ) {
                continue;
            }
            if ( ! str_starts_with( $name, 'owwc_' ) && ! str_starts_with( $name, 'owwcommerce_' ) ) {
                continue;
            }
            if ( in_array( $name, $page_options, true ) && ! get_post( (int) $value ) ) {
                continue;
            }
            update_option( $name, $value );
            $count++;
        }

        return $count;
    }
}
