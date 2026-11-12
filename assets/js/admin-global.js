/**
 * OwwCommerce Admin Global Scripts
 * 
 * Menyediakan fungsi helper global seperti dialog custom owwcAlert dan owwcConfirm
 * untuk menggantikan dialog bawaan browser agar terlihat premium (Gold & Dark).
 */

(function() {
    /**
     * Membuat struktur DOM modal dialog dasar.
     * @param {string} title - Judul modal
     * @param {string} message - Pesan/konten modal
     * @param {boolean} isConfirm - Apakah bertipe konfirmasi (butuh tombol Batal)
     * @returns {Object} Elemen modal dan tombol aksi
     */
    function createModalDOM(title, message, isConfirm = false) {
        // Hapus modal lama jika masih tersisa
        const oldModal = document.getElementById('owwc-custom-dialog-backdrop');
        if (oldModal) oldModal.remove();

        const backdrop = document.createElement('div');
        backdrop.id = 'owwc-custom-dialog-backdrop';
        backdrop.className = 'owwc-custom-modal-backdrop';

        const box = document.createElement('div');
        box.className = 'owwc-custom-modal-box';

        const header = document.createElement('div');
        header.className = 'owwc-custom-modal-header';
        header.innerHTML = `⚠️ <span>${title}</span>`;

        const body = document.createElement('div');
        body.className = 'owwc-custom-modal-body';
        body.innerText = message;

        const footer = document.createElement('div');
        footer.className = 'owwc-custom-modal-footer';

        const btnConfirm = document.createElement('button');
        btnConfirm.type = 'button';
        btnConfirm.className = 'owwc-custom-modal-btn owwc-custom-modal-btn-confirm';
        btnConfirm.innerText = 'OK';

        let btnCancel = null;
        if (isConfirm) {
            btnConfirm.innerText = 'Ya, Lanjutkan';
            btnCancel = document.createElement('button');
            btnCancel.type = 'button';
            btnCancel.className = 'owwc-custom-modal-btn owwc-custom-modal-btn-cancel';
            btnCancel.innerText = 'Batal';
            footer.appendChild(btnCancel);
        }

        footer.appendChild(btnConfirm);
        box.appendChild(header);
        box.appendChild(body);
        box.appendChild(footer);
        backdrop.appendChild(box);
        document.body.appendChild(backdrop);

        // Memicu reflow browser agar transisi fade-in berjalan
        backdrop.offsetHeight;
        backdrop.classList.add('show');

        return { backdrop, btnConfirm, btnCancel };
    }

    /**
     * Menutup modal dengan efek transisi fade-out.
     * @param {HTMLElement} backdrop 
     */
    function closeModal(backdrop) {
        backdrop.classList.remove('show');
        setTimeout(() => {
            backdrop.remove();
        }, 200);
    }

    /**
     * Menampilkan dialog informasi custom.
     * @param {string} message - Pesan yang ingin disampaikan
     * @param {Function} [callback] - Fungsi opsional setelah tombol OK ditekan
     */
    window.owwcAlert = function(message, callback) {
        const { backdrop, btnConfirm } = createModalDOM('Informasi', message, false);

        btnConfirm.addEventListener('click', function() {
            closeModal(backdrop);
            if (typeof callback === 'function') {
                callback();
            }
        });
    };

    /**
     * Menampilkan dialog konfirmasi custom.
     * @param {string} message - Pesan konfirmasi
     * @param {Function} onConfirm - Dipanggil jika user memilih "Ya, Lanjutkan"
     * @param {Function} [onCancel] - Dipanggil jika user memilih "Batal"
     */
    window.owwcConfirm = function(message, onConfirm, onCancel) {
        const { backdrop, btnConfirm, btnCancel } = createModalDOM('Konfirmasi Tindakan', message, true);

        btnConfirm.addEventListener('click', function() {
            closeModal(backdrop);
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
        });

        if (btnCancel) {
            btnCancel.addEventListener('click', function() {
                closeModal(backdrop);
                if (typeof onCancel === 'function') {
                    onCancel();
                }
            });
        }
    };

    /**
     * Escape HTML untuk mencegah XSS saat merender string dinamis di template literal.
     * @param {string} str
     * @returns {string}
     */
    window.owwcEscapeHTML = function(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/[&<>'"]/g, tag => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        }[tag] || tag));
    };

    /**
     * Membangun URL REST API WordPress secara aman (mendukung Plain ?rest_route= maupun Pretty /wp-json/).
     * @param {string} endpoint - Path endpoint, misal 'owwc/v1/products'
     * @param {Object} [params] - Query parameters opsional
     * @returns {string}
     */
    window.owwcBuildRestUrl = function(endpoint, params = {}) {
        const base = (typeof owwcSettings !== 'undefined' && owwcSettings.restUrl)
            ? owwcSettings.restUrl
            : '/wp-json/';
        const cleanEndpoint = String(endpoint).replace(/^\/+/, '');
        let url = base + cleanEndpoint;
        const entries = Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '');
        if (entries.length > 0) {
            const sep = url.includes('?') ? '&' : '?';
            const qs = entries.map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
            url += sep + qs;
        }
        return url;
    };
})();
