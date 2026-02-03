<?php
// app/admin/inventory_list.php - Modern Style with Filters
$pageTitle = 'Kelola Inventaris';
$pdo = require __DIR__ . '/../config/database.php';

// Fetch all categories for filter
$categories = $pdo->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();

// Get filter parameters
$filterCategories = $_GET['categories'] ?? [];
$filterConditions = $_GET['conditions'] ?? [];
$filterLowStock = isset($_GET['low_stock']) ? true : false;
$filterOutOfStock = isset($_GET['out_of_stock']) ? true : false;
$filterSearch = trim($_GET['search'] ?? '');
$conditionFilter = $_GET['condition'] ?? ''; // for dashboard link

// Build query with filters
$sql = 'SELECT DISTINCT i.* FROM inventories i';
$params = [];
$where = ['i.deleted_at IS NULL'];

// Join categories if filter applied
if (!empty($filterCategories)) {
    $sql .= ' JOIN inventory_categories ic ON ic.inventory_id = i.id';
    $placeholders = implode(',', array_fill(0, count($filterCategories), '?'));
    $where[] = "ic.category_id IN ($placeholders)";
    $params = array_merge($params, $filterCategories);
}

// Filter by condition
if (!empty($filterConditions)) {
    $condPlaceholders = implode(',', array_fill(0, count($filterConditions), '?'));
    $where[] = "i.item_condition IN ($condPlaceholders)";
    $params = array_merge($params, $filterConditions);
}

// Special filter for damaged from dashboard
if ($conditionFilter === 'damaged') {
    $where[] = "i.item_condition IN ('Rusak Ringan', 'Rusak Berat')";
}

// Filter by low stock
if ($filterLowStock) {
    $where[] = 'i.stock_available <= COALESCE(i.low_stock_threshold, 5) AND i.stock_available > 0';
}

// Filter by out of stock
if ($filterOutOfStock) {
    $where[] = 'i.stock_available = 0';
}

