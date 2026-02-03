<?php
// app/includes/modern_footer.php
// Modern dashboard footer
?>
            </div> <!-- End page-content -->
            
            <!-- Footer -->
            <footer class="modern-footer">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <p>
                        <strong><i class="bi bi-lightning-charge-fill me-1"></i>PLN Inventory System</strong>
                        <span class="d-none d-md-inline"> — Sistem Manajemen Inventaris</span>
                    </p>
                    <p class="mb-0">
                        <small>&copy; <?= date('Y') ?> PLN. All rights reserved.</small>
                    </p>
                </div>
            </footer>
        </main>
    </div>

    <!-- Logout Confirmation Modal -->
    <div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content" style="border: none; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.15);">
                <div class="modal-body text-center" style="padding: 32px 24px;">
                    <div style="width: 70px; height: 70px; background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(220, 38, 38, 0.15) 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                        <i class="bi bi-box-arrow-right" style="font-size: 28px; color: #dc2626;"></i>
                    </div>
                    <h5 style="font-weight: 700; color: var(--text-dark); margin-bottom: 8px;">Keluar dari Sistem?</h5>
                    <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 24px;">Anda akan keluar dari sesi saat ini. Pastikan semua pekerjaan telah disimpan.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="padding: 10px 24px; border-radius: 8px;">
                            <i class="bi bi-x-lg me-1"></i>Batal
                        </button>
                        <a href="/index.php?page=logout" class="btn btn-danger" style="padding: 10px 24px; border-radius: 8px;">
                            <i class="bi bi-check-lg me-1"></i>Ya, Keluar
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Modern Dashboard JS -->
    <script src="/public/assets/js/modern-dashboard.js"></script>
    
    <script>
    function showLogoutConfirm() {
        const modal = new bootstrap.Modal(document.getElementById('logoutConfirmModal'));
        modal.show();
    }
    </script>
</body>
</html>
