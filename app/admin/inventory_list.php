<?php
$pdo = require __DIR__ . '/../config/database.php';

// get inventories
$stmt = $pdo->query('SELECT * FROM inventories WHERE deleted_at IS NULL ORDER BY id DESC');
$items = $stmt->fetchAll();

$msg = $_GET['msg'] ?? '';
?>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1"><i class="bi bi-box-seam me-2"></i>Kelola Inventaris</h3>
        <p class="text-secondary mb-0">Total <?= count($items) ?> barang dalam inventaris</p>
    </div>
    <a class="btn btn-warning" href="/index.php?page=admin_inventory_add">
        <i class="bi bi-plus-lg me-1"></i> Tambah Barang
    </a>
</div>

<?php if ($msg === 'updated'): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Barang berhasil diperbarui.</div>
<?php elseif ($msg === 'added'): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Barang berhasil ditambahkan.</div>
<?php endif; ?>

<!-- Inventory Cards Grid -->
<div class="row g-4">
    <?php if (empty($items)): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-inbox text-secondary" style="font-size: 4rem;"></i>
                    <h5 class="mt-3 text-secondary">Belum Ada Barang</h5>
                    <p class="text-secondary">Mulai dengan menambahkan barang pertama ke inventaris.</p>
                    <a href="/index.php?page=admin_inventory_add" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i> Tambah Barang
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach($items as $it): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 inventory-card">
                    <!-- Image -->
                    <div class="inventory-image-container">
                        <?php if ($it['image']): ?>
                            <img src="/public/assets/uploads/<?= htmlspecialchars($it['image']) ?>" 
                                 alt="<?= htmlspecialchars($it['name']) ?>" 
                                 class="card-img-top inventory-image">
                        <?php else: ?>
                            <div class="no-image-placeholder">
                                <i class="bi bi-box-seam"></i>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Stock Badge -->
                        <?php 
                        $stockPercent = $it['stock_total'] > 0 ? ($it['stock_available'] / $it['stock_total']) * 100 : 0;
                        $stockClass = $stockPercent > 50 ? 'bg-success' : ($stockPercent > 20 ? 'bg-warning' : 'bg-danger');
                        ?>
                        <span class="stock-badge <?= $stockClass ?>">
                            <?= $it['stock_available'] ?>/<?= $it['stock_total'] ?> <?= htmlspecialchars($it['unit'] ?? 'unit') ?>
                        </span>
                    </div>
                    
                    <div class="card-body">
                        <h5 class="card-title text-pln-yellow mb-1"><?= htmlspecialchars($it['name']) ?></h5>
                        <p class="text-secondary small mb-2">
                            <i class="bi bi-upc-scan me-1"></i><?= htmlspecialchars($it['code']) ?>
                        </p>
                        
                        <?php if ($it['description']): ?>
                            <p class="card-text small text-secondary mb-3" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                <?= htmlspecialchars($it['description']) ?>
                            </p>
                        <?php endif; ?>
                        
                        <!-- Stock Info -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>Ketersediaan Stok</span>
                                <span class="fw-bold"><?= round($stockPercent) ?>%</span>
                            </div>
                            <div class="progress" style="height: 6px; background: rgba(255,255,255,0.1);">
                                <div class="progress-bar <?= $stockClass ?>" style="width: <?= $stockPercent ?>%"></div>
                            </div>
                        </div>
                        
                        <!-- Stats Row -->
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <div class="stat-box">
                                    <small class="text-secondary">Total</small>
                                    <div class="fw-bold text-pln-yellow"><?= $it['stock_total'] ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box">
                                    <small class="text-secondary">Tersedia</small>
                                    <div class="fw-bold text-pln-yellow"><?= $it['stock_available'] ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-footer bg-transparent border-top" style="border-color: rgba(255,255,255,0.1) !important;">
                        <div class="d-flex gap-2">
                            <a href="/index.php?page=admin_inventory_edit&id=<?= $it['id'] ?>" class="btn btn-sm btn-primary flex-fill">
                                <i class="bi bi-pencil me-1"></i> Edit
                            </a>
                            <a href="/index.php?page=admin_inventory_delete&id=<?= $it['id'] ?>" 
                               class="btn btn-sm btn-outline-danger"
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
    box-shadow: 0 10px 30px rgba(15, 117, 188, 0.3);
}
.inventory-image-container {
    position: relative;
    height: 200px;
    overflow: hidden;
    background: linear-gradient(135deg, #0D1E36 0%, #122640 100%);
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
    color: rgba(255,255,255,0.1);
}
.stock-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}
.stat-box {
    background: rgba(15, 117, 188, 0.1);
    padding: 8px 12px;
    border-radius: 8px;
    text-align: center;
}
</style>
