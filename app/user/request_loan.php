<?php
// app/user/request_loan_new.php - Modern Request Loan Page
if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}
// Prevent blacklisted users
if (!empty($_SESSION['user']['is_blacklisted'])) {
    echo "<div class='alert alert-danger'><i class='bi bi-ban me-2'></i>Akun Anda diblokir. Hubungi admin.</div>";
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$userId = $_SESSION['user']['id'];

// Pre-selected item from catalog
$preSelectedItem = (int)($_GET['item'] ?? 0);

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inventory_id = (int)($_POST['inventory_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($inventory_id <= 0 || $quantity <= 0) {
        $errors[] = 'Pilih barang dan masukkan jumlah yang valid.';
    } else {
        // Check inventory exists
        $stmt = $pdo->prepare('SELECT * FROM inventories WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$inventory_id]);
        $inv = $stmt->fetch();
        if (!$inv) {
            $errors[] = 'Barang tidak ditemukan.';
        } elseif ($inv['stock_available'] < $quantity) {
            $errors[] = 'Stok tidak cukup (tersedia: ' . $inv['stock_available'] . ').';
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare('INSERT INTO loans (inventory_id, user_id, quantity, note) VALUES (?, ?, ?, ?)');
        $stmt->execute([$inventory_id, $userId, $quantity, $note]);
        $redirectUrl = '/index.php?page=history&msg=' . urlencode('Request submitted');
        if (!headers_sent()) {
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            echo '<script>window.location.href = ' . json_encode($redirectUrl) . ';</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl, ENT_QUOTES) . '"></noscript>';
            exit;
        }
    }
}

// Fetch inventories for select
$stmt = $pdo->query('SELECT id, name, code, stock_available, image FROM inventories WHERE deleted_at IS NULL AND stock_available > 0 ORDER BY name ASC');
$items = $stmt->fetchAll();

// Get preselected item details if exists
$preSelectedDetails = null;
if ($preSelectedItem) {
    foreach ($items as $item) {
        if ($item['id'] == $preSelectedItem) {
            $preSelectedDetails = $item;
            break;
        }
    }
}
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">
            <i class="bi bi-plus-circle me-2"></i>Ajukan Peminjaman
        </h1>
        <p class="text-muted mb-0">Isi formulir untuk mengajukan peminjaman barang</p>
    </div>
    <a href="/index.php?page=catalog" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Kembali
    </a>
</div>

<div class="row">
    <div class="col-lg-8">
        <!-- Main Form Card -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-file-text me-2"></i>Form Peminjaman
                </h5>
            </div>
            <div class="card-body">
                <?php foreach($errors as $e): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endforeach; ?>

                <form method="POST" id="loanForm">
                    <!-- Single Searchable Item Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-box-seam me-1"></i>Pilih Barang <span class="text-danger">*</span>
                        </label>
                        <select name="inventory_id" class="form-select form-select-lg" required id="itemSelect">
                            <option value="">Ketik untuk mencari barang...</option>
                            <?php foreach($items as $it): ?>
                            <option value="<?= $it['id'] ?>" 
                                    data-stock="<?= $it['stock_available'] ?>"
                                    data-image="<?= htmlspecialchars($it['image'] ?? '') ?>"
                                    data-code="<?= htmlspecialchars($it['code'] ?? '') ?>"
                                    data-name="<?= htmlspecialchars($it['name']) ?>"
                                    <?= $preSelectedItem == $it['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($it['name']) ?> (<?= htmlspecialchars($it['code']) ?>) - Tersedia: <?= $it['stock_available'] ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Klik dan ketik nama atau kode barang untuk mencari</small>
                    </div>

                    <!-- Item Preview -->
                    <div id="itemPreview" class="mb-4" style="display: <?= $preSelectedDetails ? 'block' : 'none' ?>;">
                        <div class="item-preview-card">
                            <div class="d-flex align-items-center">
                                <?php if($preSelectedDetails && $preSelectedDetails['image']): ?>
                                <img id="previewImage" 
                                     src="/public/assets/uploads/<?= htmlspecialchars($preSelectedDetails['image']) ?>" 
                                     alt="" class="preview-img">
                                <div id="previewPlaceholder" class="preview-placeholder" style="display: none;">
                                    <i class="bi bi-box-seam"></i>
                                </div>
                                <?php else: ?>
                                <img id="previewImage" src="" alt="" class="preview-img" style="display: none;">
                                <div id="previewPlaceholder" class="preview-placeholder">
                                    <i class="bi bi-box-seam"></i>
                                </div>
                                <?php endif; ?>
                                <div class="preview-info">
                                    <div class="text-muted small mb-1">Barang yang dipilih:</div>
                                    <div id="previewName" class="fw-bold text-primary fs-5">
                                        <?= $preSelectedDetails ? htmlspecialchars($preSelectedDetails['name']) : '' ?>
                                    </div>
                                    <div id="previewCode" class="text-muted small">
                                        <?= $preSelectedDetails ? htmlspecialchars($preSelectedDetails['code']) : '' ?>
                                    </div>
                                    <div id="previewStock" class="mt-1">
                                        <?php if($preSelectedDetails): ?>
                                        <span class="badge bg-success">
                                            <i class="bi bi-check-circle me-1"></i>Tersedia: <?= $preSelectedDetails['stock_available'] ?> unit
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quantity -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-123 me-1"></i>Jumlah yang Dipinjam <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <button type="button" class="btn btn-outline-secondary" id="decreaseQty">
                                <i class="bi bi-dash-lg"></i>
                            </button>
                            <input type="number" name="quantity" class="form-control text-center" 
                                   min="1" required id="quantityInput" placeholder="0"
                                   <?= $preSelectedDetails ? 'max="'.$preSelectedDetails['stock_available'].'"' : '' ?>>
                            <button type="button" class="btn btn-outline-secondary" id="increaseQty">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                        <small class="text-muted" id="stockHint">
                            <?= $preSelectedDetails ? 'Maksimal peminjaman: '.$preSelectedDetails['stock_available'].' unit' : 'Pilih barang terlebih dahulu' ?>
                        </small>
                    </div>

                    <!-- Note -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-chat-text me-1"></i>Catatan / Alasan Peminjaman
                        </label>
                        <textarea name="note" class="form-control" rows="4" 
                                  placeholder="Jelaskan alasan atau keperluan peminjaman barang ini (opsional, tapi disarankan untuk mempercepat approval)"></textarea>
                        <small class="text-muted">Catatan ini akan dibaca oleh admin saat mereview permintaan Anda.</small>
                    </div>

                    <hr class="my-4">

                    <!-- Submit Buttons -->
                    <div class="d-flex gap-3">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-send me-2"></i>Kirim Permintaan
                        </button>
                        <a href="/index.php?page=catalog" class="btn btn-outline-secondary btn-lg">
                            <i class="bi bi-x-lg me-2"></i>Batal
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Process Info Card -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-info-circle me-2"></i>Proses Peminjaman
                </h5>
            </div>
            <div class="card-body">
                <div class="process-timeline">
                    <div class="process-step">
                        <div class="step-icon active">
                            <i class="bi bi-1-circle-fill"></i>
                        </div>
                        <div class="step-content">
                            <div class="fw-semibold">Ajukan Permintaan</div>
                            <small class="text-muted">Isi form dan kirim permintaan</small>
                        </div>
                    </div>
                    <div class="process-step">
                        <div class="step-icon">
                            <i class="bi bi-2-circle"></i>
                        </div>
                        <div class="step-content">
                            <div class="fw-semibold">Validasi Admin</div>
                            <small class="text-muted">Admin mereview permintaan</small>
                        </div>
                    </div>
                    <div class="process-step">
                        <div class="step-icon">
                            <i class="bi bi-3-circle"></i>
                        </div>
                        <div class="step-content">
                            <div class="fw-semibold">Upload Dokumen</div>
                            <small class="text-muted">Upload Dokumen Berita Acara Serah Terima (BAST)</small>
                        </div>
                    </div>
                    <div class="process-step">
                        <div class="step-icon">
                            <i class="bi bi-4-circle"></i>
                        </div>
                        <div class="step-content">
                            <div class="fw-semibold">Persetujuan Final</div>
                            <small class="text-muted">Admin menyetujui peminjaman</small>
                        </div>
                    </div>
                    <div class="process-step">
                        <div class="step-icon success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="step-content">
                            <div class="fw-semibold">Selesai</div>
                            <small class="text-muted">Barang dapat diambil</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Info Box -->
        <div class="card">
            <div class="card-header bg-warning-subtle">
                <h5 class="card-title mb-0 text-warning-emphasis">
                    <i class="bi bi-exclamation-triangle me-2"></i>Perhatian
                </h5>
            </div>
            <div class="card-body">
                <ul class="mb-0 ps-3">
                    <li class="mb-2">Pastikan memilih jumlah sesuai kebutuhan.</li>
                    <li class="mb-2">Sertakan alasan yang jelas untuk mempercepat approval.</li>
                    <li class="mb-2">Barang harus dikembalikan dalam kondisi baik.</li>
                    <li class="mb-2">Hubungi admin jika ada pertanyaan.</li>
                    <li class="mb-2">admin@pln.com.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style>
.item-preview-card {
    background: linear-gradient(135deg, var(--bg-tertiary) 0%, var(--bg-secondary) 100%);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
}

.preview-img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 10px;
    margin-right: 1rem;
}

.preview-placeholder {
    width: 80px;
    height: 80px;
    background: var(--bg-secondary);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 1rem;
}

.preview-placeholder i {
    font-size: 2rem;
    color: var(--text-muted);
}

.preview-info {
    flex: 1;
}

.process-timeline {
    position: relative;
}

.process-step {
    display: flex;
    align-items: flex-start;
    margin-bottom: 1.25rem;
    position: relative;
}

.process-step:last-child {
    margin-bottom: 0;
}

.process-step:not(:last-child)::after {
    content: '';
    position: absolute;
    left: 11px;
    top: 28px;
    width: 2px;
    height: calc(100% - 8px);
    background: var(--border-color);
}

.step-icon {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 0.75rem;
    color: var(--text-muted);
    flex-shrink: 0;
}

.step-icon i {
    font-size: 1.25rem;
}

.step-icon.active {
    color: var(--primary);
}

.step-icon.success {
    color: var(--success);
}

.step-content {
    flex: 1;
}

#quantityInput {
    font-size: 1.25rem;
    font-weight: 600;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const itemSelect = document.getElementById('itemSelect');
    const preview = document.getElementById('itemPreview');
    const previewImage = document.getElementById('previewImage');
    const previewPlaceholder = document.getElementById('previewPlaceholder');
    const previewName = document.getElementById('previewName');
    const previewCode = document.getElementById('previewCode');
    const previewStock = document.getElementById('previewStock');
    const stockHint = document.getElementById('stockHint');
    const quantityInput = document.getElementById('quantityInput');
    const decreaseBtn = document.getElementById('decreaseQty');
    const increaseBtn = document.getElementById('increaseQty');
    
    let maxStock = <?= $preSelectedDetails ? $preSelectedDetails['stock_available'] : 0 ?>;

    // Initialize Tom Select for searchable dropdown
    const tomSelect = new TomSelect('#itemSelect', {
        placeholder: 'Ketik untuk mencari barang...',
        searchField: ['text'],
        sortField: { field: 'text', direction: 'asc' },
        maxOptions: 100,
        render: {
            option: function(data, escape) {
                const stock = data.$option?.dataset?.stock || '';
                const code = data.$option?.dataset?.code || '';
                const image = data.$option?.dataset?.image || '';
                return `<div class="d-flex align-items-center py-2">
                    ${image ? `<img src="/public/assets/uploads/${escape(image)}" class="rounded me-2" style="width:40px;height:40px;object-fit:cover;">` : 
                             `<div class="rounded me-2 d-flex align-items-center justify-content-center bg-light" style="width:40px;height:40px;"><i class="bi bi-box-seam text-muted"></i></div>`}
                    <div>
                        <div class="fw-semibold">${escape(data.text.split(' (')[0])}</div>
                        <small class="text-muted">${escape(code)} | Stok: ${escape(stock)}</small>
                    </div>
                </div>`;
            },
            item: function(data, escape) {
                return `<div>${escape(data.text)}</div>`;
            }
        },
        onChange: function(value) {
            if (!value) return;
            const option = itemSelect.querySelector(`option[value="${value}"]`);
            if (option) {
                updatePreview(option);
            }
        }
    });

    function updatePreview(selected) {
        if (selected && selected.value) {
            const stock = parseInt(selected.dataset.stock);
            const image = selected.dataset.image;
            const code = selected.dataset.code;
            const name = selected.text.split(' (')[0];
            
            maxStock = stock;
            
            preview.style.display = 'block';
            previewName.textContent = name;
            previewCode.textContent = code;
            previewStock.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Tersedia: ' + stock + ' unit</span>';
            stockHint.textContent = 'Maksimal peminjaman: ' + stock + ' unit';
            quantityInput.max = stock;
            
            if (image) {
                previewImage.src = '/public/assets/uploads/' + image;
                previewImage.style.display = 'block';
                previewPlaceholder.style.display = 'none';
            } else {
                previewImage.style.display = 'none';
                previewPlaceholder.style.display = 'flex';
            }
        } else {
            preview.style.display = 'none';
            stockHint.textContent = 'Pilih barang terlebih dahulu';
            quantityInput.removeAttribute('max');
            maxStock = 0;
        }
    }

    // Quantity buttons
    decreaseBtn.addEventListener('click', function() {
        let val = parseInt(quantityInput.value) || 0;
        if (val > 1) {
            quantityInput.value = val - 1;
        }
    });

    increaseBtn.addEventListener('click', function() {
        let val = parseInt(quantityInput.value) || 0;
        if (maxStock === 0 || val < maxStock) {
            quantityInput.value = val + 1;
        }
    });

    // Validate quantity on input
    quantityInput.addEventListener('input', function() {
        let val = parseInt(this.value) || 0;
        if (maxStock > 0 && val > maxStock) {
            this.value = maxStock;
        }
        if (val < 1 && this.value !== '') {
            this.value = 1;
        }
    });

    // Trigger preview on page load if pre-selected
    <?php if ($preSelectedItem && $preSelectedDetails): ?>
    const preSelected = itemSelect.querySelector('option[value="<?= $preSelectedItem ?>"]');
    if (preSelected) updatePreview(preSelected);
    <?php endif; ?>
});
</script>
