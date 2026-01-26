<?php
// app/user/request_item.php - Request Item Page (for permanent requests)
if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}

if (!empty($_SESSION['user']['is_blacklisted'])) {
    echo "<div class='alert alert-danger'><i class='bi bi-ban me-2'></i>Akun Anda diblokir. Hubungi admin.</div>";
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$userId = $_SESSION['user']['id'];

$preSelectedItem = (int)($_GET['item'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inventory_id = (int)($_POST['inventory_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($inventory_id <= 0 || $quantity <= 0) {
        $errors[] = 'Pilih barang dan masukkan jumlah yang valid.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM inventories WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$inventory_id]);
        $inv = $stmt->fetch();
        if (!$inv) {
            $errors[] = 'Barang tidak ditemukan.';
        } elseif ($inv['stock_total'] < $quantity) {
            $errors[] = 'Stok total tidak mencukupi (total: ' . $inv['stock_total'] . ').';
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare('INSERT INTO requests (inventory_id, user_id, quantity, note, stage, status) VALUES (?, ?, ?, ?, "pending", "pending")');
        $stmt->execute([$inventory_id, $userId, $quantity, $note]);
        
        echo '<script>window.location.href = "/index.php?page=user_request_history&msg=' . urlencode('Permintaan berhasil diajukan') . '";</script>';
        exit;
    }
}

// Fetch inventories
$stmt = $pdo->query('SELECT id, name, code, stock_total, stock_available, image FROM inventories WHERE deleted_at IS NULL AND stock_total > 0 ORDER BY name ASC');
$items = $stmt->fetchAll();

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

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">
            <i class="bi bi-cart-plus me-2"></i>Ajukan Permintaan
        </h1>
        <p class="text-muted mb-0">Ajukan permintaan barang untuk diambil secara permanen</p>
    </div>
    <a href="/index.php?page=catalog" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Kembali
    </a>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="modern-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-file-text me-2" style="color: var(--primary-light);"></i>Form Permintaan
                </h5>
            </div>
            <div class="card-body">
                <?php foreach($errors as $e): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endforeach; ?>

                <form method="POST" id="requestForm">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-box-seam me-1"></i>Pilih Barang <span class="text-danger">*</span>
                        </label>
                        <select name="inventory_id" class="form-select form-select-lg searchable-select" required id="itemSelect">
                            <option value="">-- Ketik atau Pilih Barang --</option>
                            <?php foreach($items as $it): ?>
                            <option value="<?= $it['id'] ?>" 
                                    data-stock="<?= $it['stock_total'] ?>"
                                    data-available="<?= $it['stock_available'] ?>"
                                    data-image="<?= htmlspecialchars($it['image'] ?? '') ?>"
                                    data-code="<?= htmlspecialchars($it['code'] ?? '') ?>"
                                    <?= $preSelectedItem == $it['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($it['name']) ?> (<?= htmlspecialchars($it['code']) ?>) - Total: <?= $it['stock_total'] ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="itemPreview" class="mb-4" style="display: <?= $preSelectedDetails ? 'block' : 'none' ?>;">
                        <div class="p-3 rounded" style="background: var(--bg-main); border: 1px solid var(--border-color);">
                            <div class="d-flex align-items-center">
                                <div id="previewPlaceholder" class="me-3" style="width: 60px; height: 60px; background: rgba(26, 154, 170, 0.1); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-box-seam" style="font-size: 24px; color: var(--primary-light);"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">Barang yang dipilih:</div>
                                    <div id="previewName" class="fw-bold" style="color: var(--text-dark); font-size: 1.1rem;"></div>
                                    <div id="previewCode" class="text-muted small"></div>
                                    <div id="previewStock" class="mt-1"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-123 me-1"></i>Jumlah yang Diminta <span class="text-danger">*</span>
                        </label>
                        <div class="input-group" style="max-width: 200px;">
                            <button type="button" class="btn btn-outline-secondary" id="decreaseBtn">
                                <i class="bi bi-dash"></i>
                            </button>
                            <input type="number" name="quantity" id="quantityInput" class="form-control text-center" value="1" min="1" required>
                            <button type="button" class="btn btn-outline-secondary" id="increaseBtn">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                        <small class="text-muted" id="stockHint">Pilih barang terlebih dahulu</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-chat-left-text me-1"></i>Catatan (Opsional)
                        </label>
                        <textarea name="note" class="form-control" rows="3" placeholder="Alasan permintaan atau catatan tambahan..."></textarea>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-send me-2"></i>Ajukan Permintaan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="modern-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-info-circle me-2" style="color: var(--primary-light);"></i>Proses Permintaan
                </h5>
            </div>
            <div class="card-body">
                <div class="process-timeline">
                    <div class="process-step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <strong>Ajukan Permintaan</strong>
                            <p class="text-muted small mb-0">Isi form dan kirim permintaan</p>
                        </div>
                    </div>
                    <div class="process-step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <strong>Validasi Admin</strong>
                            <p class="text-muted small mb-0">Admin mereview permintaan</p>
                        </div>
                    </div>
                    <div class="process-step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <strong>Upload Dokumen</strong>
                            <p class="text-muted small mb-0">Upload dokumen serah terima</p>
                        </div>
                    </div>
                    <div class="process-step">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <strong>Persetujuan Final</strong>
                            <p class="text-muted small mb-0">Admin menyetujui permintaan</p>
                        </div>
                    </div>
                    <div class="process-step completed">
                        <div class="step-number"><i class="bi bi-check"></i></div>
                        <div class="step-content">
                            <strong>Selesai</strong>
                            <p class="text-muted small mb-0">Barang dapat diambil</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modern-card mt-3">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-exclamation-triangle me-2" style="color: var(--warning);"></i>Perhatian
                </h5>
            </div>
            <div class="card-body">
                <ul class="mb-0" style="color: var(--text-muted); font-size: 14px;">
                    <li>Permintaan bersifat permanen (barang tidak dikembalikan)</li>
                    <li>Stok barang akan berkurang setelah disetujui</li>
                    <li>Pastikan jumlah sesuai kebutuhan</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style>
.process-timeline { padding: 0; }
.process-step { display: flex; align-items: flex-start; margin-bottom: 16px; position: relative; }
.process-step:not(:last-child)::after { content: ''; position: absolute; left: 15px; top: 32px; width: 2px; height: calc(100% - 8px); background: var(--border-color); }
.step-number { width: 32px; height: 32px; background: var(--primary-light); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; flex-shrink: 0; margin-right: 12px; }
.process-step.completed .step-number { background: var(--success); }
.step-content { flex: 1; }
.step-content strong { color: var(--text-dark); font-size: 14px; }
</style>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const itemSelect = document.getElementById('itemSelect');
    const preview = document.getElementById('itemPreview');
    const previewName = document.getElementById('previewName');
    const previewCode = document.getElementById('previewCode');
    const previewStock = document.getElementById('previewStock');
    const quantityInput = document.getElementById('quantityInput');
    const stockHint = document.getElementById('stockHint');
    const decreaseBtn = document.getElementById('decreaseBtn');
    const increaseBtn = document.getElementById('increaseBtn');
    let maxStock = 0;

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
                        <small class="text-muted">${escape(code)} | Total: ${escape(stock)}</small>
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
            maxStock = stock;
            preview.style.display = 'block';
            previewName.textContent = selected.text.split(' (')[0];
            previewCode.textContent = selected.dataset.code;
            previewStock.innerHTML = '<span class="badge bg-info">Total: ' + stock + ' unit</span>';
            stockHint.textContent = 'Maksimal permintaan: ' + stock + ' unit';
            quantityInput.max = stock;
        } else {
            preview.style.display = 'none';
            stockHint.textContent = 'Pilih barang terlebih dahulu';
            maxStock = 0;
        }
    }

    decreaseBtn.addEventListener('click', () => { 
        let val = parseInt(quantityInput.value) || 0;
        if (val > 1) quantityInput.value = val - 1;
    });
    increaseBtn.addEventListener('click', () => { 
        let val = parseInt(quantityInput.value) || 0;
        if (maxStock === 0 || val < maxStock) quantityInput.value = val + 1;
    });
    quantityInput.addEventListener('input', function() {
        let val = parseInt(this.value) || 0;
        if (maxStock > 0 && val > maxStock) this.value = maxStock;
        if (val < 1 && this.value !== '') this.value = 1;
    });

    <?php if ($preSelectedItem && $preSelectedDetails): ?>
    const preSelected = itemSelect.querySelector('option[value="<?= $preSelectedItem ?>"]');
    if (preSelected) updatePreview(preSelected);
    <?php endif; ?>
});
</script>
