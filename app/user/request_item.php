<?php
// app/user/request_item.php - Request Item Page with Multi-Item Support
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
    // Multi-item support
    $items = $_POST['items'] ?? [];
    $note = trim($_POST['note'] ?? '');
    
    // Validate items
    if (empty($items) || !is_array($items)) {
        $errors[] = 'Pilih minimal satu barang untuk diminta.';
    } else {
        // Generate group_id for multi-item transactions
        $groupId = count($items) > 1 ? bin2hex(random_bytes(16)) : null;
        
        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $inventory_id = (int)($item['id'] ?? 0);
                $quantity = (int)($item['qty'] ?? 0);
                
                if ($inventory_id <= 0 || $quantity <= 0) {
                    throw new Exception('Data barang tidak valid.');
                }
                
                // Check inventory exists
                $stmt = $pdo->prepare('SELECT * FROM inventories WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
                $stmt->execute([$inventory_id]);
                $inv = $stmt->fetch();
                
                if (!$inv) {
                    throw new Exception('Barang "' . ($item['name'] ?? 'Unknown') . '" tidak ditemukan.');
                }
                if ($inv['stock_total'] < $quantity) {
                    throw new Exception('Stok "' . $inv['name'] . '" tidak cukup (total: ' . $inv['stock_total'] . ').');
                }
                
                // Insert request with group_id
                $stmt = $pdo->prepare('INSERT INTO requests (group_id, inventory_id, user_id, quantity, note, stage, status) VALUES (?, ?, ?, ?, ?, "pending", "pending")');
                $stmt->execute([$groupId, $inventory_id, $userId, $quantity, $note]);
            }
            
            $pdo->commit();
            $itemCount = count($items);
            $msg = $itemCount > 1 ? "Permintaan $itemCount barang berhasil diajukan" : 'Permintaan berhasil diajukan';
            
            $redirectUrl = '/index.php?page=user_request_history&msg=' . urlencode($msg);
            if (!headers_sent()) {
                header('Location: ' . $redirectUrl);
                exit;
            } else {
                echo '<script>window.location.href = ' . json_encode($redirectUrl) . ';</script>';
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
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
        <p class="text-muted mb-0">Tambahkan satu atau lebih barang untuk diminta sekaligus</p>
    </div>
    <a href="/index.php?page=catalog" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Kembali
    </a>
</div>

<div class="row">
    <div class="col-lg-8">
        <!-- Main Form Card -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-cart-plus me-2"></i>Keranjang Permintaan
                </h5>
                <span class="badge bg-primary" id="cartCount">0 barang</span>
            </div>
            <div class="card-body">
                <?php foreach($errors as $e): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endforeach; ?>

                <!-- Item Selection -->
                <div class="mb-4 p-3 rounded" style="background: var(--bg-main); border: 1px solid var(--border-color);">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-plus-circle me-2"></i>Tambah Barang</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Pilih Barang</label>
                            <select id="itemSelect" class="form-select">
                                <option value="">Ketik untuk mencari barang...</option>
                                <?php foreach($items as $it): ?>
                                <option value="<?= $it['id'] ?>" 
                                        data-stock="<?= $it['stock_total'] ?>"
                                        data-image="<?= htmlspecialchars($it['image'] ?? '') ?>"
                                        data-code="<?= htmlspecialchars($it['code'] ?? '') ?>"
                                        data-name="<?= htmlspecialchars($it['name']) ?>">
                                    <?= htmlspecialchars($it['name']) ?> (<?= htmlspecialchars($it['code']) ?>) - Stok: <?= $it['stock_total'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Jumlah</label>
                            <input type="number" id="qtyInput" class="form-control" value="1" min="1">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="button" id="addToCartBtn" class="btn btn-primary w-100" disabled>
                                <i class="bi bi-plus-lg me-1"></i>Tambah
                            </button>
                        </div>
                    </div>
                    <small class="text-muted mt-2 d-block" id="stockHint">Pilih barang untuk melihat stok tersedia</small>
                </div>

                <!-- Cart Items List -->
                <div id="cartContainer" style="display: none;">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-bag me-2"></i>Daftar Barang</h6>
                    <div id="cartItems" class="mb-4"></div>
                </div>

                <form method="POST" id="requestForm">
                    <div id="hiddenInputs"></div>

                    <!-- Note -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-chat-text me-1"></i>Catatan / Alasan Permintaan
                        </label>
                        <textarea name="note" class="form-control" rows="3" 
                                  placeholder="Jelaskan alasan atau keperluan permintaan barang (opsional)"></textarea>
                    </div>

                    <hr class="my-4">

                    <!-- Submit Buttons -->
                    <div class="d-flex gap-3">
                        <button type="submit" class="btn btn-primary btn-lg" id="submitBtn" disabled>
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
                    <i class="bi bi-info-circle me-2"></i>Proses Permintaan
                </h5>
            </div>
            <div class="card-body">
                <div class="process-timeline">
                    <div class="process-step">
                        <div class="step-icon active"><i class="bi bi-1-circle-fill"></i></div>
                        <div class="step-content">
                            <div class="fw-semibold">Ajukan Permintaan</div>
                            <small class="text-muted">Isi form dan kirim permintaan</small>
                        </div>
                    </div>
                    <div class="process-step">
                        <div class="step-icon"><i class="bi bi-2-circle"></i></div>
                        <div class="step-content">
                            <div class="fw-semibold">Validasi Admin</div>
                            <small class="text-muted">Admin mereview permintaan</small>
                        </div>
                    </div>
                    <div class="process-step">
                        <div class="step-icon"><i class="bi bi-3-circle"></i></div>
                        <div class="step-content">
                            <div class="fw-semibold">Upload Dokumen</div>
                            <small class="text-muted">Upload dokumen serah terima</small>
                        </div>
                    </div>
                    <div class="process-step">
                        <div class="step-icon"><i class="bi bi-4-circle"></i></div>
                        <div class="step-content">
                            <div class="fw-semibold">Persetujuan Final</div>
                            <small class="text-muted">Admin menyetujui permintaan</small>
                        </div>
                    </div>
                    <div class="process-step">
                        <div class="step-icon success"><i class="bi bi-check-circle"></i></div>
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
                    <li class="mb-2">Permintaan bersifat <strong>permanen</strong> (barang tidak dikembalikan).</li>
                    <li class="mb-2">Stok barang akan berkurang setelah disetujui.</li>
                    <li class="mb-2">Pastikan jumlah sesuai kebutuhan.</li>
                    <li class="mb-2">Sertakan alasan yang jelas untuk mempercepat approval.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style>
.process-timeline { position: relative; }
.process-step { display: flex; align-items: flex-start; margin-bottom: 1.25rem; position: relative; }
.process-step:last-child { margin-bottom: 0; }
.process-step:not(:last-child)::after { content: ''; position: absolute; left: 11px; top: 28px; width: 2px; height: calc(100% - 8px); background: var(--border-color); }
.step-icon { width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem; color: var(--text-muted); flex-shrink: 0; }
.step-icon i { font-size: 1.25rem; }
.step-icon.active { color: var(--primary); }
.step-icon.success { color: var(--success); }
.step-content { flex: 1; }

/* Cart Item Styles */
.cart-item { display: flex; align-items: center; padding: 12px 16px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius); margin-bottom: 10px; transition: var(--transition); }
.cart-item:hover { border-color: var(--primary-light); box-shadow: var(--shadow-sm); }
.cart-item-img { width: 50px; height: 50px; border-radius: var(--radius-sm); object-fit: cover; margin-right: 12px; }
.cart-item-placeholder { width: 50px; height: 50px; border-radius: var(--radius-sm); background: var(--bg-main); display: flex; align-items: center; justify-content: center; margin-right: 12px; color: var(--text-muted); }
.cart-item-info { flex: 1; }
.cart-item-name { font-weight: 600; color: var(--text-dark); }
.cart-item-code { font-size: 12px; color: var(--text-muted); }
.cart-item-qty { display: flex; align-items: center; gap: 8px; margin-right: 16px; }
.cart-item-qty input { width: 60px; text-align: center; font-weight: 600; }
.cart-item-remove { color: var(--danger); cursor: pointer; padding: 4px 8px; border-radius: var(--radius-sm); transition: var(--transition); }
.cart-item-remove:hover { background: var(--danger-light); }
</style>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const itemSelect = document.getElementById('itemSelect');
    const qtyInput = document.getElementById('qtyInput');
    const addToCartBtn = document.getElementById('addToCartBtn');
    const cartContainer = document.getElementById('cartContainer');
    const cartItems = document.getElementById('cartItems');
    const cartCount = document.getElementById('cartCount');
    const hiddenInputs = document.getElementById('hiddenInputs');
    const submitBtn = document.getElementById('submitBtn');
    const stockHint = document.getElementById('stockHint');
    
    let cart = [];
    let selectedItem = null;
    let maxStock = 0;

    // Initialize Tom Select
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
            if (!value) {
                selectedItem = null;
                addToCartBtn.disabled = true;
                stockHint.textContent = 'Pilih barang untuk melihat stok tersedia';
                return;
            }
            const option = itemSelect.querySelector(`option[value="${value}"]`);
            if (option) {
                selectedItem = {
                    id: value,
                    name: option.dataset.name,
                    code: option.dataset.code,
                    image: option.dataset.image,
                    stock: parseInt(option.dataset.stock)
                };
                maxStock = selectedItem.stock;
                
                const inCart = cart.find(c => c.id === value);
                const usedQty = inCart ? inCart.qty : 0;
                const available = maxStock - usedQty;
                
                stockHint.textContent = `Stok tersedia: ${available} unit${usedQty > 0 ? ` (${usedQty} sudah di keranjang)` : ''}`;
                qtyInput.max = available;
                qtyInput.value = Math.min(parseInt(qtyInput.value) || 1, available);
                addToCartBtn.disabled = available <= 0;
            }
        }
    });

    addToCartBtn.addEventListener('click', function() {
        if (!selectedItem) return;
        const qty = parseInt(qtyInput.value) || 0;
        if (qty <= 0) return;
        
        const existingIdx = cart.findIndex(c => c.id === selectedItem.id);
        if (existingIdx >= 0) {
            cart[existingIdx].qty += qty;
        } else {
            cart.push({ id: selectedItem.id, name: selectedItem.name, code: selectedItem.code, image: selectedItem.image, qty: qty, maxStock: selectedItem.stock });
        }
        
        renderCart();
        tomSelect.clear();
        qtyInput.value = 1;
        selectedItem = null;
        addToCartBtn.disabled = true;
        stockHint.textContent = 'Pilih barang untuk melihat stok tersedia';
    });

    function renderCart() {
        if (cart.length === 0) {
            cartContainer.style.display = 'none';
            submitBtn.disabled = true;
            cartCount.textContent = '0 barang';
            hiddenInputs.innerHTML = '';
            return;
        }
        
        cartContainer.style.display = 'block';
        submitBtn.disabled = false;
        cartCount.textContent = `${cart.length} barang`;
        
        cartItems.innerHTML = cart.map((item, idx) => `
            <div class="cart-item" data-idx="${idx}">
                ${item.image ? `<img src="/public/assets/uploads/${item.image}" class="cart-item-img" alt="">` : `<div class="cart-item-placeholder"><i class="bi bi-box-seam"></i></div>`}
                <div class="cart-item-info">
                    <div class="cart-item-name">${escapeHtml(item.name)}</div>
                    <div class="cart-item-code">${escapeHtml(item.code)}</div>
                </div>
                <div class="cart-item-qty">
                    <input type="number" class="form-control form-control-sm qty-edit" value="${item.qty}" min="1" max="${item.maxStock}" data-idx="${idx}">
                    <span class="text-muted">unit</span>
                </div>
                <div class="cart-item-remove" data-idx="${idx}" title="Hapus"><i class="bi bi-trash"></i></div>
            </div>
        `).join('');
        
        hiddenInputs.innerHTML = cart.map((item, idx) => `
            <input type="hidden" name="items[${idx}][id]" value="${item.id}">
            <input type="hidden" name="items[${idx}][name]" value="${escapeHtml(item.name)}">
            <input type="hidden" name="items[${idx}][qty]" value="${item.qty}">
        `).join('');
        
        document.querySelectorAll('.qty-edit').forEach(input => {
            input.addEventListener('change', function() {
                const idx = parseInt(this.dataset.idx);
                const val = parseInt(this.value) || 1;
                cart[idx].qty = Math.min(Math.max(val, 1), cart[idx].maxStock);
                renderCart();
            });
        });
        
        document.querySelectorAll('.cart-item-remove').forEach(btn => {
            btn.addEventListener('click', function() {
                const idx = parseInt(this.dataset.idx);
                cart.splice(idx, 1);
                renderCart();
            });
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    <?php if ($preSelectedItem && $preSelectedDetails): ?>
    setTimeout(() => { tomSelect.setValue('<?= $preSelectedItem ?>'); }, 100);
    <?php endif; ?>
});
</script>