// Search filter
if (!empty($filterSearch)) {
    $where[] = '(i.name LIKE ? OR i.code LIKE ? OR i.description LIKE ?)';
    $searchParam = "%{$filterSearch}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY i.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Fetch categories for each item
$itemCategories = [];
foreach ($items as $item) {
    $catStmt = $pdo->prepare('SELECT c.* FROM categories c JOIN inventory_categories ic ON ic.category_id = c.id WHERE ic.inventory_id = ?');
    $catStmt->execute([$item['id']]);
    $itemCategories[$item['id']] = $catStmt->fetchAll();
}

$msg = $_GET['msg'] ?? '';
$hasFilters = !empty($filterCategories) || !empty($filterConditions) || $filterLowStock || $filterOutOfStock || !empty($filterSearch) || $conditionFilter === 'damaged';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-box-seam-fill"></i> Kelola Inventaris</h3>
        <p>Total <?= count($items) ?> barang dalam inventaris</p>
    </div>
    <div class="page-header-actions">
        <a class="btn btn-primary" href="/index.php?page=admin_inventory_add">
            <i class="bi bi-plus-lg me-1"></i> Tambah Barang
        </a>
    </div>
</div>

<!-- Alerts -->
<?php if ($msg === 'updated'): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle-fill me-2"></i>Barang berhasil diperbarui.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php elseif ($msg === 'added'): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle-fill me-2"></i>Barang berhasil ditambahkan.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php elseif ($msg === 'deleted'): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle-fill me-2"></i>Barang berhasil dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filter Section -->
<div class="modern-card" style="margin-bottom: 24px;">
    <div class="card-body" style="padding: 20px;">
        <form method="GET" action="/index.php" id="filterForm">
            <input type="hidden" name="page" value="admin_inventory_list">
            
            <div class="row g-3">
                <!-- Search -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold"><i class="bi bi-search me-1"></i>Cari Barang</label>
                    <input type="text" name="search" class="form-control" placeholder="Nama, kode, atau deskripsi..." value="<?= htmlspecialchars($filterSearch) ?>">
                </div>
                
                <!-- Categories Filter -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><i class="bi bi-tags me-1"></i>Kategori</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach($categories as $cat): ?>
                        <div class="form-check" style="margin: 0;">
                            <input class="form-check-input" type="checkbox" name="categories[]" value="<?= $cat['id'] ?>" id="cat<?= $cat['id'] ?>" <?= in_array($cat['id'], $filterCategories) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cat<?= $cat['id'] ?>" style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($cat['name']) ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($categories)): ?>
                        <small class="text-muted">Belum ada kategori</small>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Condition Filter -->
                <div class="col-md-2">
                    <label class="form-label fw-semibold"><i class="bi bi-heart-pulse me-1"></i>Kondisi</label>
                    <div class="d-flex flex-column gap-1">
                        <div class="form-check" style="margin: 0;">
                            <input class="form-check-input" type="checkbox" name="conditions[]" value="Baik" id="condBaik" <?= in_array('Baik', $filterConditions) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="condBaik" style="font-size: 13px;">Baik</label>
                        </div>
                        <div class="form-check" style="margin: 0;">
                            <input class="form-check-input" type="checkbox" name="conditions[]" value="Rusak Ringan" id="condRR" <?= in_array('Rusak Ringan', $filterConditions) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="condRR" style="font-size: 13px;">Rusak Ringan</label>
                        </div>
                        <div class="form-check" style="margin: 0;">
                            <input class="form-check-input" type="checkbox" name="conditions[]" value="Rusak Berat" id="condRB" <?= in_array('Rusak Berat', $filterConditions) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="condRB" style="font-size: 13px;">Rusak Berat</label>
                        </div>
                    </div>
                </div>
                
                <!-- Stock Filter -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><i class="bi bi-box-seam me-1"></i>Stok</label>
                    <div class="d-flex flex-column gap-1">
                        <div class="form-check" style="margin: 0;">
                            <input class="form-check-input" type="checkbox" name="low_stock" value="1" id="lowStock" <?= $filterLowStock ? 'checked' : '' ?>>
                            <label class="form-check-label" for="lowStock" style="font-size: 13px;">
                                <i class="bi bi-exclamation-triangle text-warning me-1"></i>Stok Menipis
                            </label>
                        </div>
                        <div class="form-check" style="margin: 0;">
                            <input class="form-check-input" type="checkbox" name="out_of_stock" value="1" id="outOfStock" <?= $filterOutOfStock ? 'checked' : '' ?>>
                            <label class="form-check-label" for="outOfStock" style="font-size: 13px;">
                                <i class="bi bi-x-circle text-danger me-1"></i>Stok Habis
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel me-1"></i>Terapkan Filter
                </button>
                <?php if ($hasFilters): ?>
                <a href="/index.php?page=admin_inventory_list" class="btn btn-outline-secondary">
                    <i class="bi bi-x-lg me-1"></i>Reset Filter
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Filter Result Info -->
<?php if ($hasFilters): ?>
<div class="alert alert-info d-flex align-items-center justify-content-between" style="margin-bottom: 24px;">
    <div>
        <i class="bi bi-funnel-fill me-2"></i>
        Menampilkan <strong><?= count($items) ?></strong> barang dengan filter aktif
        <?php if ($conditionFilter === 'damaged'): ?>
        <span class="badge bg-warning ms-2">Barang Rusak</span>
        <?php endif; ?>
    </div>
    <a href="/index.php?page=admin_inventory_list" class="btn btn-sm btn-outline-info">Reset</a>
</div>
<?php endif; ?>

<!-- Inventory Cards Grid -->
<div class="row g-4">
    <?php if (empty($items)): ?>
    <div class="col-12">
        <div class="modern-card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-box-seam"></i>
                    </div>
                    <h5 class="empty-state-title">Belum Ada Barang</h5>
                    <p class="empty-state-text">Mulai dengan menambahkan barang pertama ke inventaris.</p>
                    <a href="/index.php?page=admin_inventory_add" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i> Tambah Barang
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <?php foreach($items as $it): ?>
    <div class="col-md-6 col-lg-4">
        <div class="modern-card inventory-card h-100">
            <!-- Image -->
            <div class="inventory-image-container">
                <?php if ($it['image']): ?>
                <img src="/public/assets/uploads/<?= htmlspecialchars($it['image']) ?>" 
                     alt="<?= htmlspecialchars($it['name']) ?>" 
                     class="inventory-image">
                <?php else: ?>
                <div class="no-image-placeholder">
                    <i class="bi bi-box-seam"></i>
                </div>
                <?php endif; ?>
                
                <!-- Stock Badge -->
                <?php 
                $stockPercent = $it['stock_total'] > 0 ? ($it['stock_available'] / $it['stock_total']) * 100 : 0;
                $lowStockThreshold = $it['low_stock_threshold'] ?? 5;
                $isLowStock = $it['stock_available'] <= $lowStockThreshold;
                
                if ($it['stock_available'] <= 0) {
                    $stockClass = 'danger';
                } elseif ($isLowStock) {
                    $stockClass = 'warning';
                } elseif ($stockPercent > 50) {
                    $stockClass = 'success';
                } else {
                    $stockClass = 'warning';
                }
                ?>
                <span class="stock-badge <?= $stockClass ?>">
                    <?= $it['stock_available'] ?>/<?= $it['stock_total'] ?> <?= htmlspecialchars($it['unit'] ?? 'unit') ?>
                </span>
                
                <!-- Low Stock Warning Ribbon -->
                <?php if ($isLowStock && $it['stock_available'] > 0): ?>
                <div class="low-stock-ribbon">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>Stok Menipis
                </div>
                <?php elseif ($it['stock_available'] <= 0): ?>
                <div class="low-stock-ribbon danger">
                    <i class="bi bi-x-circle-fill me-1"></i>Stok Habis
                </div>
                <?php endif; ?>
                
                <!-- Condition Badge -->
                <?php 
                $condition = $it['item_condition'] ?? 'Baik';
                $conditionBadgeClass = $condition === 'Baik' ? 'success' : ($condition === 'Rusak Ringan' ? 'warning' : 'danger');
                ?>
                <?php if ($condition !== 'Baik'): ?>
                <span class="condition-badge <?= $conditionBadgeClass ?>" style="position: absolute; bottom: 12px; left: 12px;">
                    <i class="bi bi-<?= $condition === 'Rusak Ringan' ? 'exclamation-triangle' : 'x-circle' ?> me-1"></i><?= $condition ?>
                </span>
                <?php endif; ?>
            </div>
            
            <div class="card-body" style="padding: 20px;">
                <!-- Categories -->
                <?php if (!empty($itemCategories[$it['id']])): ?>
                <div style="margin-bottom: 8px;">
                    <?php foreach($itemCategories[$it['id']] as $cat): ?>
                    <span class="badge" style="background: <?= htmlspecialchars($cat['color']) ?>20; color: <?= htmlspecialchars($cat['color']) ?>; font-size: 10px; margin-right: 4px;">
                        <?= htmlspecialchars($cat['name']) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <h5 style="margin: 0 0 4px 0; font-weight: 600; color: var(--primary-light);">
                    <?= htmlspecialchars($it['name']) ?>
                </h5>
                <p style="color: var(--text-muted); font-size: 13px; margin: 0 0 12px 0;">
                    <i class="bi bi-upc-scan me-1"></i><?= htmlspecialchars($it['code']) ?>
                </p>
                
                <?php if ($it['description']): ?>
                <p style="color: var(--text-muted); font-size: 14px; margin: 0 0 16px 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                    <?= htmlspecialchars($it['description']) ?>
                </p>
                <?php endif; ?>
                
                <!-- Stock Progress -->
                <div style="margin-bottom: 16px;">
                    <div class="d-flex justify-content-between" style="font-size: 12px; margin-bottom: 6px;">
                        <span style="color: var(--text-muted);">Ketersediaan Stok</span>
                        <span style="font-weight: 600; color: var(--text-dark);"><?= round($stockPercent) ?>%</span>
                    </div>
                    <div style="height: 6px; background: var(--bg-main); border-radius: 3px; overflow: hidden;">
                        <div style="height: 100%; width: <?= $stockPercent ?>%; background: var(--<?= $stockClass ?>); border-radius: 3px; transition: width 0.3s ease;"></div>
                    </div>
                </div>
                
                <!-- Stats Row -->
                <div class="row g-2">
                    <div class="col-6">
                        <div class="stat-box">
                            <small style="color: var(--text-muted);">Total</small>
                            <div style="font-weight: 700; color: var(--primary-light); font-size: 18px;"><?= $it['stock_total'] ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-box">
                            <small style="color: var(--text-muted);">Tersedia</small>
                            <div style="font-weight: 700; color: var(--<?= $stockClass ?>); font-size: 18px;"><?= $it['stock_available'] ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div style="padding: 16px 20px; border-top: 1px solid var(--border-color);">
                <div class="d-flex gap-2">
                    <a href="/index.php?page=admin_inventory_edit&id=<?= $it['id'] ?>" class="btn btn-primary btn-sm flex-fill">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </a>
                    <a href="/index.php?page=admin_inventory_delete&id=<?= $it['id'] ?>" 
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Hapus barang ini?')">
                        <i class="bi bi-trash"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
.inventory-card {
    transition: all 0.3s ease;
    overflow: hidden;
}
.inventory-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-lg);
}
.inventory-image-container {
    position: relative;
    height: 180px;
    overflow: hidden;
    background: linear-gradient(135deg, var(--bg-main) 0%, var(--border-color) 100%);
}
.inventory-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}
.inventory-card:hover .inventory-image {
    transform: scale(1.05);
}
.no-image-placeholder {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 4rem;
    color: var(--border-color);
}
.stock-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 6px 12px;
    border-radius: var(--radius-lg);
    font-size: 12px;
    font-weight: 600;
    color: #fff;
}
.stock-badge.success { background: var(--success); }
.stock-badge.warning { background: var(--warning); }
.stock-badge.danger { background: var(--danger); }
.stat-box {
    background: var(--bg-main);
    padding: 12px;
    border-radius: var(--radius);
    text-align: center;
}
.low-stock-ribbon {
    position: absolute;
    top: 12px;
    left: -35px;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
    padding: 6px 40px;
    font-size: 11px;
    font-weight: 600;
    transform: rotate(-45deg);
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    z-index: 10;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.low-stock-ribbon.danger {
    background: linear-gradient(135deg, #ef4444, #dc2626);
}
.condition-badge {
    padding: 6px 10px;
    border-radius: var(--radius);
    font-size: 11px;
    font-weight: 600;
    color: #fff;
}
.condition-badge.success { background: var(--success); }
.condition-badge.warning { background: var(--warning); }
.condition-badge.danger { background: var(--danger); }
.filter-checkbox-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.filter-checkbox-group .form-check {
    margin: 0;
}
</style>
