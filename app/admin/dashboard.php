<?php
// app/admin/dashboard.php
// Modern Admin Dashboard with Teal/Cyan Theme
$pageTitle = 'Dashboard';
$pdo = require __DIR__ . '/../config/database.php';

// Quick stats
$totalItems = $pdo->query('SELECT COUNT(*) FROM inventories WHERE deleted_at IS NULL')->fetchColumn();
$lowStock = $pdo->query('SELECT COUNT(*) FROM inventories WHERE stock_available <= 2 AND deleted_at IS NULL')->fetchColumn();
$totalPendingLoans = $pdo->query("SELECT COUNT(*) FROM loans WHERE status = 'pending'")->fetchColumn();
$totalPendingReturns = $pdo->query("SELECT COUNT(*) FROM loans WHERE return_stage = 'pending_return' OR return_stage = 'return_submitted'")->fetchColumn();
$totalUsers = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalLoans = $pdo->query('SELECT COUNT(*) FROM loans')->fetchColumn();

// Recent loans
$stmt = $pdo->query("
  SELECT l.*, u.name AS user_name, i.name AS inventory_name
  FROM loans l
  JOIN users u ON u.id = l.user_id
  JOIN inventories i ON i.id = l.inventory_id
  ORDER BY l.requested_at DESC
  LIMIT 5
");
$recentLoans = $stmt->fetchAll();

// Top borrowed items (for chart)
$topBorrowed = $pdo->query("
  SELECT i.name, COUNT(l.id) as borrow_count, SUM(l.quantity) as total_qty
  FROM loans l
  JOIN inventories i ON i.id = l.inventory_id
  GROUP BY l.inventory_id
  ORDER BY borrow_count DESC
  LIMIT 7
")->fetchAll();

$chartLabels = [];
$chartData = [];
foreach ($topBorrowed as $item) {
    $chartLabels[] = $item['name'];
    $chartData[] = (int)$item['borrow_count'];
}

// Low stock items
$lowStockItems = $pdo->query("
  SELECT name, stock_available, stock_total 
  FROM inventories 
  WHERE stock_available <= 5 AND deleted_at IS NULL 
  ORDER BY stock_available ASC 
  LIMIT 5
")->fetchAll();
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-grid-1x2-fill"></i> Dashboard Admin</h3>
        <p>Selamat datang kembali, <?= htmlspecialchars($_SESSION['user']['name']) ?>! Berikut ringkasan inventaris hari ini.</p>
    </div>
    <div class="page-header-actions">
        <a href="/index.php?page=admin_inventory_list" class="btn btn-primary">
            <i class="bi bi-box-seam me-1"></i> Kelola Inventaris
        </a>
        <a href="/index.php?page=admin_loans" class="btn btn-accent">
            <i class="bi bi-clipboard-check me-1"></i> Peminjaman
        </a>
    </div>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <p class="stat-card-title">Total Barang</p>
                <p class="stat-card-period">Semua Kategori</p>
            </div>
            <div class="stat-card-icon primary">
                <i class="bi bi-box-seam"></i>
            </div>
        </div>
        <p class="stat-card-value"><?= number_format($totalItems) ?></p>
        <div class="stat-card-footer">
            <span class="stat-card-change up">
                <i class="bi bi-arrow-up"></i> Aktif
            </span>
            <span class="stat-card-compare">item tersedia</span>
        </div>
        <a href="/index.php?page=admin_inventory_list" class="stat-card-btn">
            <i class="bi bi-eye"></i> Lihat Detail
        </a>
    </div>
    
    <div class="stat-card warning">
        <div class="stat-card-header">
            <div>
                <p class="stat-card-title">Menunggu Approval</p>
                <p class="stat-card-period">Peminjaman Pending</p>
            </div>
            <div class="stat-card-icon warning">
                <i class="bi bi-hourglass-split"></i>
            </div>
        </div>
        <p class="stat-card-value"><?= number_format($totalPendingLoans) ?></p>
        <div class="stat-card-footer">
            <span class="stat-card-change <?= $totalPendingLoans > 0 ? 'down' : 'up' ?>">
                <i class="bi bi-<?= $totalPendingLoans > 0 ? 'exclamation' : 'check' ?>"></i> 
                <?= $totalPendingLoans > 0 ? 'Perlu Tindakan' : 'Semua Selesai' ?>
            </span>
        </div>
        <a href="/index.php?page=admin_loans" class="stat-card-btn">
            <i class="bi bi-eye"></i> Proses Sekarang
        </a>
    </div>
    
    <div class="stat-card danger">
        <div class="stat-card-header">
            <div>
                <p class="stat-card-title">Stok Menipis</p>
                <p class="stat-card-period">Perlu Restock</p>
            </div>
            <div class="stat-card-icon danger">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
        </div>
        <p class="stat-card-value"><?= number_format($lowStock) ?></p>
        <div class="stat-card-footer">
            <span class="stat-card-change <?= $lowStock > 0 ? 'down' : 'up' ?>">
                <i class="bi bi-<?= $lowStock > 0 ? 'arrow-down' : 'check' ?>"></i> 
                <?= $lowStock > 0 ? 'Segera Restock' : 'Stok Aman' ?>
            </span>
        </div>
        <a href="/index.php?page=admin_inventory_list" class="stat-card-btn">
            <i class="bi bi-eye"></i> Lihat Detail
        </a>
    </div>
    
    <div class="stat-card info">
        <div class="stat-card-header">
            <div>
                <p class="stat-card-title">Pengembalian</p>
                <p class="stat-card-period">Menunggu Proses</p>
            </div>
            <div class="stat-card-icon info">
                <i class="bi bi-box-arrow-in-left"></i>
            </div>
        </div>
        <p class="stat-card-value"><?= number_format($totalPendingReturns) ?></p>
        <div class="stat-card-footer">
            <span class="stat-card-change <?= $totalPendingReturns > 0 ? 'down' : 'up' ?>">
                <i class="bi bi-<?= $totalPendingReturns > 0 ? 'clock' : 'check' ?>"></i> 
                <?= $totalPendingReturns > 0 ? 'Perlu Verifikasi' : 'Semua Selesai' ?>
            </span>
        </div>
        <a href="/index.php?page=admin_returns" class="stat-card-btn">
            <i class="bi bi-eye"></i> Lihat Detail
        </a>
    </div>
</div>

<!-- Charts Row -->
<div class="content-grid">
    <!-- Chart Card -->
    <div class="modern-card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="bi bi-bar-chart-fill"></i> Barang Paling Sering Dipinjam
            </h3>
            <div class="card-actions">
                <button class="card-tab-btn active">Minggu Ini</button>
                <button class="card-tab-btn">Bulan Ini</button>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($topBorrowed)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="bi bi-bar-chart"></i>
                </div>
                <h5 class="empty-state-title">Belum Ada Data</h5>
                <p class="empty-state-text">Data peminjaman akan muncul di sini</p>
            </div>
            <?php else: ?>
            <div class="chart-container">
                <canvas id="topBorrowedChart"></canvas>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Info Card -->
    <div class="modern-card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="bi bi-pie-chart-fill"></i> Statistik Cepat
            </h3>
            <button class="card-menu-btn">
                <i class="bi bi-three-dots-vertical"></i>
            </button>
        </div>
        <div class="card-body">
            <div class="info-stat-main">
                <p class="info-stat-value"><?= number_format($totalUsers) ?></p>
                <p class="info-stat-label">Total Pengguna Terdaftar</p>
            </div>
            <ul class="info-list">
                <li class="info-list-item">
                    <div class="info-list-left">
                        <div class="info-list-icon" style="background: rgba(26, 154, 170, 0.1); color: var(--primary-light);">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <span class="info-list-text">Barang Tersedia</span>
                    </div>
                    <span class="info-list-value"><?= number_format($totalItems) ?></span>
                </li>
                <li class="info-list-item">
                    <div class="info-list-left">
                        <div class="info-list-icon" style="background: var(--success-light); color: var(--success);">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <span class="info-list-text">Total Peminjaman</span>
                    </div>
                    <span class="info-list-value"><?= number_format($totalLoans) ?></span>
                </li>
                <li class="info-list-item">
                    <div class="info-list-left">
                        <div class="info-list-icon" style="background: var(--warning-light); color: var(--warning);">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <span class="info-list-text">Pending Approval</span>
                    </div>
                    <span class="info-list-value"><?= number_format($totalPendingLoans) ?></span>
                </li>
                <li class="info-list-item">
                    <div class="info-list-left">
                        <div class="info-list-icon" style="background: var(--danger-light); color: var(--danger);">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <span class="info-list-text">Stok Rendah</span>
                    </div>
                    <span class="info-list-value"><?= number_format($lowStock) ?></span>
                </li>
            </ul>
            <a href="/index.php?page=admin_users_list" class="info-btn">
                <i class="bi bi-people"></i> Kelola Pengguna
            </a>
        </div>
    </div>
</div>

<!-- Table & Products Row -->
<div class="content-grid">
    <!-- Recent Loans Table -->
    <div class="table-card">
        <div class="card-header" style="padding: 20px 24px; border-bottom: 1px solid var(--border-color);">
            <h3 class="card-title" style="margin: 0;">
                <i class="bi bi-clock-history"></i> Peminjaman Terbaru
            </h3>
            <a href="/index.php?page=admin_loans" class="btn btn-secondary btn-sm">
                Lihat Semua
            </a>
        </div>
        <div style="padding: 20px 24px 0;">
            <div class="table-filters">
                <button class="table-filter-btn active">Semua</button>
                <button class="table-filter-btn">Pending</button>
                <button class="table-filter-btn">Disetujui</button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Peminjam</th>
                        <th>Barang</th>
                        <th>Jumlah</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($recentLoans)): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state" style="padding: 40px;">
                                <div class="empty-state-icon" style="width: 60px; height: 60px; font-size: 24px;">
                                    <i class="bi bi-inbox"></i>
                                </div>
                                <h5 class="empty-state-title">Belum Ada Peminjaman</h5>
                                <p class="empty-state-text mb-0">Data peminjaman akan muncul di sini</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach($recentLoans as $l): ?>
                    <tr>
                        <td><span class="badge bg-secondary">#<?= $l['id'] ?></span></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="topbar-avatar" style="width: 32px; height: 32px; font-size: 12px;">
                                    <?= strtoupper(substr($l['user_name'], 0, 1)) ?>
                                </div>
                                <?= htmlspecialchars($l['user_name']) ?>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($l['inventory_name']) ?></td>
                        <td><strong><?= $l['quantity'] ?></strong></td>
                        <td><small><?= date('d M Y', strtotime($l['requested_at'])) ?></small></td>
                        <td>
                            <?php if($l['status'] === 'pending'): ?>
                                <span class="status-badge warning">Pending</span>
                            <?php elseif($l['status'] === 'approved'): ?>
                                <span class="status-badge success">Disetujui</span>
                            <?php elseif($l['status'] === 'rejected'): ?>
                                <span class="status-badge danger">Ditolak</span>
                            <?php elseif($l['status'] === 'returned'): ?>
                                <span class="status-badge info">Dikembalikan</span>
                            <?php else: ?>
                                <span class="status-badge secondary"><?= htmlspecialchars($l['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($l['status'] === 'pending' && $l['stage'] === 'pending'): ?>
                            <div class="table-actions">
                                <form method="POST" action="/index.php?page=loan_approve" style="display:inline;">
                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                    <button type="submit" class="table-action-btn success" title="Setujui" onclick="return confirm('Approve peminjaman ini?')">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <form method="POST" action="/index.php?page=loan_reject" style="display:inline;">
                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                    <button type="submit" class="table-action-btn danger" title="Tolak" onclick="return confirm('Reject peminjaman ini?')">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Low Stock Items -->
    <div class="modern-card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="bi bi-exclamation-triangle-fill"></i> Stok Menipis
            </h3>
            <a href="/index.php?page=admin_inventory_list" style="color: var(--primary-light); font-size: 14px;">Lihat Semua</a>
        </div>
        <div class="card-body">
            <?php if (empty($lowStockItems)): ?>
            <div class="empty-state" style="padding: 40px 20px;">
                <div class="empty-state-icon" style="width: 60px; height: 60px; font-size: 24px;">
                    <i class="bi bi-check-circle"></i>
                </div>
                <h5 class="empty-state-title">Stok Aman</h5>
                <p class="empty-state-text mb-0">Semua barang memiliki stok cukup</p>
            </div>
            <?php else: ?>
            <?php foreach($lowStockItems as $item): ?>
            <div class="product-item">
                <div class="product-img" style="background: var(--danger-light);">
                    <i class="bi bi-box-seam" style="color: var(--danger);"></i>
                </div>
                <div class="product-info">
                    <p class="product-name"><?= htmlspecialchars($item['name']) ?></p>
                    <p class="product-category">Stok Total: <?= $item['stock_total'] ?></p>
                </div>
                <div class="product-stock">
                    <p class="product-stock-value" style="color: var(--danger);"><?= $item['stock_available'] ?></p>
                    <p class="product-stock-label">tersedia</p>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($topBorrowed)): ?>
<script>
// Chart data for top borrowed items
const chartLabels = <?= json_encode($chartLabels) ?>;
const chartData = <?= json_encode($chartData) ?>;
</script>
<?php endif; ?>
