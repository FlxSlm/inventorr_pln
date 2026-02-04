<?php
// app/admin/inventory_edit_new.php - Modern Edit Inventory Page
$pdo = require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/image_helper.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM inventories WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();
if (!$item) { 
    echo "<div class='alert alert-danger'>Item tidak ditemukan.</div>"; 
    exit; 
}

// Fetch all categories
$categories = $pdo->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();

// Fetch item's current categories
$itemCatStmt = $pdo->prepare('SELECT category_id FROM inventory_categories WHERE inventory_id = ?');
$itemCatStmt->execute([$id]);
$itemCategories = array_column($itemCatStmt->fetchAll(), 'category_id');

// Fetch item's images from inventory_images table
$imgStmt = $pdo->prepare('SELECT * FROM inventory_images WHERE inventory_id = ? ORDER BY is_primary DESC, sort_order ASC');
$imgStmt->execute([$id]);
$itemImages = $imgStmt->fetchAll();

$errors = [];
$success = '';

// Handle image delete
if (isset($_GET['delete_image'])) {
    $uploadDir = 'C:/XAMPP/htdocs/inventory_pln/public/assets/uploads/';
    
    if ($_GET['delete_image'] === 'all' || $_GET['delete_image'] == 1) {
        // Delete all images
        if ($item['image'] && file_exists($uploadDir . $item['image'])) {
            unlink($uploadDir . $item['image']);
        }
        // Delete from inventory_images table and files
        $imgStmt = $pdo->prepare('SELECT image_path FROM inventory_images WHERE inventory_id = ?');
        $imgStmt->execute([$id]);
        while ($img = $imgStmt->fetch()) {
            if (file_exists($uploadDir . $img['image_path'])) {
                @unlink($uploadDir . $img['image_path']);
            }
        }
        $pdo->prepare('DELETE FROM inventory_images WHERE inventory_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE inventories SET image = NULL, updated_at = NOW() WHERE id = ?')->execute([$id]);
    } else {
        // Delete specific image by ID
        $imgId = (int)$_GET['delete_image'];
        $imgStmt = $pdo->prepare('SELECT * FROM inventory_images WHERE id = ? AND inventory_id = ?');
        $imgStmt->execute([$imgId, $id]);
        $imgToDelete = $imgStmt->fetch();
        
        if ($imgToDelete) {
            if (file_exists($uploadDir . $imgToDelete['image_path'])) {
                @unlink($uploadDir . $imgToDelete['image_path']);
            }
            $pdo->prepare('DELETE FROM inventory_images WHERE id = ?')->execute([$imgId]);
            
            // If deleted image was primary, set another as primary
            if ($imgToDelete['is_primary']) {
                // Also update main inventories.image column
                $nextPrimary = $pdo->prepare('SELECT * FROM inventory_images WHERE inventory_id = ? ORDER BY sort_order ASC LIMIT 1');
                $nextPrimary->execute([$id]);
                $nextImg = $nextPrimary->fetch();
                
                if ($nextImg) {
                    $pdo->prepare('UPDATE inventory_images SET is_primary = 1 WHERE id = ?')->execute([$nextImg['id']]);
                    $pdo->prepare('UPDATE inventories SET image = ?, updated_at = NOW() WHERE id = ?')->execute([$nextImg['image_path'], $id]);
                } else {
                    $pdo->prepare('UPDATE inventories SET image = NULL, updated_at = NOW() WHERE id = ?')->execute([$id]);
                }
            } elseif ($item['image'] === $imgToDelete['image_path']) {
                // If the deleted image was the main one, update it
                $nextPrimary = $pdo->prepare('SELECT image_path FROM inventory_images WHERE inventory_id = ? AND is_primary = 1 LIMIT 1');
                $nextPrimary->execute([$id]);
                $nextImg = $nextPrimary->fetch();
                $pdo->prepare('UPDATE inventories SET image = ?, updated_at = NOW() WHERE id = ?')->execute([$nextImg ? $nextImg['image_path'] : null, $id]);
            }
        }
    }
    
    $redirectUrl = '/index.php?page=admin_inventory_edit&id=' . $id . '&msg=image_deleted';
    if (!headers_sent()) {
        header('Location: ' . $redirectUrl);
        exit;
    } else {
        echo '<script>window.location.href="' . htmlspecialchars($redirectUrl, ENT_QUOTES) . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl, ENT_QUOTES) . '"></noscript>';
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $item_type = trim($_POST['item_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $stock_total = (int)($_POST['stock_total'] ?? 0);
    $stock_available = (int)($_POST['stock_available'] ?? 0);
    $unit = trim($_POST['unit'] ?? '');
    $year_acquired = trim($_POST['year_acquired'] ?? '');
    $year_manufactured = trim($_POST['year_manufactured'] ?? '');
    $low_stock_threshold = (int)($_POST['low_stock_threshold'] ?? 5);
    $item_condition = trim($_POST['item_condition'] ?? 'Baik');
    $location = trim($_POST['location'] ?? '');
    $rack = trim($_POST['rack'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $selectedCategories = $_POST['categories'] ?? [];
    
    // Validate item_condition
    $validConditions = ['Baik', 'Rusak Ringan', 'Rusak Berat'];
    if (!in_array($item_condition, $validConditions)) {
        $item_condition = 'Baik';
    }
    
    // Validate year_acquired (now required)
    if (empty($year_acquired)) {
        $errors[] = 'Tahun perolehan wajib diisi.';
    } elseif (!preg_match('/^\d{4}$/', $year_acquired) || (int)$year_acquired < 1900 || (int)$year_acquired > (int)date('Y') + 1) {
        $errors[] = 'Tahun perolehan tidak valid.';
    }
    
    // Validate year_manufactured if provided
    if (!empty($year_manufactured)) {
        if (!preg_match('/^\d{4}$/', $year_manufactured) || (int)$year_manufactured < 1900 || (int)$year_manufactured > (int)date('Y') + 1) {
            $errors[] = 'Tahun pembuatan tidak valid.';
        }
    }
    
    // Handle multiple image upload
    $imageName = $item['image'];
    $uploadDir = 'C:/XAMPP/htdocs/inventory_pln/public/assets/uploads/';
    $uploadedImages = [];
    
    // Handle multiple images upload
    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileCount = count($_FILES['images']['name']);
        $currentMaxSort = $pdo->prepare('SELECT MAX(sort_order) as max_sort FROM inventory_images WHERE inventory_id = ?');
        $currentMaxSort->execute([$id]);
        $sortStart = ($currentMaxSort->fetch()['max_sort'] ?? -1) + 1;
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $_FILES['images']['name'][$i],
                    'type' => $_FILES['images']['type'][$i],
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'error' => $_FILES['images']['error'][$i],
                    'size' => $_FILES['images']['size'][$i]
                ];
                
                $result = processUploadedImage($file, $uploadDir, 'inv');
                
                if ($result['success']) {
                    $uploadedImages[] = [
                        'filename' => $result['filename'],
                        'sort_order' => $sortStart + $i
                    ];
                } else {
                    $errors[] = "Gambar " . ($i + 1) . ": " . $result['error'];
                }
            }
        }
    }
    
    // Backward compatibility: single image upload
    if (empty($uploadedImages) && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $result = processUploadedImage($_FILES['image'], $uploadDir, 'inv');
        if ($result['success']) {
            $uploadedImages[] = [
                'filename' => $result['filename'],
                'sort_order' => 0
            ];
        } else {
            $errors[] = $result['error'];
        }
    }
    
    if (empty($errors)) {
        // Save new images to inventory_images table
        if (!empty($uploadedImages)) {
            // Check if there are existing images
            $existingCount = $pdo->prepare('SELECT COUNT(*) FROM inventory_images WHERE inventory_id = ?');
            $existingCount->execute([$id]);
            $hasExisting = $existingCount->fetchColumn() > 0;
            
            $imgStmt = $pdo->prepare('INSERT INTO inventory_images (inventory_id, image_path, is_primary, sort_order) VALUES (?, ?, ?, ?)');
            foreach ($uploadedImages as $idx => $img) {
                $isPrimary = (!$hasExisting && $idx === 0) ? 1 : 0;
                $imgStmt->execute([$id, $img['filename'], $isPrimary, $img['sort_order']]);
            }
            
            // Update main image if no images existed before
            if (!$hasExisting) {
                $imageName = $uploadedImages[0]['filename'];
            }
        }
        
        $stmt = $pdo->prepare('UPDATE inventories SET name=?, code=?, item_type=?, description=?, stock_total=?, stock_available=?, unit=?, year_acquired=?, year_manufactured=?, low_stock_threshold=?, item_condition=?, location=?, rack=?, notes=?, image=?, updated_at=NOW() WHERE id=?');
        $stmt->execute([$name, $code ?: null, $item_type ?: null, $description, $stock_total, $stock_available, $unit, $year_acquired ?: null, $year_manufactured ?: null, $low_stock_threshold, $item_condition, $location ?: null, $rack ?: null, $notes ?: null, $imageName, $id]);
        
        $pdo->prepare('DELETE FROM inventory_categories WHERE inventory_id = ?')->execute([$id]);
        if (!empty($selectedCategories)) {
            $catStmt = $pdo->prepare('INSERT INTO inventory_categories (inventory_id, category_id) VALUES (?, ?)');
            foreach ($selectedCategories as $catId) {
                $catStmt->execute([$id, (int)$catId]);
            }
        }

        // Set success message and refresh item data so admin stays on edit page
        $success = 'Perubahan Telah Disimpan';

        // Reload item and its categories to reflect saved changes
        $stmt = $pdo->prepare('SELECT * FROM inventories WHERE id = ?');
        $stmt->execute([$id]);
        $item = $stmt->fetch();

        $itemCatStmt = $pdo->prepare('SELECT category_id FROM inventory_categories WHERE inventory_id = ?');
        $itemCatStmt->execute([$id]);
        $itemCategories = array_column($itemCatStmt->fetchAll(), 'category_id');
        
        // Reload images
        $imgStmt = $pdo->prepare('SELECT * FROM inventory_images WHERE inventory_id = ? ORDER BY is_primary DESC, sort_order ASC');
        $imgStmt->execute([$id]);
        $itemImages = $imgStmt->fetchAll();
    }
}

