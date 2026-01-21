<?php
// app/user/dashboard.php - Modern Style
$pageTitle = 'Dashboard';
$pdo = require __DIR__ . '/../config/database.php';
$userId = $_SESSION['user']['id'] ?? 0;

// counts
$totalAvailable = $pdo->query('SELECT COUNT(*) FROM inventories WHERE stock_available > 0 AND deleted_at IS NULL')->fetchColumn();
$myPending = $pdo->prepare('SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = "pending"');
$myPending->execute([$userId]);
$myPendingCount = $myPending->fetchColumn();

// Count loans that are fully approved
$myApproved = $pdo->prepare('SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = "approved" AND stage = "approved" AND (return_stage IS NULL OR return_stage = "none")');
$myApproved->execute([$userId]);
$myApprovedCount = $myApproved->fetchColumn();

// Get loans awaiting document upload
$awaitingDoc = $pdo->prepare('SELECT l.*, i.name as inventory_name FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.user_id = ? AND l.stage = "awaiting_document"');
$awaitingDoc->execute([$userId]);
$awaitingDocLoans = $awaitingDoc->fetchAll();

// Get loans awaiting return document upload
$awaitingReturnDoc = $pdo->prepare('SELECT l.*, i.name as inventory_name FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.user_id = ? AND l.return_stage = "awaiting_return_doc"');
$awaitingReturnDoc->execute([$userId]);
$awaitingReturnDocLoans = $awaitingReturnDoc->fetchAll();

// Get featured items
$featuredItems = $pdo->query('SELECT * FROM inventories WHERE stock_available > 0 AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 6')->fetchAll();

