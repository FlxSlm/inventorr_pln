<?php
// app/admin/inventory_edit_new.php - Modern Edit Inventory Page
$pdo = require __DIR__ . '/../config/database.php';
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
    $description = trim($_POST['description'] ?? '');
    $stock_total = (int)($_POST['stock_total'] ?? 0);
    $stock_available = (int)($_POST['stock_available'] ?? 0);
    $unit = trim($_POST['unit'] ?? '');
    $selectedCategories = $_POST['categories'] ?? [];
    
    // Handle image upload
    $imageName = $item['image'];
    
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'C:/XAMPP/htdocs/inventory_pln/public/assets/uploads/';
        
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                $errors[] = 'Gagal membuat folder upload.';
            }
        }
        
        if (empty($errors)) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = mime_content_type($_FILES['image']['tmp_name']);
            
            if (!in_array($fileType, $allowedTypes)) {
                $errors[] = 'Format gambar tidak valid. Gunakan JPG, PNG, GIF, atau WEBP.';
            } else {
                if ($item['image'] && file_exists($uploadDir . $item['image'])) {
                    unlink($uploadDir . $item['image']);
                }
                
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $imageName = 'inv_' . $id . '_' . time() . '.' . $ext;
                
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $imageName)) {
                    $errors[] = 'Gagal mengupload gambar.';
                    $imageName = $item['image'];
                }
            }
        }
    }
    
    if (empty($errors)) {
        $stmt = $pdo->prepare('UPDATE inventories SET name=?, code=?, description=?, stock_total=?, stock_available=?, unit=?, image=?, updated_at=NOW() WHERE id=?');
        $stmt->execute([$name, $code, $description, $stock_total, $stock_available, $unit, $imageName, $id]);
        
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
                                <i class="bi bi-upc-scan me-1"></i>Kode/Serial <span class="text-danger">*</span>
                            </label>
                            <input name="code" class="form-control" required 
                                   value="<?= htmlspecialchars($item['code']) ?>">
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
                        <i class="bi bi-image me-2"></i>Foto Barang
                    </h5>
                    <?php if ($item['image']): ?>
                    <a href="/index.php?page=admin_inventory_edit&id=<?= $id ?>&delete_image=1" 
                       class="btn btn-sm btn-outline-danger"
                       onclick="return confirm('Hapus foto ini?')">
                        <i class="bi bi-trash"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($item['image']): ?>
                    <div class="current-image mb-3">
                        <img src="/public/assets/uploads/<?= htmlspecialchars($item['image']) ?>" 
                             alt="<?= htmlspecialchars($item['name']) ?>" 
                             class="img-fluid rounded">
                    </div>
                    <label class="form-label small text-muted">Ganti foto:</label>
                    <?php else: ?>
                    <div class="image-upload-area mb-3" id="imagePreviewContainer">
                        <i class="bi bi-cloud-upload"></i>
                        <p>Klik atau drag gambar</p>
                        <small>JPG, PNG, GIF, WEBP (Max 5MB)</small>
                    </div>
                    <?php endif; ?>
                    <input type="file" name="image" class="form-control" accept="image/*" id="imageInput">
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
                        <small class="text-muted">ID:</small>
                        <div class="fw-semibold">#<?= $item['id'] ?></div>
                    </div>
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
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('imagePreviewContainer');
    const input = document.getElementById('imageInput');
    
    if (container) {
        // Click to select file
        container.addEventListener('click', () => input.click());
        
        // Drag & Drop functionality
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            container.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        // Highlight on drag
        ['dragenter', 'dragover'].forEach(eventName => {
            container.addEventListener(eventName, () => {
                container.style.borderColor = 'var(--primary)';
                container.style.background = 'rgba(26, 154, 170, 0.1)';
            });
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            container.addEventListener(eventName, () => {
                container.style.borderColor = 'var(--border-color)';
                container.style.background = 'var(--bg-tertiary)';
            });
        });
        
        // Handle dropped files
        container.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            if (files.length > 0) {
                const file = files[0];
                if (file.type.startsWith('image/')) {
                    // Transfer file to input
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    input.files = dataTransfer.files;
                    
                    // Preview
                    previewImage(file);
                } else {
                    alert('Mohon pilih file gambar (JPG, PNG, GIF, WEBP)');
                }
            }
        });
    }
    
    // Preview on file select
    if (input) {
        input.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                previewImage(this.files[0]);
            }
        });
    }
    
    function previewImage(file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            if (container) {
                container.innerHTML = `
                    <img src="${e.target.result}" style="max-width: 100%; max-height: 200px; border-radius: 8px; object-fit: cover;">
                    <p style="margin-top: 10px; color: var(--text-muted); font-size: 13px;">
                        <i class="bi bi-check-circle text-success me-1"></i>${file.name}
                    </p>
                `;
            }
        };
        reader.readAsDataURL(file);
    }
});
</script>
