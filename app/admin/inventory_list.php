<?php
// app/admin/inventory_list.php - Modern Style
$pageTitle = 'Kelola Inventaris';
$pdo = require __DIR__ . '/../config/database.php';

// get inventories
$stmt = $pdo->query('SELECT * FROM inventories WHERE deleted_at IS NULL ORDER BY id DESC');
$items = $stmt->fetchAll();

$msg = $_GET['msg'] ?? '';
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
                if ($stockPercent > 50) {
                    $stockClass = 'success';
                } elseif ($stockPercent > 20) {
                    $stockClass = 'warning';
                } else {
                    $stockClass = 'danger';
                }
                ?>
                <span class="stock-badge <?= $stockClass ?>">
                    <?= $it['stock_available'] ?>/<?= $it['stock_total'] ?> <?= htmlspecialchars($it['unit'] ?? 'unit') ?>
                </span>
            </div>
            
            <div class="card-body" style="padding: 20px;">
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
</style>
