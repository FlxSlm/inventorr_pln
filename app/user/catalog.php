<?php
// app/user/catalog.php
// Halaman katalog barang untuk karyawan dan admin

if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}

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

<!-- Header -->
<div class="dashboard-header mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h3 class="mb-1"><i class="bi bi-grid me-2"></i>Katalog Barang</h3>
            <p class="mb-0 opacity-75">Lihat semua barang inventaris yang tersedia untuk dipinjam</p>
        </div>
        <a href="/index.php?page=user_request_loan" class="btn btn-warning mt-2 mt-md-0">
            <i class="bi bi-plus-lg me-1"></i> Ajukan Peminjaman
        </a>
    </div>
</div>

<!-- Search Bar -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="/index.php" class="row g-3">
            <input type="hidden" name="page" value="catalog">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text" style="background: rgba(15, 117, 188, 0.2); border-color: rgba(255,255,255,0.1);">
                        <i class="bi bi-search text-pln-blue"></i>
                    </span>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Cari barang berdasarkan nama, kode, atau deskripsi..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-4">
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

<!-- Category Pills -->
<?php if (!empty($categories)): ?>
<div class="mb-4">
    <div class="d-flex flex-wrap gap-2">
        <a href="/index.php?page=catalog" class="btn btn-sm <?= !$categoryFilter ? 'btn-warning' : 'btn-outline-secondary' ?>">
            Semua
        </a>
        <?php foreach($categories as $cat): ?>
            <a href="/index.php?page=catalog&category=<?= $cat['id'] ?>" 
               class="btn btn-sm <?= $categoryFilter == $cat['id'] ? '' : 'btn-outline-secondary' ?>"
               style="<?= $categoryFilter == $cat['id'] ? 'background: '.$cat['color'].'; border-color: '.$cat['color'].'; color: white;' : '' ?>">
                <?= htmlspecialchars($cat['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($search || $categoryFilter): ?>
    <div class="mb-3">
        <?php if ($search): ?>
            <span class="text-secondary">Hasil pencarian untuk "</span>
            <span class="text-pln-yellow fw-bold"><?= htmlspecialchars($search) ?></span>
            <span class="text-secondary">"</span>
        <?php endif; ?>
        <?php if ($categoryFilter): 
            $selectedCat = array_filter($categories, fn($c) => $c['id'] == $categoryFilter);
            $selectedCat = reset($selectedCat);
        ?>
            <?php if ($search): ?><span class="text-secondary"> dalam kategori </span><?php endif; ?>
            <span class="badge" style="background: <?= htmlspecialchars($selectedCat['color'] ?? '#6B7280') ?>;"><?= htmlspecialchars($selectedCat['name'] ?? 'Unknown') ?></span>
        <?php endif; ?>
        <span class="text-secondary"> - <?= count($items) ?> barang ditemukan</span>
        <a href="/index.php?page=catalog" class="ms-2 text-danger"><i class="bi bi-x-circle"></i> Reset</a>
    </div>
<?php endif; ?>

<!-- Catalog Grid -->
<div class="row g-4">
    <?php if (empty($items)): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-search text-secondary" style="font-size: 4rem;"></i>
                    <h5 class="mt-3 text-secondary">Tidak Ada Barang Ditemukan</h5>
                    <p class="text-secondary">
                        <?php if ($search): ?>
                            Coba gunakan kata kunci lain untuk mencari barang.
                        <?php else: ?>
                            Belum ada barang dalam inventaris.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach($items as $it): ?>
            <div class="col-md-6 col-lg-4 col-xl-3">
                <div class="card h-100 catalog-card">
                    <!-- Image -->
                    <div class="catalog-image-container">
                        <?php if ($it['image']): ?>
                            <img src="/public/assets/uploads/<?= htmlspecialchars($it['image']) ?>" 
                                 alt="<?= htmlspecialchars($it['name']) ?>" 
                                 class="card-img-top catalog-image">
                        <?php else: ?>
                            <div class="no-image-placeholder">
                                <i class="bi bi-box-seam"></i>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Availability Badge -->
                        <?php if ($it['stock_available'] > 0): ?>
                            <span class="availability-badge available">
                                <i class="bi bi-check-circle me-1"></i>Tersedia
                            </span>
                        <?php else: ?>
                            <span class="availability-badge unavailable">
                                <i class="bi bi-x-circle me-1"></i>Habis
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-body">
                        <h5 class="card-title text-pln-yellow mb-1"><?= htmlspecialchars($it['name']) ?></h5>
                        <p class="text-secondary small mb-2">
                            <i class="bi bi-upc-scan me-1"></i><?= htmlspecialchars($it['code']) ?>
                        </p>
                        
                        <?php if (!empty($itemCategories[$it['id']])): ?>
                            <div class="mb-2">
                                <?php foreach($itemCategories[$it['id']] as $cat): ?>
                                    <span class="badge me-1" style="background: <?= htmlspecialchars($cat['color']) ?>; font-size: 0.7rem;">
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($it['description']): ?>
                            <p class="card-text small text-secondary mb-3" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                <?= htmlspecialchars($it['description']) ?>
                            </p>
                        <?php endif; ?>
                        
                        <!-- Stock Info -->
                        <div class="stock-info-box">
                            <div class="row g-0 text-center">
                                <div class="col-6" style="border-right: 1px solid rgba(255,255,255,0.1);">
                                    <small class="text-secondary d-block">Stok Total</small>
                                    <span class="fw-bold text-pln-yellow"><?= $it['stock_total'] ?></span>
                                    <small class="text-secondary"><?= htmlspecialchars($it['unit'] ?? 'unit') ?></small>
                                </div>
                                <div class="col-6">
                                    <small class="text-secondary d-block">Tersedia</small>
                                    <span class="fw-bold text-pln-yellow"><?= $it['stock_available'] ?></span>
                                    <small class="text-secondary"><?= htmlspecialchars($it['unit'] ?? 'unit') ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-footer bg-transparent border-top" style="border-color: rgba(255,255,255,0.1) !important;">
                        <?php if ($it['stock_available'] > 0): ?>
                            <a href="/index.php?page=user_request_loan&item=<?= $it['id'] ?>" class="btn btn-warning w-100">
                                <i class="bi bi-plus-circle me-1"></i> Ajukan Peminjaman
                            </a>
                        <?php else: ?>
                            <button class="btn btn-secondary w-100" disabled>
                                <i class="bi bi-x-circle me-1"></i> Stok Habis
                            </button>
                        <?php endif; ?>
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
    box-shadow: 0 10px 30px rgba(15, 117, 188, 0.3);
}
.catalog-image-container {
    position: relative;
    height: 180px;
    overflow: hidden;
    background: linear-gradient(135deg, #0D1E36 0%, #122640 100%);
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
.no-image-placeholder {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3.5rem;
    color: rgba(255,255,255,0.1);
}
.availability-badge {
    position: absolute;
    top: 10px;
    left: 10px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}
.availability-badge.available {
    background: rgba(16, 185, 129, 0.9);
    color: white;
}
.availability-badge.unavailable {
    background: rgba(220, 53, 69, 0.9);
    color: white;
}
.stock-info-box {
    background: rgba(15, 117, 188, 0.1);
    border-radius: 10px;
    padding: 12px;
    margin-top: auto;
}
</style>
