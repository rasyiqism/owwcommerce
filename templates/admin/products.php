<?php
/**
 * Template Halaman Katalog Produk OwwCommerce
 * Komentar dan deskripsi menggunakan Bahasa Indonesia.
 */
?>
<div class="owwc-admin-wrap">
    <div class="owwc-admin-header owwc-mb-3">
        <h1><?php esc_html_e( 'Katalog Produk', 'owwcommerce' ); ?></h1>
        <div class="owwc-flex-align-center owwc-flex-wrap owwc-flex-gap-2">
            <!-- Helper Update Stok Massal Compact -->
            <div id="owwc-bulk-set-all-wrap" class="owwc-bulk-set-all-wrap" style="display: none;">
                <input type="number" id="owwc-bulk-stock-all-input" class="owwc-admin-input owwc-bulk-stock-input-sm" placeholder="Stok..." min="0">
                <button type="button" id="owwc-bulk-stock-all-btn" class="owwc-admin-btn owwc-btn-secondary owwc-admin-btn-sm owwc-bulk-set-all-btn">Set Semua</button>
            </div>
            <button type="button" id="owwc-bulk-stock-btn" class="owwc-admin-btn owwc-btn-secondary owwc-admin-btn-sm">Update Stok Massal</button>
            <a href="?page=owwc-products&action=add" class="owwc-admin-btn owwc-admin-btn-sm" style="text-decoration: none;">Tambah Produk Baru</a>
        </div>
    </div>
    
    <!-- Render Table via Javascript API -->
    <div id="owwc-products-app">
        <div class="owwc-admin-card owwc-p-0" style="overflow: hidden;">
            <p class="owwc-text-muted owwc-p-4" style="text-align: center; margin: 0;">Memuat data produk...</p>
        </div>
    </div>
</div>