$imageDeleted = isset($_GET['msg']) && $_GET['msg'] === 'image_deleted';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">
            <i class="bi bi-pencil-square me-2"></i>Edit Barang
        </h1>
        <p class="text-muted mb-0">Perbarui informasi item inventaris</p>
    </div>
    <a href="/index.php?page=admin_inventory_list" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Kembali
    </a>
</div>

<?php if ($imageDeleted): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>Gambar berhasil dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php foreach($errors as $e): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<form method="POST" enctype="multipart/form-data">
    <div class="row">
        <!-- Main Form -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-info-circle me-2"></i>Informasi Barang
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-tag me-1"></i>Nama Barang <span class="text-danger">*</span>
                            </label>
                            <input name="name" class="form-control" required 
                                   value="<?= htmlspecialchars($item['name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-upc-scan me-1"></i>Nomor Seri
                            </label>
                            <input name="code" class="form-control" 
                                   value="<?= htmlspecialchars($item['code'] ?? '') ?>" placeholder="Opsional">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-diagram-3 me-1"></i>Merek/Tipe Barang
                            </label>
                            <input name="item_type" class="form-control" 
                                   value="<?= htmlspecialchars($item['item_type'] ?? '') ?>" 
                                   placeholder="Contoh: Elektronik, Furniture, dll (opsional)">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-card-text me-1"></i>Deskripsi
                            </label>
                            <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($item['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stock Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-boxes me-2"></i>Informasi Stok
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-box me-1"></i>Stok Total
                            </label>
                            <input type="number" name="stock_total" class="form-control" 
                                   value="<?= $item['stock_total'] ?>" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-box-seam me-1"></i>Stok Tersedia
                            </label>
                            <input type="number" name="stock_available" class="form-control" 
                                   value="<?= $item['stock_available'] ?>" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-rulers me-1"></i>Satuan
                            </label>
                            <input name="unit" class="form-control" 
                                   value="<?= htmlspecialchars($item['unit'] ?? '') ?>" 
                                   placeholder="unit, pcs, kg, dll">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-calendar me-1"></i>Tahun Perolehan <span class="text-danger">*</span>
                            </label>
                            <input type="number" name="year_acquired" class="form-control" 
                                   value="<?= htmlspecialchars($item['year_acquired'] ?? '') ?>" 
                                   placeholder="Contoh: 2024" min="1900" max="<?= date('Y') + 1 ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-wrench me-1"></i>Tahun Pembuatan
                            </label>
                            <input type="number" name="year_manufactured" class="form-control" 
                                   value="<?= htmlspecialchars($item['year_manufactured'] ?? '') ?>" 
                                   placeholder="Contoh: 2023" min="1900" max="<?= date('Y') + 1 ?>">
                            <small class="text-muted">Tahun barang diproduksi (opsional)</small>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-exclamation-triangle me-1"></i>Batas Stok Menipis
                            </label>
                            <input type="number" name="low_stock_threshold" class="form-control" 
                                   value="<?= htmlspecialchars($item['low_stock_threshold'] ?? 5) ?>" 
                                   min="0">
                            <small class="text-muted">Notifikasi muncul jika stok &le; nilai ini</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-heart-pulse me-1"></i>Kondisi Barang
                            </label>
                            <select name="item_condition" class="form-select">
                                <option value="Baik" <?= ($item['item_condition'] ?? 'Baik') === 'Baik' ? 'selected' : '' ?>>Baik</option>
                                <option value="Rusak Ringan" <?= ($item['item_condition'] ?? '') === 'Rusak Ringan' ? 'selected' : '' ?>>Rusak Ringan</option>
                                <option value="Rusak Berat" <?= ($item['item_condition'] ?? '') === 'Rusak Berat' ? 'selected' : '' ?>>Rusak Berat</option>
                            </select>
                            <small class="text-muted">Kondisi fisik barang saat ini</small>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-geo-alt me-1"></i>Lokasi Barang
                            </label>
                            <input name="location" class="form-control" 
                                   value="<?= htmlspecialchars($item['location'] ?? '') ?>" 
                                   placeholder="Contoh: Gudang A (opsional)">
                            <small class="text-muted">Lokasi penyimpanan barang</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-grid-3x3-gap me-1"></i>Rak
                            </label>
                            <input name="rack" class="form-control" 
                                   value="<?= htmlspecialchars($item['rack'] ?? '') ?>" 
                                   placeholder="Contoh: Rak A1 (opsional)">
                            <small class="text-muted">Posisi rak</small>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-journal-text me-1"></i>Keterangan
                            </label>
                            <textarea name="notes" class="form-control" rows="2" 
                                      placeholder="Keterangan tambahan (opsional)"><?= htmlspecialchars($item['notes'] ?? '') ?></textarea>
                            <small class="text-muted">Catatan yang dapat dilihat oleh semua pengguna</small>
                        </div>
                    </div>
                    
                    <!-- Stock Indicator -->
                    <?php 
                    $stockPercent = $item['stock_total'] > 0 ? round(($item['stock_available'] / $item['stock_total']) * 100) : 0;
                    $stockColor = $stockPercent > 50 ? 'success' : ($stockPercent > 20 ? 'warning' : 'danger');
                    ?>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small">Ketersediaan Stok</span>
                            <span class="small fw-semibold"><?= $stockPercent ?>%</span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-<?= $stockColor ?>" style="width: <?= $stockPercent ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Categories -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-tags me-2"></i>Kategori
                    </h5>
                </div>
                <div class="card-body">
                    <?php if(empty($categories)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-tags text-muted" style="font-size: 2rem;"></i>
                        <p class="text-muted mt-2 mb-0">
                            Belum ada kategori. 
                            <a href="/index.php?page=admin_categories" class="text-primary">Buat kategori</a>
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="row g-2">
                        <?php foreach($categories as $cat): ?>
                        <div class="col-auto">
                            <input class="btn-check" type="checkbox" name="categories[]" 
                                   value="<?= $cat['id'] ?>" id="cat<?= $cat['id'] ?>"
                                   <?= in_array($cat['id'], $itemCategories) ? 'checked' : '' ?>>
                            <label class="btn btn-outline-secondary category-btn" for="cat<?= $cat['id'] ?>">
                                <span class="category-dot" style="background: <?= htmlspecialchars($cat['color']) ?>;"></span>
                                <?= htmlspecialchars($cat['name']) ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Image Upload -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-images me-2"></i>Foto Barang
                    </h5>
                    <?php if (!empty($itemImages) || $item['image']): ?>
                    <a href="/index.php?page=admin_inventory_edit&id=<?= $id ?>&delete_image=all" 
                       class="btn btn-sm btn-outline-danger"
                       onclick="return confirm('Hapus semua foto?')">
                        <i class="bi bi-trash me-1"></i>Hapus Semua
                    </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($itemImages)): ?>
                    <!-- Display all images with delete button -->
                    <div class="row g-2 mb-3" id="existingImages">
                        <?php foreach ($itemImages as $img): ?>
                        <div class="col-6">
                            <div class="existing-image-item <?= $img['is_primary'] ? 'primary' : '' ?>">
                                <img src="/public/assets/uploads/<?= htmlspecialchars($img['image_path']) ?>" 
                                     alt="<?= htmlspecialchars($item['name']) ?>">
                                <button type="button" class="btn btn-danger btn-remove-existing" 
                                        onclick="if(confirm('Hapus foto ini?')) window.location.href='/index.php?page=admin_inventory_edit&id=<?= $id ?>&delete_image=<?= $img['id'] ?>'">
                                    <i class="bi bi-x"></i>
                                </button>
                                <?php if ($img['is_primary']): ?>
                                <span class="primary-badge"><i class="bi bi-star-fill me-1"></i>Utama</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <label class="form-label small text-muted">Tambah foto lain:</label>
                    <?php elseif ($item['image']): ?>
                    <!-- Legacy single image display -->
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="existing-image-item primary">
                                <img src="/public/assets/uploads/<?= htmlspecialchars($item['image']) ?>" 
                                     alt="<?= htmlspecialchars($item['name']) ?>">
                                <button type="button" class="btn btn-danger btn-remove-existing" 
                                        onclick="if(confirm('Hapus foto ini?')) window.location.href='/index.php?page=admin_inventory_edit&id=<?= $id ?>&delete_image=1'">
                                    <i class="bi bi-x"></i>
                                </button>
                                <span class="primary-badge"><i class="bi bi-star-fill me-1"></i>Utama</span>
                            </div>
                        </div>
                    </div>
                    <label class="form-label small text-muted">Tambah foto lain:</label>
                    <?php else: ?>
                    <div class="image-upload-area mb-3" id="imageDropZone">
                        <i class="bi bi-cloud-upload"></i>
                        <p>Klik atau drag gambar</p>
                        <small>Dapat upload lebih dari satu gambar</small>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Multi-image upload input -->
                    <input type="file" name="images[]" class="form-control" accept="image/*" id="imageInput" multiple>
                    <small class="text-muted">JPG, PNG, GIF, WEBP (Max 5MB/gambar)</small>
                    
                    <!-- Preview container for new uploads -->
                    <div id="newImagePreview" class="row g-2 mt-2"></div>
                </div>
            </div>

            <!-- Item Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-clock-history me-2"></i>Info Item
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <small class="text-muted">Dibuat:</small>
                        <div class="fw-semibold"><?= date('d M Y H:i', strtotime($item['created_at'])) ?></div>
                    </div>
                    <?php if ($item['updated_at']): ?>
                    <div class="mb-0">
                        <small class="text-muted">Diperbarui:</small>
                        <div class="fw-semibold"><?= date('d M Y H:i', strtotime($item['updated_at'])) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Actions -->
            <div class="card">
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-check-lg me-2"></i>Simpan Perubahan
                        </button>
                        <a href="/index.php?page=admin_inventory_list" class="btn btn-outline-secondary">
                            <i class="bi bi-x-lg me-2"></i>Batal
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<style>
.category-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 20px;
}

.category-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
}

