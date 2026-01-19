<?php
$pdo = require __DIR__ . '/../config/database.php';
$errors = [];

// Fetch categories for dropdown
$categories = $pdo->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $stock_total = (int)($_POST['stock_total'] ?? 0);
    $unit = trim($_POST['unit'] ?? '');
    $selectedCategories = $_POST['categories'] ?? [];
    
    // Validation
    if (empty($name)) $errors[] = 'Nama barang wajib diisi.';
    if (empty($code)) $errors[] = 'Kode barang wajib diisi.';
    
    // Check duplicate code
    $stmt = $pdo->prepare('SELECT id FROM inventories WHERE code = ? AND deleted_at IS NULL');
    $stmt->execute([$code]);
    if ($stmt->fetch()) {
        $errors[] = 'Kode barang sudah digunakan.';
    }
    
    // Handle image upload
    $imageName = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        // Use absolute path
        $uploadDir = 'C:/XAMPP/htdocs/inventory_pln/public/assets/uploads/';
        
        // Create directory if not exists
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                $errors[] = 'Gagal membuat folder upload.';
            }
        }
        
        if (empty($errors)) {
            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = mime_content_type($_FILES['image']['tmp_name']);
            
            if (!in_array($fileType, $allowedTypes)) {
                $errors[] = 'Format gambar tidak valid. Gunakan JPG, PNG, GIF, atau WEBP.';
            } else {
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $imageName = 'inv_' . uniqid() . '_' . time() . '.' . $ext;
                
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $imageName)) {
                    $errors[] = 'Gagal mengupload gambar.';
                    $imageName = null;
                }
            }
        }
    }
    
    if (empty($errors)) {
        $stmt = $pdo->prepare('INSERT INTO inventories (name, code, description, stock_total, stock_available, unit, image) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$name, $code, $description, $stock_total, $stock_total, $unit, $imageName]);
        $inventoryId = $pdo->lastInsertId();
        
        // Save categories
        if (!empty($selectedCategories)) {
            $catStmt = $pdo->prepare('INSERT INTO inventory_categories (inventory_id, category_id) VALUES (?, ?)');
            foreach ($selectedCategories as $catId) {
                $catStmt->execute([$inventoryId, (int)$catId]);
            }
        }
        
        header('Location: /index.php?page=admin_inventory_list&msg=added');
        exit;
    }
}
?>

<div class="card">
    <div class="card-header">
        <h4 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Tambah Barang Baru</h4>
    </div>
    <div class="card-body">
        <?php foreach($errors as $e): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="row">
                <div class="col-md-8">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-tag me-1"></i> Nama Barang <span class="text-danger">*</span></label>
                            <input name="name" class="form-control" required placeholder="Contoh: Laptop Dell Latitude">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-upc-scan me-1"></i> Kode/Serial <span class="text-danger">*</span></label>
                            <input name="code" class="form-control" required placeholder="Contoh: LPT-001">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-card-text me-1"></i> Deskripsi</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Deskripsi barang (opsional)"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-boxes me-1"></i> Jumlah Stok</label>
                            <input type="number" name="stock_total" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-rulers me-1"></i> Satuan</label>
                            <input name="unit" class="form-control" placeholder="unit, pcs, kg, dll">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-tags me-1"></i> Kategori</label>
                        <div class="row g-2">
                            <?php foreach($categories as $cat): ?>
                            <div class="col-auto">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="categories[]" value="<?= $cat['id'] ?>" id="cat<?= $cat['id'] ?>">
                                    <label class="form-check-label" for="cat<?= $cat['id'] ?>">
                                        <span class="badge" style="background: <?= htmlspecialchars($cat['color']) ?>;"><?= htmlspecialchars($cat['name']) ?></span>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if(empty($categories)): ?>
                            <div class="col-12">
                                <small class="text-muted">Belum ada kategori. <a href="/index.php?page=admin_categories">Buat kategori</a></small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-image me-1"></i> Foto Barang</label>
                        <div class="text-center p-4 mb-3" style="background: rgba(255,255,255,0.05); border: 2px dashed rgba(255,255,255,0.2); border-radius: 10px;" id="imagePreviewContainer">
                            <i class="bi bi-cloud-upload text-secondary" style="font-size: 3rem;"></i>
                            <p class="text-secondary mt-2 mb-0">Pilih gambar untuk diupload</p>
                        </div>
                        <input type="file" name="image" class="form-control" accept="image/*" id="imageInput">
                        <small class="text-secondary">Format: JPG, PNG, GIF, WEBP. Max 5MB</small>
                    </div>
                </div>
            </div>
            
            <hr class="my-4" style="border-color: rgba(255,255,255,0.1);">
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-check-lg me-1"></i> Simpan Barang
                </button>
                <a href="/index.php?page=admin_inventory_list" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Kembali
                </a>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('imageInput').addEventListener('change', function(e) {
    const container = document.getElementById('imagePreviewContainer');
    const file = e.target.files[0];
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            container.innerHTML = '<img src="' + e.target.result + '" alt="Preview" style="max-height: 200px; max-width: 100%; border-radius: 10px;">';
        };
        reader.readAsDataURL(file);
    }
});
</script>
