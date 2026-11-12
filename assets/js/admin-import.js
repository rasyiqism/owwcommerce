/**
 * OwwCommerce Admin Import/Export v2
 */
document.addEventListener('DOMContentLoaded', function () {
    const importBtn = document.getElementById('import-submit-btn');
    const progressBar = document.getElementById('import-progress-bar');
    const progressWrap = document.getElementById('import-progress');
    const statusText = document.getElementById('import-status');
    const fileInput = document.getElementById('excel-file');

    if (importBtn && fileInput) {
        importBtn.addEventListener('click', async (e) => {
            if (!fileInput.files.length) {
                alert('Silakan pilih file CSV terlebih dahulu.');
                return;
            }

            const formData = new FormData();
            formData.append('excel_file', fileInput.files[0]);

            importBtn.disabled = true;
            importBtn.textContent = 'Mengimpor...';
            progressWrap.style.display = 'block';
            progressBar.style.width = '50%';
            statusText.textContent = 'Membaca file CSV dan memproses data...';
            statusText.style.color = '#666';

            try {
                const res = await fetch(`${owwcSettings.restUrl}owwc/v1/import/excel`, {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': owwcSettings.nonce
                    },
                    body: formData
                });

                const contentType = res.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    const data = await res.json();
                    if (res.ok) {
                        progressBar.style.width = '100%';
                        statusText.textContent = `Berhasil mengimpor ${data.imported} produk dari CSV!`;
                        statusText.style.color = 'green';
                        setTimeout(() => {
                            window.location.href = '?page=owwc-products';
                        }, 2000);
                    } else {
                        throw new Error(data.message || 'Gagal mengimpor.');
                    }
                } else {
                    const text = await res.text();
                    console.error('Server Response:', text);
                    throw new Error('Server mengembalikan respon tidak valid (bukan JSON). Cek konsol browser untuk detail.');
                }
            } catch (error) {
                alert(error.message);
                importBtn.disabled = false;
                importBtn.textContent = 'Mulai Import';
                progressWrap.style.display = 'none';
            }
        });
    }

    // Export Logic
    document.getElementById('owwc-export-btn')?.addEventListener('click', () => {
        const exportUrl = window.owwcBuildRestUrl('owwc/v1/import/export', { _wpnonce: owwcSettings.nonce });
        window.location.href = exportUrl;
    });

    const backupBtn = document.getElementById('owwc-backup-btn');
    backupBtn?.addEventListener('click', () => {
        const backupUrl = window.owwcBuildRestUrl('owwc/v1/import/backup', { _wpnonce: owwcSettings.nonce });
        const origHtml = backupBtn.innerHTML;
        backupBtn.disabled = true;
        backupBtn.textContent = 'Menyiapkan ZIP...';
        window.location.href = backupUrl;
        setTimeout(() => {
            backupBtn.disabled = false;
            backupBtn.innerHTML = origHtml;
        }, 2500);
    });

    const restoreBtn = document.getElementById('owwc-restore-btn');
    const restoreFile = document.getElementById('owwc-restore-file');
    const restoreStatus = document.getElementById('owwc-restore-status');
    restoreBtn?.addEventListener('click', () => {
        const file = restoreFile?.files?.[0];
        if (!file) {
            owwcAlert('Pilih file backup (.zip) terlebih dahulu.');
            return;
        }

        owwcConfirm('Seluruh data OwwCommerce saat ini akan ditimpa dengan isi file backup. Lanjutkan restore?', async () => {
            const origHtml = restoreBtn.innerHTML;
            restoreBtn.disabled = true;
            restoreBtn.textContent = 'Memulihkan Backup...';
            if (restoreStatus) {
                restoreStatus.style.display = 'block';
                restoreStatus.style.color = '#666';
                restoreStatus.textContent = 'Mengunggah & mengekstrak data serta gambar...';
            }

            const formData = new FormData();
            formData.append('backup_file', file);

            try {
                const res = await fetch(window.owwcBuildRestUrl('owwc/v1/import/restore'), {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': owwcSettings.nonce },
                    body: formData
                });
                const data = await res.json();

                if (res.ok && data.success) {
                    if (restoreStatus) {
                        restoreStatus.style.color = '#10b981';
                        restoreStatus.textContent = `Restore berhasil! ${data.rows_restored} baris data, ${data.media_restored} file gambar, dan ${data.options_restored} pengaturan dipulihkan.`;
                    }
                    owwcAlert(`Restore berhasil! ${data.rows_restored} baris data dan ${data.media_restored} gambar dipulihkan.`);
                } else {
                    const msg = data.message || 'Gagal memulihkan backup.';
                    if (restoreStatus) {
                        restoreStatus.style.color = '#ef4444';
                        restoreStatus.textContent = msg;
                    }
                    owwcAlert(msg);
                }
            } catch (err) {
                if (restoreStatus) {
                    restoreStatus.style.color = '#ef4444';
                    restoreStatus.textContent = 'Kesalahan jaringan saat mengunggah backup.';
                }
            } finally {
                restoreBtn.disabled = false;
                restoreBtn.innerHTML = origHtml;
            }
        });
    });
});