.btn-check:checked + .category-btn {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}

.current-image img {
    width: 100%;
    max-height: 250px;
    object-fit: cover;
}

.image-upload-area {
    border: 2px dashed var(--border-color);
    border-radius: 12px;
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: var(--bg-tertiary);
}

.image-upload-area:hover {
    border-color: var(--primary);
    background: rgba(var(--primary-rgb), 0.1);
}

.image-upload-area i {
    font-size: 3rem;
    color: var(--text-muted);
    display: block;
    margin-bottom: 0.5rem;
}

.image-upload-area p {
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
}

.image-upload-area small {
    color: var(--text-muted);
}

/* Multi-image styles */
.existing-image-item {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid transparent;
    transition: all 0.3s ease;
}
.existing-image-item.primary {
    border-color: var(--primary);
}
.existing-image-item img {
    width: 100%;
    height: 100px;
    object-fit: cover;
}
.existing-image-item .btn-remove-existing {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 24px;
    height: 24px;
    padding: 0;
    border-radius: 50%;
    font-size: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease;
}
.existing-image-item:hover .btn-remove-existing {
    opacity: 1;
}
.existing-image-item .primary-badge {
    position: absolute;
    bottom: 4px;
    left: 4px;
    background: var(--primary);
    color: white;
    font-size: 9px;
    padding: 2px 6px;
    border-radius: 4px;
}
.new-image-preview {
    position: relative;
}
.new-image-preview img {
    width: 100%;
    height: 80px;
    object-fit: cover;
    border-radius: 6px;
}
.new-image-preview .btn-remove-new {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 20px;
    height: 20px;
    padding: 0;
    border-radius: 50%;
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dropZone = document.getElementById('imageDropZone');
    const input = document.getElementById('imageInput');
    const previewContainer = document.getElementById('newImagePreview');
    let selectedFiles = [];
    
    // Click to select
    if (dropZone) {
        dropZone.addEventListener('click', () => input.click());
        
        // Drag & drop handlers
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(event => {
            dropZone.addEventListener(event, e => {
                e.preventDefault();
                e.stopPropagation();
            });
        });
        
        dropZone.addEventListener('dragenter', () => dropZone.style.borderColor = 'var(--primary)');
        dropZone.addEventListener('dragleave', () => dropZone.style.borderColor = 'var(--border-color)');
        dropZone.addEventListener('drop', e => {
            dropZone.style.borderColor = 'var(--border-color)';
            handleFiles(e.dataTransfer.files);
        });
    }
    
    if (input) {
        input.addEventListener('change', e => handleFiles(e.target.files));
    }
    
    function handleFiles(files) {
        Array.from(files).forEach(file => {
            if (file.type.startsWith('image/')) {
                if (file.size > 5 * 1024 * 1024) {
                    alert('File ' + file.name + ' terlalu besar (max 5MB)');
                    return;
                }
                selectedFiles.push(file);
            }
        });
        updatePreview();
        updateFileInput();
    }
    
    function updatePreview() {
        previewContainer.innerHTML = '';
        selectedFiles.forEach((file, index) => {
            const reader = new FileReader();
            reader.onload = function(e) {
                const col = document.createElement('div');
                col.className = 'col-4';
                col.innerHTML = `
                    <div class="new-image-preview">
                        <img src="${e.target.result}" alt="Preview">
                        <button type="button" class="btn btn-danger btn-remove-new" onclick="removeNewImage(${index})">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                `;
                previewContainer.appendChild(col);
            };
            reader.readAsDataURL(file);
        });
    }
    
    function updateFileInput() {
        const dt = new DataTransfer();
        selectedFiles.forEach(file => dt.items.add(file));
        input.files = dt.files;
    }
    
    window.removeNewImage = function(index) {
        selectedFiles.splice(index, 1);
        updatePreview();
        updateFileInput();
    };
});
</script>
