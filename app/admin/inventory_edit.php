<?php
$pdo = require __DIR__ . '/../config/database.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM inventories WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();
if (!$item) { echo "Item not found"; exit; }

// Fetch all categories
$categories = $pdo->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();

// Fetch item's current categories
$itemCatStmt = $pdo->prepare('SELECT category_id FROM inventory_categories WHERE inventory_id = ?');
$itemCatStmt->execute([$id]);
$itemCategories = array_column($itemCatStmt->fetchAll(), 'category_id');

$errors = [];
$success = '';

// Handle image delete
if (isset($_GET['delete_image']) && $_GET['delete_image'] == 1) {
    $uploadDir = 'C:/XAMPP/htdocs/inventory_pln/public/assets/uploads/';
    if ($item['image'] && file_exists($uploadDir . $item['image'])) {
        unlink($uploadDir . $item['image']);
    }
    $stmt = $pdo->prepare('UPDATE inventories SET image = NULL, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$id]);
    header('Location: /index.php?page=admin_inventory_edit&id=' . $id . '&msg=image_deleted');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $stock_total = (int)($_POST['stock_total'] ?? 0);
    $stock_available = (int)($_POST['stock_available'] ?? 0);
    $unit = trim($_POST['unit'] ?? '');
    $selectedCategories = $_POST['categories'] ?? [];
    
    // Handle image upload
    $imageName = $item['image']; // Keep existing image by default
    
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        // Use absolute path - adjusted for XAMPP structure
        $uploadDir = 'C:/XAMPP/htdocs/inventory_pln/public/assets/uploads/';
        
        // Create directory if not exists
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                $errors[] = 'Gagal membuat folder upload: ' . $uploadDir;
            }
        }
        
        if (empty($errors)) {
            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = mime_content_type($_FILES['image']['tmp_name']);
            
            if (!in_array($fileType, $allowedTypes)) {
                $errors[] = 'Format gambar tidak valid. Gunakan JPG, PNG, GIF, atau WEBP.';
            } else {
                // Delete old image if exists
                if ($item['image'] && file_exists($uploadDir . $item['image'])) {
                    unlink($uploadDir . $item['image']);
                }
                
                // Generate unique filename
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $imageName = 'inv_' . $id . '_' . time() . '.' . $ext;
                
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $imageName)) {
                    $errors[] = 'Gagal mengupload gambar ke: ' . $uploadDir . $imageName;
                    $imageName = $item['image']; // Keep old image
                } else {
                    // Success - file uploaded
                }
            }
        }
    }
    
    if (empty($errors)) {
        $stmt = $pdo->prepare('UPDATE inventories SET name=?, code=?, description=?, stock_total=?, stock_available=?, unit=?, image=?, updated_at=NOW() WHERE id=?');
        $stmt->execute([$name, $code, $description, $stock_total, $stock_available, $unit, $imageName, $id]);
        
        // Update categories - delete old and insert new
        $pdo->prepare('DELETE FROM inventory_categories WHERE inventory_id = ?')->execute([$id]);
        if (!empty($selectedCategories)) {
            $catStmt = $pdo->prepare('INSERT INTO inventory_categories (inventory_id, category_id) VALUES (?, ?)');
            foreach ($selectedCategories as $catId) {
                $catStmt->execute([$id, (int)$catId]);
            }
        }
        
        header('Location: /index.php?page=admin_inventory_list&msg=updated');
        exit;
    }
}

$imageDeleted = isset($_GET['msg']) && $_GET['msg'] === 'image_deleted';
?>

<div class="card">
    <div class="card-header">
        <h4 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Edit Barang</h4>
    </div>
    <div class="card-body">
        <?php if ($imageDeleted): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Gambar berhasil dihapus.</div>
        <?php endif; ?>
        
        <?php foreach($errors as $e): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="row">
                <div class="col-md-8">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-tag me-1"></i> Nama Barang</label>
                            <input name="name" class="form-control" value="<?= htmlspecialchars($item['name']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-upc-scan me-1"></i> Kode/Serial</label>
                            <input name="code" class="form-control" value="<?= htmlspecialchars($item['code']) ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-card-text me-1"></i> Deskripsi</label>
                        <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($item['description'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><i class="bi bi-boxes me-1"></i> Stok Total</label>
                            <input type="number" name="stock_total" class="form-control" value="<?= $item['stock_total'] ?>" min="0" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><i class="bi bi-box-seam me-1"></i> Stok Tersedia</label>
                            <input type="number" name="stock_available" class="form-control" value="<?= $item['stock_available'] ?>" min="0" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><i class="bi bi-rulers me-1"></i> Satuan</label>
                            <input name="unit" class="form-control" value="<?= htmlspecialchars($item['unit'] ?? '') ?>" placeholder="unit, pcs, kg, dll">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-tags me-1"></i> Kategori</label>
                        <div class="row g-2">
                            <?php foreach($categories as $cat): ?>
                            <div class="col-auto">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="categories[]" value="<?= $cat['id'] ?>" id="cat<?= $cat['id'] ?>" <?= in_array($cat['id'], $itemCategories) ? 'checked' : '' ?>>
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
                
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-image me-1"></i> Foto Barang</label>
                        
                        <?php if ($item['image']): ?>
                            <div class="mb-3 text-center p-3" style="background: rgba(15, 117, 188, 0.1); border-radius: 10px;">
                                <img src="/public/assets/uploads/<?= htmlspecialchars($item['image']) ?>" 
                                     alt="<?= htmlspecialchars($item['name']) ?>" 
                                     class="img-fluid rounded mb-2" 
                                     style="max-height: 200px; object-fit: cover;">
                                <br>
                                <a href="/index.php?page=admin_inventory_edit&id=<?= $id ?>&delete_image=1" 
                                   class="btn btn-sm btn-danger mt-2"
                                   onclick="return confirm('Hapus foto ini?')">
                                    <i class="bi bi-trash me-1"></i> Hapus Foto
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="text-center p-4 mb-3" style="background: rgba(255,255,255,0.05); border: 2px dashed rgba(255,255,255,0.2); border-radius: 10px;">
                                <i class="bi bi-image text-secondary" style="font-size: 3rem;"></i>
                                <p class="text-secondary mt-2 mb-0">Belum ada foto</p>
                            </div>
                        <?php endif; ?>
                        
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <small class="text-secondary">Format: JPG, PNG, GIF, WEBP. Max 5MB</small>
                    </div>
                </div>
            </div>
            
            <hr class="my-4" style="border-color: rgba(255,255,255,0.1);">
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> Simpan Perubahan
                </button>
                <a href="/index.php?page=admin_inventory_list" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Kembali
                </a>
            </div>
        </form>
    </div>
</div>
