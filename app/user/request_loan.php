<?php
// app/user/request_loan.php
if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}
// prevent blacklisted users (double check)
if (!empty($_SESSION['user']['is_blacklisted'])) {
    echo "<div class='alert alert-danger'>Akun Anda diblokir. Hubungi admin.</div>";
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
        // check inventory exist
        $stmt = $pdo->prepare('SELECT * FROM inventories WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$inventory_id]);
        $inv = $stmt->fetch();
        if (!$inv) $errors[] = 'Barang tidak ditemukan.';
        elseif ($inv['stock_available'] < $quantity) $errors[] = 'Stok tidak cukup (tersedia: ' . $inv['stock_available'] . ').';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare('INSERT INTO loans (inventory_id, user_id, quantity, note) VALUES (?, ?, ?, ?)');
        $stmt->execute([$inventory_id, $userId, $quantity, $note]);
        header('Location: /index.php?page=history&msg=Request+submitted');
        exit;
    }
}

// fetch inventories for select
$stmt = $pdo->query('SELECT id, name, code, stock_available, image FROM inventories WHERE deleted_at IS NULL AND stock_available > 0 ORDER BY name ASC');
$items = $stmt->fetchAll();
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h4 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Ajukan Peminjaman Barang</h4>
            </div>
            <div class="card-body">
                <?php foreach($errors as $e): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
                    </div>
                <?php endforeach; ?>

                <form method="POST">
                    <div class="mb-4">
                        <label class="form-label"><i class="bi bi-box-seam me-1"></i> Pilih Barang</label>
                        <select name="inventory_id" class="form-select" required id="itemSelect">
                            <option value="">-- Pilih Barang --</option>
                            <?php foreach($items as $it): ?>
                                <option value="<?= $it['id'] ?>" 
                                        data-stock="<?= $it['stock_available'] ?>"
                                        data-image="<?= htmlspecialchars($it['image'] ?? '') ?>"
                                        <?= $preSelectedItem == $it['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($it['name']) ?> (<?= htmlspecialchars($it['code']) ?>) - Tersedia: <?= $it['stock_available'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Item Preview -->
                    <div id="itemPreview" class="mb-4" style="display: none;">
                        <div class="d-flex align-items-center p-3" style="background: rgba(15, 117, 188, 0.1); border-radius: 10px;">
                            <img id="previewImage" src="" alt="" class="me-3" style="width: 80px; height: 80px; object-fit: cover; border-radius: 10px; display: none;">
                            <div id="previewPlaceholder" class="me-3" style="width: 80px; height: 80px; background: rgba(255,255,255,0.1); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-box-seam text-secondary" style="font-size: 2rem;"></i>
                            </div>
                            <div>
                                <div class="text-secondary small">Barang yang dipilih:</div>
                                <div id="previewName" class="fw-bold text-pln-yellow"></div>
                                <div id="previewStock" class="small text-secondary"></div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label"><i class="bi bi-123 me-1"></i> Jumlah yang Dipinjam</label>
                        <input type="number" name="quantity" class="form-control" min="1" required id="quantityInput" placeholder="Masukkan jumlah">
                        <small class="text-secondary" id="stockHint"></small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label"><i class="bi bi-chat-text me-1"></i> Catatan / Alasan Peminjaman</label>
                        <textarea name="note" class="form-control" rows="3" placeholder="Jelaskan alasan atau keperluan peminjaman barang ini (opsional, tapi disarankan untuk mempercepat approval)"></textarea>
                        <small class="text-secondary">Catatan ini akan dibaca oleh admin saat mereview permintaan Anda.</small>
                    </div>

                    <hr class="my-4" style="border-color: rgba(255,255,255,0.1);">

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-send me-1"></i> Kirim Permintaan
                        </button>
                        <a href="/index.php?page=catalog" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i> Kembali ke Katalog
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Info Box -->
        <div class="card mt-4">
            <div class="card-body">
                <h6 class="text-pln-yellow mb-3"><i class="bi bi-info-circle me-2"></i>Informasi Peminjaman</h6>
                <ul class="mb-0 text-secondary small">
                    <li>Permintaan peminjaman akan direview oleh admin.</li>
                    <li>Anda akan mendapat notifikasi setelah permintaan disetujui atau ditolak.</li>
                    <li>Pastikan barang dikembalikan tepat waktu dan dalam kondisi baik.</li>
                    <li>Hubungi admin jika ada pertanyaan terkait peminjaman.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('itemSelect').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const preview = document.getElementById('itemPreview');
    const previewImage = document.getElementById('previewImage');
    const previewPlaceholder = document.getElementById('previewPlaceholder');
    const previewName = document.getElementById('previewName');
    const previewStock = document.getElementById('previewStock');
    const stockHint = document.getElementById('stockHint');
    const quantityInput = document.getElementById('quantityInput');
    
    if (this.value) {
        const stock = selected.dataset.stock;
        const image = selected.dataset.image;
        const name = selected.text.split(' (')[0];
        
        preview.style.display = 'block';
        previewName.textContent = name;
        previewStock.textContent = 'Stok tersedia: ' + stock + ' unit';
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
        stockHint.textContent = '';
        quantityInput.removeAttribute('max');
    }
});

// Trigger change on page load if pre-selected
if (document.getElementById('itemSelect').value) {
    document.getElementById('itemSelect').dispatchEvent(new Event('change'));
}
</script>
