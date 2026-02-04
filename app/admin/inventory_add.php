<?php
$pdo = require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/image_helper.php';
$errors = [];

// Fetch categories for dropdown
$categories = $pdo->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $item_type = trim($_POST['item_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $stock_total = (int)($_POST['stock_total'] ?? 0);
    $unit = trim($_POST['unit'] ?? '');
    $year_acquired = trim($_POST['year_acquired'] ?? '');
    $year_manufactured = trim($_POST['year_manufactured'] ?? '');
    $low_stock_threshold = (int)($_POST['low_stock_threshold'] ?? 5);
    $item_condition = trim($_POST['item_condition'] ?? 'Baik');
    $location = trim($_POST['location'] ?? '');
    $rack = trim($_POST['rack'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $selectedCategories = $_POST['categories'] ?? [];
    $primaryImageIndex = (int)($_POST['primary_image'] ?? 0);
    
    // Validate item_condition
    $validConditions = ['Baik', 'Rusak Ringan', 'Rusak Berat'];
    if (!in_array($item_condition, $validConditions)) {
        $item_condition = 'Baik';
    }
    
    // Validation
    if (empty($name)) $errors[] = 'Nama barang wajib diisi.';
    if (empty($year_acquired)) $errors[] = 'Tahun perolehan wajib diisi.';
    
    // Require at least one category if categories exist
    if (!empty($categories) && empty($selectedCategories)) {
        $errors[] = 'Pilih minimal satu kategori untuk barang ini.';
    }
    
    // Validate year_acquired
    if (!empty($year_acquired)) {
        if (!preg_match('/^\d{4}$/', $year_acquired) || (int)$year_acquired < 1900 || (int)$year_acquired > (int)date('Y') + 1) {
            $errors[] = 'Tahun perolehan tidak valid.';
        }
    }
    
    // Validate year_manufactured if provided
    if (!empty($year_manufactured)) {
        if (!preg_match('/^\d{4}$/', $year_manufactured) || (int)$year_manufactured < 1900 || (int)$year_manufactured > (int)date('Y') + 1) {
            $errors[] = 'Tahun pembuatan tidak valid.';
        }
    }
    
    // Check duplicate code (only if code is provided)
    if (!empty($code)) {
        $stmt = $pdo->prepare('SELECT id FROM inventories WHERE code = ?');
        $stmt->execute([$code]);
        if ($stmt->fetch()) {
            $errors[] = 'Nomor Seri barang sudah digunakan (mungkin ada di data sampah/deleted).';
        }
    }
    
    // Handle multiple image upload with compression
    $uploadedImages = [];
    $uploadDir = 'C:/XAMPP/htdocs/inventory_pln/public/assets/uploads/';
    
    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        $fileCount = count($_FILES['images']['name']);
        
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
                        'is_primary' => ($i == $primaryImageIndex) ? 1 : 0,
                        'sort_order' => $i
                    ];
                } else {
                    $errors[] = "Gambar " . ($i + 1) . ": " . $result['error'];
                }
            }
        }
    }
    
    // Set first image as primary if none selected
    if (!empty($uploadedImages)) {
        $hasPrimary = false;
        foreach ($uploadedImages as $img) {
            if ($img['is_primary']) {
                $hasPrimary = true;
                break;
            }
        }
        if (!$hasPrimary) {
            $uploadedImages[0]['is_primary'] = 1;
        }
    }
    
    // Get primary image for main inventory table (backward compatibility)
    $imageName = null;
    foreach ($uploadedImages as $img) {
        if ($img['is_primary']) {
            $imageName = $img['filename'];
            break;
        }
    }
    
    if (empty($errors)) {
        $stmt = $pdo->prepare('INSERT INTO inventories (name, code, item_type, description, stock_total, stock_available, unit, year_acquired, year_manufactured, low_stock_threshold, item_condition, location, rack, notes, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$name, $code ?: null, $item_type ?: null, $description, $stock_total, $stock_total, $unit, $year_acquired ?: null, $year_manufactured ?: null, $low_stock_threshold, $item_condition, $location ?: null, $rack ?: null, $notes ?: null, $imageName]);
        $inventoryId = $pdo->lastInsertId();
        
        // Save multiple images to inventory_images table
        if (!empty($uploadedImages)) {
            $imgStmt = $pdo->prepare('INSERT INTO inventory_images (inventory_id, image_path, is_primary, sort_order) VALUES (?, ?, ?, ?)');
            foreach ($uploadedImages as $img) {
                $imgStmt->execute([$inventoryId, $img['filename'], $img['is_primary'], $img['sort_order']]);
            }
        }
        
        // Save categories
        if (!empty($selectedCategories)) {
            $catStmt = $pdo->prepare('INSERT INTO inventory_categories (inventory_id, category_id) VALUES (?, ?)');
            foreach ($selectedCategories as $catId) {
                $catStmt->execute([$inventoryId, (int)$catId]);
            }
        }

        // Redirect using JavaScript since Header might already be sent
        echo "<script>
            window.location.href = '/index.php?page=admin_inventory_list&msg=added';
        </script>";
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
                            <label class="form-label"><i class="bi bi-upc-scan me-1"></i> Nomor Seri</label>
                            <input name="code" class="form-control" placeholder="Contoh: LPT-001 (opsional)">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-diagram-3 me-1"></i> Tipe Barang</label>
                        <input name="item_type" class="form-control" placeholder="Contoh: Elektronik, Furniture, dll (opsional)">
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
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-calendar me-1"></i> Tahun Perolehan <span class="text-danger">*</span></label>
                            <input type="number" name="year_acquired" class="form-control" placeholder="Contoh: 2024" min="1900" max="<?= date('Y') + 1 ?>" required>
                            <small class="text-muted">Tahun barang diperoleh/dibeli</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-wrench me-1"></i> Tahun Pembuatan</label>
                            <input type="number" name="year_manufactured" class="form-control" placeholder="Contoh: 2023" min="1900" max="<?= date('Y') + 1 ?>">
                            <small class="text-muted">Tahun barang diproduksi (opsional)</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-exclamation-triangle me-1"></i> Batas Stok Menipis</label>
                            <input type="number" name="low_stock_threshold" class="form-control" value="5" min="0">
                            <small class="text-muted">Notifikasi muncul jika stok &le; nilai ini</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-heart-pulse me-1"></i> Kondisi Barang</label>
                            <select name="item_condition" class="form-select">
                                <option value="Baik" selected>Baik</option>
                                <option value="Rusak Ringan">Rusak Ringan</option>
                                <option value="Rusak Berat">Rusak Berat</option>
                            </select>
                            <small class="text-muted">Kondisi fisik barang saat ini</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-geo-alt me-1"></i> Lokasi Barang</label>
                            <input name="location" class="form-control" placeholder="Contoh: Gudang A, Ruang Server, dll (opsional)">
                            <small class="text-muted">Lokasi penyimpanan barang</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-grid-3x3-gap me-1"></i> Rak</label>
                            <input name="rack" class="form-control" placeholder="Contoh: Rak A1, Shelf 3, dll (opsional)">
                            <small class="text-muted">Posisi rak (opsional)</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-journal-text me-1"></i> Keterangan</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Keterangan tambahan untuk barang ini (opsional)"></textarea>
                        <small class="text-muted">Catatan yang dapat dilihat oleh semua pengguna</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-tags me-1"></i> Kategori <span class="text-danger">*</span></label>
                        <div class="row g-2">
                            <?php foreach($categories as $cat): ?>
                            <div class="col-auto">
                                <div class="form-check">
                                    <input class="form-check-input category-checkbox" type="checkbox" name="categories[]" value="<?= $cat['id'] ?>" id="cat<?= $cat['id'] ?>">
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
                        <?php if(!empty($categories)): ?>
                        <small class="text-muted d-block mt-2"><i class="bi bi-info-circle me-1"></i>Pilih minimal satu kategori</small>
                        <?php endif; ?>
                    </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-images me-1"></i> Foto Barang (Multiple)</label>
                        <div class="text-center p-4 mb-3" style="background: rgba(255,255,255,0.05); border: 2px dashed rgba(255,255,255,0.2); border-radius: 10px;" id="imageDropZone">
                            <i class="bi bi-cloud-upload text-secondary" style="font-size: 3rem;"></i>
                            <p class="text-secondary mt-2 mb-0">Drag & drop atau klik untuk memilih</p>
                            <small class="text-muted">Dapat upload lebih dari satu gambar</small>
                        </div>
                        <input type="file" name="images[]" class="form-control" accept="image/*" id="imageInput" multiple style="display:none;">
                        <small class="text-secondary">Format: JPG, PNG, GIF, WEBP. Max 10MB/gambar. Gambar akan dikompres otomatis.</small>
                        
                        <!-- Preview Container -->
                        <div id="imagePreviewContainer" class="mt-3">
                            <div class="row g-2" id="previewImages"></div>
                        </div>
                        <input type="hidden" name="primary_image" id="primaryImageInput" value="0">
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

<style>
.image-preview-item {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid transparent;
    transition: all 0.3s ease;
}
.image-preview-item.primary {
    border-color: var(--primary);
}
.image-preview-item img {
    width: 100%;
    height: 120px;
    object-fit: cover;
}
.image-preview-item .overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease;
}
.image-preview-item:hover .overlay {
    opacity: 1;
}
.image-preview-item .btn-set-primary {
    font-size: 11px;
    padding: 4px 8px;
}
.image-preview-item .btn-remove {
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
}
.primary-badge {
    position: absolute;
    bottom: 4px;
    left: 4px;
    background: var(--primary);
    color: white;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 4px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dropZone = document.getElementById('imageDropZone');
    const input = document.getElementById('imageInput');
    const previewContainer = document.getElementById('previewImages');
    const primaryInput = document.getElementById('primaryImageInput');
    let selectedFiles = [];
    
    // Click to select
    dropZone.addEventListener('click', () => input.click());
    
    // Drag & drop handlers
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(event => {
        dropZone.addEventListener(event, e => {
            e.preventDefault();
            e.stopPropagation();
        });
    });
    
    dropZone.addEventListener('dragenter', () => dropZone.style.borderColor = 'var(--primary)');
    dropZone.addEventListener('dragleave', () => dropZone.style.borderColor = 'rgba(255,255,255,0.2)');
    dropZone.addEventListener('drop', e => {
        dropZone.style.borderColor = 'rgba(255,255,255,0.2)';
        handleFiles(e.dataTransfer.files);
    });
    
    input.addEventListener('change', e => handleFiles(e.target.files));
    
    function handleFiles(files) {
        Array.from(files).forEach(file => {
            if (file.type.startsWith('image/')) {
                if (file.size > 10 * 1024 * 1024) {
                    alert('File ' + file.name + ' terlalu besar (max 10MB)');
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
                const isPrimary = parseInt(primaryInput.value) === index;
                const col = document.createElement('div');
                col.className = 'col-6';
                col.innerHTML = `
                    <div class="image-preview-item ${isPrimary ? 'primary' : ''}" data-index="${index}">
                        <img src="${e.target.result}" alt="Preview">
                        <button type="button" class="btn btn-danger btn-remove" onclick="removeImage(${index})">
                            <i class="bi bi-x"></i>
                        </button>
                        ${isPrimary ? '<span class="primary-badge"><i class="bi bi-star-fill me-1"></i>Utama</span>' : ''}
                        <div class="overlay">
                            ${!isPrimary ? `<button type="button" class="btn btn-light btn-set-primary" onclick="setPrimary(${index})">
                                <i class="bi bi-star me-1"></i>Jadikan Utama
                            </button>` : '<span class="text-white"><i class="bi bi-star-fill me-1"></i>Gambar Utama</span>'}
                        </div>
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
    
    window.removeImage = function(index) {
        selectedFiles.splice(index, 1);
        if (parseInt(primaryInput.value) === index) {
            primaryInput.value = 0;
        } else if (parseInt(primaryInput.value) > index) {
            primaryInput.value = parseInt(primaryInput.value) - 1;
        }
        updatePreview();
        updateFileInput();
    };
    
    window.setPrimary = function(index) {
        primaryInput.value = index;
        updatePreview();
    };
});
</script>