// My recent loans
$myRecentLoans = $pdo->prepare('
    SELECT l.*, i.name as inventory_name, i.code as inventory_code 
    FROM loans l 
    JOIN inventories i ON i.id = l.inventory_id 
    WHERE l.user_id = ? 
    ORDER BY l.requested_at DESC 
    LIMIT 5
');
$myRecentLoans->execute([$userId]);
$recentLoans = $myRecentLoans->fetchAll();

// Top borrowed items (for chart)
$topBorrowed = $pdo->query("
  SELECT i.name, COUNT(l.id) as borrow_count
  FROM loans l
  JOIN inventories i ON i.id = l.inventory_id
  GROUP BY l.inventory_id
  ORDER BY borrow_count DESC
  LIMIT 5
")->fetchAll();

$chartLabels = [];
$chartData = [];
foreach ($topBorrowed as $item) {
    $chartLabels[] = $item['name'];
    $chartData[] = (int)$item['borrow_count'];
}
?>

<!-- Alerts for Awaiting Document -->
<?php foreach($awaitingDocLoans as $loan): ?>
<div class="alert alert-info alert-dismissible fade show" style="background: linear-gradient(135deg, var(--primary-light), var(--accent)); color: #fff; border: none;">
    <i class="bi bi-file-earmark-check me-2"></i>
    <strong>Pengajuan #<?= $loan['id'] ?> (<?= htmlspecialchars($loan['inventory_name']) ?>) telah disetujui!</strong> 
    <a href="/index.php?page=upload_document&loan_id=<?= $loan['id'] ?>" style="color: #fff; font-weight: bold; text-decoration: underline;">
        Download template & upload dokumen
    </a>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<?php foreach($awaitingReturnDocLoans as $loan): ?>
<div class="alert alert-warning alert-dismissible fade show" style="border: none;">
    <i class="bi bi-box-arrow-in-left me-2"></i>
    <strong>Pengembalian #<?= $loan['id'] ?> (<?= htmlspecialchars($loan['inventory_name']) ?>) perlu dokumen!</strong> 
    <a href="/index.php?page=upload_return_document&loan_id=<?= $loan['id'] ?>" style="color: inherit; font-weight: bold; text-decoration: underline;">
        Upload dokumen pengembalian
    </a>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-grid-1x2-fill"></i> Dashboard Karyawan</h3>
        <p>Selamat datang kembali, <?= htmlspecialchars($_SESSION['user']['name']) ?>!</p>
    </div>
    <div class="page-header-actions">
        <a class="btn btn-primary" href="/index.php?page=user_request_loan">
            <i class="bi bi-plus-lg me-1"></i> Ajukan Peminjaman
        </a>
        <a class="btn btn-secondary" href="/index.php?page=history">
            <i class="bi bi-clock-history me-1"></i> Riwayat
        </a>
    </div>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <p class="stat-card-title">Barang Tersedia</p>
                <p class="stat-card-period">Dapat Dipinjam</p>
            </div>
            <div class="stat-card-icon primary">
                <i class="bi bi-box-seam"></i>
            </div>
        </div>
        <p class="stat-card-value"><?= number_format($totalAvailable) ?></p>
        <a href="/index.php?page=catalog" class="stat-card-btn">
            <i class="bi bi-eye"></i> Lihat Katalog
        </a>
    </div>
    
    <div class="stat-card warning">
        <div class="stat-card-header">
            <div>
                <p class="stat-card-title">Menunggu Approval</p>
                <p class="stat-card-period">Pengajuan Pending</p>
            </div>
            <div class="stat-card-icon warning">
                <i class="bi bi-hourglass-split"></i>
            </div>
        </div>
        <p class="stat-card-value"><?= number_format($myPendingCount) ?></p>
        <a href="/index.php?page=history" class="stat-card-btn">
            <i class="bi bi-eye"></i> Lihat Riwayat
        </a>
    </div>
    
    <div class="stat-card success">
        <div class="stat-card-header">
            <div>
                <p class="stat-card-title">Peminjaman Aktif</p>
                <p class="stat-card-period">Sedang Dipinjam</p>
            </div>
            <div class="stat-card-icon success">
                <i class="bi bi-check-circle"></i>
            </div>
        </div>
        <p class="stat-card-value"><?= number_format($myApprovedCount) ?></p>
        <a href="/index.php?page=history" class="stat-card-btn">
            <i class="bi bi-eye"></i> Lihat Detail
        </a>
    </div>
</div>

<!-- Content Grid -->
<div class="content-grid">
    <!-- Recent Loans -->
    <div class="table-card">
        <div class="card-header" style="padding: 20px 24px; border-bottom: 1px solid var(--border-color);">
            <h3 class="card-title" style="margin: 0;">
                <i class="bi bi-clock-history"></i> Peminjaman Terakhir
            </h3>
            <a href="/index.php?page=history" class="btn btn-secondary btn-sm">Lihat Semua</a>
        </div>
        
        <?php if (empty($recentLoans)): ?>
        <div class="card-body">
            <div class="empty-state" style="padding: 40px;">
                <div class="empty-state-icon" style="width: 60px; height: 60px; font-size: 24px;">
                    <i class="bi bi-inbox"></i>
                </div>
                <h5 class="empty-state-title">Belum Ada Peminjaman</h5>
                <p class="empty-state-text mb-0">Mulai ajukan peminjaman pertama Anda</p>
                <a href="/index.php?page=user_request_loan" class="btn btn-primary mt-3">
                    <i class="bi bi-plus-lg me-1"></i> Ajukan Peminjaman
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Barang</th>
                        <th>Qty</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($recentLoans as $loan): ?>
                    <tr>
                        <td>
                            <div>
                                <div style="font-weight: 500;"><?= htmlspecialchars($loan['inventory_name']) ?></div>
                                <small style="color: var(--text-muted);"><?= htmlspecialchars($loan['inventory_code']) ?></small>
                            </div>
                        </td>
                        <td><strong><?= $loan['quantity'] ?></strong></td>
                        <td><small><?= date('d M Y', strtotime($loan['requested_at'])) ?></small></td>
                        <td>
                            <?php if($loan['stage'] === 'pending'): ?>
                                <span class="status-badge warning">Pending</span>
                            <?php elseif($loan['stage'] === 'awaiting_document'): ?>
                                <span class="status-badge info">Upload Dokumen</span>
                            <?php elseif($loan['stage'] === 'submitted'): ?>
                                <span class="status-badge info">Menunggu Verifikasi</span>
                            <?php elseif($loan['stage'] === 'approved'): ?>
                                <span class="status-badge success">Disetujui</span>
                            <?php elseif($loan['stage'] === 'rejected'): ?>
                                <span class="status-badge danger">Ditolak</span>
                            <?php else: ?>
                                <span class="status-badge secondary"><?= htmlspecialchars($loan['stage']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Featured Items -->
    <div class="modern-card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="bi bi-star-fill"></i> Barang Terbaru
            </h3>
            <a href="/index.php?page=catalog" style="color: var(--primary-light); font-size: 14px;">Lihat Semua</a>
        </div>
        <div class="card-body">
            <?php if (empty($featuredItems)): ?>
            <div class="empty-state" style="padding: 40px 20px;">
                <div class="empty-state-icon" style="width: 60px; height: 60px; font-size: 24px;">
                    <i class="bi bi-box-seam"></i>
                </div>
                <h5 class="empty-state-title">Belum Ada Barang</h5>
                <p class="empty-state-text mb-0">Barang akan muncul di sini</p>
            </div>
            <?php else: ?>
            <?php foreach(array_slice($featuredItems, 0, 5) as $item): ?>
            <div class="product-item">
                <?php if ($item['image']): ?>
                <img src="/public/assets/uploads/<?= htmlspecialchars($item['image']) ?>" 
                     alt="<?= htmlspecialchars($item['name']) ?>" 
                     class="product-img"
                     style="object-fit: cover;">
                <?php else: ?>
                <div class="product-img">
                    <i class="bi bi-box-seam" style="color: var(--primary-light);"></i>
                </div>
                <?php endif; ?>
                <div class="product-info">
                    <p class="product-name"><?= htmlspecialchars($item['name']) ?></p>
                    <p class="product-category"><?= htmlspecialchars($item['code']) ?></p>
                </div>
                <div class="product-stock">
                    <p class="product-stock-value" style="color: var(--success);"><?= $item['stock_available'] ?></p>
                    <p class="product-stock-label">tersedia</p>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Chart Section -->
<?php if (!empty($chartLabels)): ?>
<div class="content-grid full" style="margin-top: 24px;">
    <div class="modern-card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="bi bi-bar-chart-fill"></i> Barang Paling Sering Dipinjam
            </h3>
        </div>
        <div class="card-body">
            <div class="chart-container" style="height: 250px;">
                <canvas id="topBorrowedChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
const chartLabels = <?= json_encode($chartLabels) ?>;
const chartData = <?= json_encode($chartData) ?>;
</script>
<?php endif; ?>
