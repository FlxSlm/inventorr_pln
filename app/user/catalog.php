<?php
// app/user/catalog.php - Modern Style
// Halaman katalog barang untuk karyawan dan admin

if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}

$pageTitle = 'Katalog Barang';
$pdo = require __DIR__ . '/../config/database.php';

// Get search query and category filter
$search = trim($_GET['search'] ?? '');
$categoryFilter = (int)($_GET['category'] ?? 0);

// Fetch all categories for filter
$categories = $pdo->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();

// Build query
$sql = 'SELECT DISTINCT i.* FROM inventories i';
$params = [];
$where = ['i.deleted_at IS NULL'];

// Join categories if filter applied
if ($categoryFilter) {
    $sql .= ' JOIN inventory_categories ic ON ic.inventory_id = i.id';
    $where[] = 'ic.category_id = ?';
    $params[] = $categoryFilter;
}

if ($search) {
    $where[] = '(i.name LIKE ? OR i.code LIKE ? OR i.description LIKE ?)';
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY i.name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Fetch categories for each item
$itemCategories = [];
foreach ($items as $item) {
    $catStmt = $pdo->prepare('
        SELECT c.* FROM categories c
        JOIN inventory_categories ic ON ic.category_id = c.id
        WHERE ic.inventory_id = ?
    ');
    $catStmt->execute([$item['id']]);
    $itemCategories[$item['id']] = $catStmt->fetchAll();
}
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-grid-3x3-gap-fill"></i> Katalog Barang</h3>
        <p>Lihat semua barang inventaris yang tersedia untuk dipinjam</p>
    </div>
    <div class="page-header-actions">
        <a href="/index.php?page=user_request_loan" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i> Ajukan Peminjaman
        </a>
    </div>
</div>

<!-- Search & Filter -->
<div class="modern-card" style="margin-bottom: 24px;">
    <div class="card-body" style="padding: 20px;">
        <form method="GET" action="/index.php" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="catalog">
            <div class="col-md-5">
                <label class="form-label">Cari Barang</label>
                <div class="topbar-search" style="max-width: 100%;">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" 
                           placeholder="Nama, kode, atau deskripsi..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Kategori</label>
                <select name="category" class="form-select">
                    <option value="0">-- Semua Kategori --</option>
                    <?php foreach($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search me-1"></i> Cari
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Result Info -->
<?php if ($search || $categoryFilter): ?>
<div class="alert alert-info" style="margin-bottom: 24px;">
    <i class="bi bi-info-circle me-2"></i>
    Menampilkan <strong><?= count($items) ?></strong> barang
    <?php if ($search): ?> dengan kata kunci "<strong><?= htmlspecialchars($search) ?></strong>"<?php endif; ?>
    <?php if ($categoryFilter): 
        $selectedCat = array_filter($categories, fn($c) => $c['id'] == $categoryFilter);
        $selectedCat = reset($selectedCat);
    ?>
        dalam kategori "<strong><?= htmlspecialchars($selectedCat['name'] ?? '') ?></strong>"
    <?php endif; ?>
    <a href="/index.php?page=catalog" class="ms-2">Reset Filter</a>
</div>
<?php endif; ?>

<!-- Items Grid -->
<div class="row g-4">
    <?php if (empty($items)): ?>
    <div class="col-12">
        <div class="modern-card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-search"></i>
                    </div>
                    <h5 class="empty-state-title">Tidak Ada Barang Ditemukan</h5>
                    <p class="empty-state-text">
                        <?php if ($search || $categoryFilter): ?>
                        Coba ubah kata kunci pencarian atau filter kategori
                        <?php else: ?>
                        Belum ada barang dalam inventaris
                        <?php endif; ?>
                    </p>
                    <?php if ($search || $categoryFilter): ?>
                    <a href="/index.php?page=catalog" class="btn btn-primary">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Filter
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <?php foreach($items as $item): ?>
    <div class="col-md-6 col-lg-4">
        <div class="modern-card catalog-card h-100">
            <!-- Image -->
            <div class="catalog-image-container">
                <?php if ($item['image']): ?>
                <img src="/public/assets/uploads/<?= htmlspecialchars($item['image']) ?>" 
                     alt="<?= htmlspecialchars($item['name']) ?>" 
                     class="catalog-image">
                <?php else: ?>
                <div class="catalog-placeholder">
                    <i class="bi bi-box-seam"></i>
                </div>
                <?php endif; ?>
                
                <!-- Stock Badge -->
                <?php 
                $stockPercent = $item['stock_total'] > 0 ? ($item['stock_available'] / $item['stock_total']) * 100 : 0;
                $lowStockThreshold = $item['low_stock_threshold'] ?? 5;
                $isLowStock = $item['stock_available'] <= $lowStockThreshold;
                
                if ($item['stock_available'] <= 0) {
                    $stockClass = 'danger';
                    $stockText = 'Habis';
                } elseif ($isLowStock) {
                    $stockClass = 'warning';
                    $stockText = $item['stock_available'] . ' tersisa';
                } else {
                    $stockClass = 'success';
                    $stockText = $item['stock_available'] . ' tersedia';
                }
                ?>
                <span class="catalog-stock-badge <?= $stockClass ?>">
                    <?= $stockText ?>
                </span>
                
                <!-- Low Stock Warning Ribbon -->
                <?php if ($isLowStock && $item['stock_available'] > 0): ?>
                <div class="low-stock-ribbon">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>Stok Menipis
                </div>
                <?php elseif ($item['stock_available'] <= 0): ?>
                <div class="low-stock-ribbon danger">
                    <i class="bi bi-x-circle-fill me-1"></i>Stok Habis
                </div>
                <?php endif; ?>
                
                <!-- Condition Badge -->
                <?php 
                $condition = $item['item_condition'] ?? 'Baik';
                $conditionBadgeClass = $condition === 'Baik' ? 'success' : ($condition === 'Rusak Ringan' ? 'warning' : 'danger');
                ?>
                <?php if ($condition !== 'Baik'): ?>
                <span class="catalog-condition-badge <?= $conditionBadgeClass ?>">
                    <i class="bi bi-<?= $condition === 'Rusak Ringan' ? 'exclamation-triangle' : 'x-circle' ?> me-1"></i><?= $condition ?>
                </span>
                <?php endif; ?>
            </div>
            
            <div class="card-body" style="padding: 20px;">
                <!-- Categories -->
                <?php if (!empty($itemCategories[$item['id']])): ?>
                <div style="margin-bottom: 12px;">
                    <?php foreach($itemCategories[$item['id']] as $cat): ?>
                    <span class="badge" style="background: <?= htmlspecialchars($cat['color']) ?>20; color: <?= htmlspecialchars($cat['color']) ?>; font-size: 11px; margin-right: 4px;">
                        <?= htmlspecialchars($cat['name']) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <h5 style="font-weight: 600; color: var(--text-dark); margin: 0 0 4px 0;">
                    <?= htmlspecialchars($item['name']) ?>
                </h5>
                <p style="color: var(--text-muted); font-size: 13px; margin: 0 0 12px 0;">
                    <i class="bi bi-upc-scan me-1"></i><?= htmlspecialchars($item['code']) ?>
                </p>
                
                <?php if ($item['description']): ?>
                <p style="color: var(--text-muted); font-size: 14px; margin: 0 0 16px 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                    <?= htmlspecialchars($item['description']) ?>
                </p>
                <?php endif; ?>
                
                <!-- Stock Progress -->
                <div style="margin-bottom: 16px;">
                    <div class="d-flex justify-content-between" style="font-size: 12px; margin-bottom: 6px;">
                        <span style="color: var(--text-muted);">Ketersediaan</span>
                        <span style="font-weight: 600;"><?= $item['stock_available'] ?> / <?= $item['stock_total'] ?> <?= htmlspecialchars($item['unit'] ?? 'unit') ?></span>
                    </div>
                    <div style="height: 6px; background: var(--bg-main); border-radius: 3px; overflow: hidden;">
                        <div style="height: 100%; width: <?= $stockPercent ?>%; background: var(--<?= $stockClass ?>); border-radius: 3px;"></div>
                    </div>
                </div>
            </div>
            
            <div style="padding: 16px 20px; border-top: 1px solid var(--border-color);">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary flex-shrink-0" 
                            data-bs-toggle="modal" data-bs-target="#detailModal<?= $item['id'] ?>" title="Lihat Detail">
                        <i class="bi bi-eye"></i>
                    </button>
                    <?php if ($item['stock_available'] > 0): ?>
                    <a href="/index.php?page=user_request_loan&item=<?= $item['id'] ?>" class="btn btn-primary flex-grow-1">
                        <i class="bi bi-hand-index me-1"></i> Pinjam
                    </a>
                    <a href="/index.php?page=user_request_item&item=<?= $item['id'] ?>" class="btn btn-success flex-grow-1">
                        <i class="bi bi-bag-plus me-1"></i> Minta
                    </a>
                    <?php else: ?>
                    <button class="btn btn-secondary flex-grow-1" disabled>
                        <i class="bi bi-x-circle me-1"></i> Stok Habis
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Detail Modal -->
    <div class="modal fade" id="detailModal<?= $item['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border: none; border-radius: 16px; overflow: hidden;">
                <!-- Header with Image -->
                <div style="position: relative; background: linear-gradient(135deg, var(--bg-main) 0%, var(--border-color) 100%);">
                    <?php if ($item['image']): ?>
                    <div style="display: flex; justify-content: center; align-items: center; padding: 20px; min-height: 200px; max-height: 280px;">
                        <img src="/public/assets/uploads/<?= htmlspecialchars($item['image']) ?>" 
                             alt="<?= htmlspecialchars($item['name']) ?>" 
                             style="max-width: 100%; max-height: 240px; object-fit: contain; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                    </div>
                    <?php else: ?>
                    <div style="height: 120px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); display: flex; align-items: center; justify-content: center;">
                        <i class="bi bi-box-seam" style="font-size: 3rem; color: rgba(255,255,255,0.5);"></i>
                    </div>
                    <?php endif; ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="position: absolute; top: 12px; right: 12px; background-color: rgba(0,0,0,0.3); border-radius: 50%; padding: 10px;"></button>
                    
                    <!-- Stock Badge on Image -->
                    <span class="catalog-stock-badge <?= $stockClass ?>" style="position: absolute; bottom: 12px; right: 12px;">
                        <?= $stockText ?>
                    </span>
                    
                    <?php 
                    // Condition badge
                    $condition = $item['item_condition'] ?? 'Baik';
                    $conditionClass = $condition === 'Baik' ? 'success' : ($condition === 'Rusak Ringan' ? 'warning' : 'danger');
                    ?>
                    <?php if ($condition !== 'Baik'): ?>
                    <span class="badge bg-<?= $conditionClass ?>" style="position: absolute; bottom: 12px; left: 12px; padding: 8px 12px;">
                        <i class="bi bi-<?= $condition === 'Rusak Ringan' ? 'exclamation-triangle' : 'x-circle' ?> me-1"></i><?= $condition ?>
                    </span>
                    <?php endif; ?>
                </div>
                
                <div class="modal-body" style="padding: 24px;">
                    <!-- Title and Categories -->
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h4 style="font-weight: 700; color: var(--text-dark); margin: 0 0 8px 0;">
                                <?= htmlspecialchars($item['name']) ?>
                            </h4>
                            <?php if (!empty($item['code'])): ?>
                            <span class="text-muted"><i class="bi bi-upc-scan me-1"></i><?= htmlspecialchars($item['code']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($itemCategories[$item['id']])): ?>
                        <div class="d-flex gap-1 flex-wrap justify-content-end" style="max-width: 50%;">
                            <?php foreach($itemCategories[$item['id']] as $cat): ?>
                            <span class="badge" style="background: <?= htmlspecialchars($cat['color']) ?>; color: #fff;">
                                <?= htmlspecialchars($cat['name']) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Quick Info Grid -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div style="background: var(--bg-main); border-radius: 10px; padding: 14px; text-align: center;">
                                <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Stok Total</div>
                                <div style="font-size: 20px; font-weight: 700; color: var(--text-dark);"><?= $item['stock_total'] ?></div>
                                <div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($item['unit'] ?? 'unit') ?></div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div style="background: var(--bg-main); border-radius: 10px; padding: 14px; text-align: center;">
                                <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Tersedia</div>
                                <div style="font-size: 20px; font-weight: 700; color: var(--<?= $stockClass ?>);"><?= $item['stock_available'] ?></div>
                                <div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($item['unit'] ?? 'unit') ?></div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div style="background: var(--bg-main); border-radius: 10px; padding: 14px; text-align: center;">
                                <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Kondisi</div>
                                <div style="font-size: 14px; font-weight: 600; color: var(--<?= $conditionClass ?>); margin-top: 4px;">
                                    <i class="bi bi-<?= $condition === 'Baik' ? 'check-circle' : ($condition === 'Rusak Ringan' ? 'exclamation-triangle' : 'x-circle') ?>"></i>
                                </div>
                                <div style="font-size: 12px; color: var(--text-muted);"><?= $condition ?></div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div style="background: var(--bg-main); border-radius: 10px; padding: 14px; text-align: center;">
                                <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Tahun</div>
                                <div style="font-size: 20px; font-weight: 700; color: var(--text-dark);"><?= htmlspecialchars($item['year_acquired'] ?? '-') ?></div>
                                <div style="font-size: 12px; color: var(--text-muted);">Perolehan</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Additional Info -->
                    <?php if (!empty($item['item_type']) || !empty($item['year_manufactured']) || !empty($item['description'])): ?>
                    <div style="border-top: 1px solid var(--border-color); padding-top: 16px;">
                        <?php if (!empty($item['item_type'])): ?>
                        <div class="d-flex align-items-center mb-2">
                            <span style="width: 130px; color: var(--text-muted); font-size: 13px;"><i class="bi bi-diagram-3 me-2"></i>Tipe Barang</span>
                            <span style="font-weight: 500;"><?= htmlspecialchars($item['item_type']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($item['year_manufactured'])): ?>
                        <div class="d-flex align-items-center mb-2">
                            <span style="width: 130px; color: var(--text-muted); font-size: 13px;"><i class="bi bi-wrench me-2"></i>Tahun Dibuat</span>
                            <span style="font-weight: 500;"><?= htmlspecialchars($item['year_manufactured']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($item['description'])): ?>
                        <div class="mt-3">
                            <div style="color: var(--text-muted); font-size: 13px; margin-bottom: 6px;"><i class="bi bi-card-text me-2"></i>Deskripsi</div>
                            <p style="margin: 0; color: var(--text-dark); line-height: 1.6;"><?= nl2br(htmlspecialchars($item['description'])) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid var(--border-color);">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <?php if ($item['stock_available'] > 0): ?>
                    <a href="/index.php?page=user_request_loan&item=<?= $item['id'] ?>" class="btn btn-primary">
                        <i class="bi bi-hand-index me-1"></i> Pinjam
                    </a>
                    <a href="/index.php?page=user_request_item&item=<?= $item['id'] ?>" class="btn btn-success">
                        <i class="bi bi-bag-plus me-1"></i> Minta
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
.catalog-card {
    transition: all 0.3s ease;
    overflow: hidden;
}
.catalog-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-lg);
}
.catalog-image-container {
    position: relative;
    height: 180px;
    overflow: hidden;
    background: linear-gradient(135deg, var(--bg-main) 0%, var(--border-color) 100%);
}
.catalog-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}
.catalog-card:hover .catalog-image {
    transform: scale(1.05);
}
.catalog-placeholder {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 4rem;
    color: var(--border-color);
}
.catalog-stock-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 6px 12px;
    border-radius: var(--radius-lg);
    font-size: 12px;
    font-weight: 600;
    color: #fff;
}
.catalog-stock-badge.success { background: var(--success); }
.catalog-stock-badge.warning { background: var(--warning); }
.catalog-stock-badge.danger { background: var(--danger); }
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
.catalog-condition-badge {
    position: absolute;
    bottom: 12px;
    left: 12px;
    padding: 6px 10px;
    border-radius: var(--radius);
    font-size: 11px;
    font-weight: 600;
    color: #fff;
}
.catalog-condition-badge.success { background: var(--success); }
.catalog-condition-badge.warning { background: var(--warning); }
.catalog-condition-badge.danger { background: var(--danger); }
</style>
