<?php
// app/includes/modern_footer.php
// Modern dashboard footer
?>
            </div> <!-- End page-content -->
            
            <!-- Footer -->
            <footer class="modern-footer" style="position: relative;">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <p class="mb-0">
                        <strong><i class="bi bi-lightning-charge-fill me-1"></i>SIPINTAR</strong>
                        <span class="d-none d-md-inline"> — Sistem pengelolaan inventaris dan material</span>
                    </p>
                    <div style="position: relative; display: inline-flex; align-items: center; gap: 4px;">
                        <small>&copy; <?= date('Y') ?> PLN. All rights reserved.</small>
                        <button class="info-btn-footer" onclick="toggleCreditFooter(event)" aria-expanded="false" aria-controls="creditBoxFooter" title="Info">
                            <i class="bi bi-info-circle"></i>
                        </button>
                        <div class="credit-box-footer" id="creditBoxFooter">
                            <strong>SIPINTAR</strong><br>
                            Sistem Pengelolaan Inventaris & Material<br>
                            <small style="display: block; margin-top: 6px; border-top: 1px solid #e0e0e0; padding-top: 6px;">
                                Developed by Felix Salim & Javier Dien</small>
                        </div>
                    </div>
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
    
    <style>
        /* ===== INFO BUTTON FOOTER (circular blue) ===== */
        .info-btn-footer {
            background: #0d6efd;
            border: none;
            color: #ffffff;
            font-size: 14px;
            cursor: pointer;
            width: 36px;
            height: 36px;
            padding: 0;
            margin-left: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            box-shadow: 0 6px 18px rgba(13,110,253,0.18);
            transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.12s ease;
        }

        .info-btn-footer i {
            font-size: 16px;
            line-height: 1;
        }

        .info-btn-footer:hover {
            transform: translateY(-2px) scale(1.03);
            box-shadow: 0 10px 30px rgba(13,110,253,0.22);
            background: #0b5ed7;
        }

        .info-btn-footer:focus {
            outline: none;
            box-shadow: 0 0 0 4px rgba(13,110,253,0.18);
        }

        /* ===== CREDIT BOX FOOTER ===== */
        .credit-box-footer {
            position: absolute;
            bottom: calc(100% + 8px);
            right: 0;
            width: 260px;
            max-width: calc(100vw - 32px);
            box-sizing: border-box;

            background: #ffffff;
            border-radius: 10px;
            padding: 16px 18px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15), 0 4px 8px rgba(0,0,0,0.10);
            border: 1px solid #e0e0e0;

            font-size: 12px;
            color: #333;
            text-align: left;
            line-height: 1.6;

            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transform: translateY(10px);
            transition: all 0.2s ease;
            z-index: 10000;
        }

        .credit-box-footer::after {
            content: '';
            position: absolute;
            bottom: -8px;
            right: 12px;
            width: 0;
            height: 0;
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-top: 8px solid #ffffff;
            filter: drop-shadow(0 2px 3px rgba(0,0,0,0.1));
        }

        .credit-box-footer.active {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
            transform: translateY(0);
        }

        .credit-box-footer strong {
            color: #0d6efd;
            font-size: 13px;
            display: block;
            margin-bottom: 4px;
        }

        @media (max-width: 768px) {
            .credit-box-footer {
                width: 240px;
                font-size: 11px;
                padding: 12px 14px;
                line-height: 1.5;
                right: -10px;
            }
            
            .credit-box-footer strong {
                font-size: 12px;
            }

            .info-btn-footer {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }
        }

        @media (max-width: 480px) {
            .credit-box-footer {
                width: calc(100vw - 24px);
                right: -20px;
            }
        }
    </style>
    
    <script>
    function showLogoutConfirm() {
        const modal = new bootstrap.Modal(document.getElementById('logoutConfirmModal'));
        modal.show();
    }

    // Toggle Credit Box Footer (expose globally)
    window.toggleCreditFooter = function (e) {
        if (e && e.stopPropagation) e.stopPropagation();
        const box = document.getElementById('creditBoxFooter');
        const btn = e && (e.currentTarget || e.target) ? (e.currentTarget || e.target) : document.querySelector('.info-btn-footer');
        if (!box) return;
        const isActive = box.classList.toggle('active');
        try { if (btn && btn.setAttribute) btn.setAttribute('aria-expanded', isActive ? 'true' : 'false'); } catch (err) {}
    };

    // Close credit box footer when clicking outside
    document.addEventListener('click', function (e) {
        const box = document.getElementById('creditBoxFooter');
        const btn = document.querySelector('.info-btn-footer');
        if (box && btn && !box.contains(e.target) && !btn.contains(e.target)) {
            if (box.classList.contains('active')) {
                box.classList.remove('active');
                try { btn.setAttribute('aria-expanded', 'false'); } catch (err) {}
            }
        }
    });
    </script>
</body>
</html>
