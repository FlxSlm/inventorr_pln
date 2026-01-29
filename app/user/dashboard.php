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

// Get unread notifications count
$unreadNotifStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadNotifStmt->execute([$userId]);
$unreadNotifCount = $unreadNotifStmt->fetchColumn();

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

// Top borrowed items (for chart) - Only count FINAL approved loans
$topBorrowed = $pdo->query("
  SELECT i.name, COUNT(l.id) as borrow_count
  FROM loans l
  JOIN inventories i ON i.id = l.inventory_id
  WHERE l.stage = 'approved'
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

// Top requested items (for chart) - Only count FINAL approved requests
$topRequested = $pdo->query("
  SELECT i.name, COUNT(r.id) as request_count
  FROM requests r
  JOIN inventories i ON i.id = r.inventory_id
  WHERE r.stage = 'approved'
  GROUP BY r.inventory_id
  ORDER BY request_count DESC
  LIMIT 5
")->fetchAll();

$requestChartLabels = [];
$requestChartData = [];
foreach ($topRequested as $item) {
    $requestChartLabels[] = $item['name'];
    $requestChartData[] = (int)$item['request_count'];
}

?>

<!-- Notification Badge Alert -->
<?php if ($unreadNotifCount > 0): ?>
<a href="/index.php?page=user_notifications" class="d-block text-decoration-none mb-3">
    <div style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); color: #fff; border-radius: var(--radius); padding: 12px 20px; display: flex; align-items: center; justify-content: space-between;">
        <div class="d-flex align-items-center">
            <div style="width: 36px; height: 36px; background: rgba(255,255,255,0.2); border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                <i class="bi bi-bell-fill"></i>
            </div>
            <span>Anda memiliki <strong><?= $unreadNotifCount ?></strong> notifikasi baru</span>
        </div>
        <i class="bi bi-chevron-right"></i>
    </div>
</a>
<?php endif; ?>

<!-- Alerts for Awaiting Document -->
<?php foreach($awaitingDocLoans as $loan): ?>
<div class="alert alert-dismissible fade show mb-3" style="background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: #fff; border: none; border-radius: var(--radius); padding: 16px 20px; box-shadow: 0 4px 12px rgba(13, 148, 136, 0.3);">
    <div class="d-flex align-items-center">
        <div style="width: 40px; height: 40px; background: rgba(255,255,255,0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-right: 16px;">
            <i class="bi bi-file-earmark-check" style="font-size: 20px;"></i>
        </div>
        <div class="flex-grow-1">
            <strong style="font-size: 15px;">Pengajuan #<?= $loan['id'] ?> (<?= htmlspecialchars($loan['inventory_name']) ?>) telah disetujui!</strong>
            <div style="margin-top: 4px;">
                <a href="/index.php?page=upload_document&loan_id=<?= $loan['id'] ?>" style="color: #fff; font-weight: 600; text-decoration: none; background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 6px; font-size: 13px;">
                    <i class="bi bi-upload me-1"></i>Download template & upload dokumen
                </a>
            </div>
        </div>
    </div>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" style="position: absolute; top: 12px; right: 12px;"></button>
</div>
<?php endforeach; ?>

<?php foreach($awaitingReturnDocLoans as $loan): ?>
<div class="alert alert-dismissible fade show mb-3" style="background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%); color: #1f2937; border: none; border-radius: var(--radius); padding: 16px 20px; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);">
    <div class="d-flex align-items-center">
        <div style="width: 40px; height: 40px; background: rgba(255,255,255,0.3); border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-right: 16px;">
            <i class="bi bi-box-arrow-in-left" style="font-size: 20px;"></i>
        </div>
        <div class="flex-grow-1">
            <strong style="font-size: 15px;">Pengembalian #<?= $loan['id'] ?> (<?= htmlspecialchars($loan['inventory_name']) ?>) perlu dokumen!</strong>
            <div style="margin-top: 4px;">
                <a href="/index.php?page=upload_return_document&loan_id=<?= $loan['id'] ?>" style="color: #1f2937; font-weight: 600; text-decoration: none; background: rgba(255,255,255,0.4); padding: 4px 12px; border-radius: 6px; font-size: 13px;">
                    <i class="bi bi-upload me-1"></i>Upload dokumen pengembalian
                </a>
            </div>
        </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" style="position: absolute; top: 12px; right: 12px;"></button>
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
<div class="charts-vertical" style="margin-top: 24px;">
    <?php if (!empty($chartLabels)): ?>
    <div class="modern-card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="bi bi-bar-chart-fill"></i> Barang Paling Sering Dipinjam
            </h3>
            <div class="card-actions">
                <button class="chart-type-btn active" data-chart="borrow" data-type="bar" title="Bar Chart">
                    <i class="bi bi-bar-chart"></i>
                </button>
                <button class="chart-type-btn" data-chart="borrow" data-type="doughnut" title="Doughnut Chart">
                    <i class="bi bi-pie-chart"></i>
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-container" style="height: 300px;">
                <canvas id="topBorrowedChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($requestChartLabels)): ?>
    <div class="modern-card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="bi bi-cart-check-fill"></i> Barang Paling Sering Diminta
            </h3>
            <div class="card-actions">
                <button class="chart-type-btn active" data-chart="request" data-type="bar" title="Bar Chart">
                    <i class="bi bi-bar-chart"></i>
                </button>
                <button class="chart-type-btn" data-chart="request" data-type="doughnut" title="Doughnut Chart">
                    <i class="bi bi-pie-chart"></i>
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-container" style="height: 300px;">
                <canvas id="topRequestedChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>


<script>
const chartLabels = <?= json_encode($chartLabels) ?>;
const chartData = <?= json_encode($chartData) ?>;
const requestChartLabels = <?= json_encode($requestChartLabels) ?>;
const requestChartData = <?= json_encode($requestChartData) ?>;

// Chart colors
const chartColors = [
    'rgba(10, 107, 124, 0.85)',
    'rgba(26, 154, 170, 0.85)',
    'rgba(45, 212, 191, 0.85)',
    'rgba(20, 184, 166, 0.85)',
    'rgba(13, 148, 136, 0.85)',
    'rgba(6, 95, 70, 0.85)',
    'rgba(4, 120, 87, 0.85)'
];

const chartBorderColors = [
    'rgba(10, 107, 124, 1)',
    'rgba(26, 154, 170, 1)',
    'rgba(45, 212, 191, 1)',
    'rgba(20, 184, 166, 1)',
    'rgba(13, 148, 136, 1)',
    'rgba(6, 95, 70, 1)',
    'rgba(4, 120, 87, 1)'
];

let topBorrowedChart = null;
let topRequestedChart = null;

function createChart(chartId, labels, data, type = 'bar') {
    const ctx = document.getElementById(chartId);
    if (!ctx) return null;
    
    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: type !== 'bar',
                position: 'right',
                labels: {
                    padding: 15,
                    usePointStyle: true,
                    pointStyle: 'circle',
                    font: {
                        size: 11,
                        family: "'Inter', sans-serif"
                    }
                }
            },
            tooltip: {
                backgroundColor: 'rgba(13, 79, 92, 0.95)',
                padding: 12,
                titleFont: { size: 13, weight: '600' },
                bodyFont: { size: 12 },
                borderColor: 'rgba(45, 212, 191, 0.3)',
                borderWidth: 1,
                cornerRadius: 8,
                displayColors: true,
                callbacks: {
                    label: function(context) {
                        const labelText = chartId.includes('Borrowed') ? 'kali dipinjam' : 'kali diminta';
                        return ` ${context.parsed.r || context.parsed.y || context.parsed} ${labelText}`;
                    }
                }
            }
        }
    };
    
    let config = {
        type: type,
        data: {
            labels: labels,
            datasets: [{
                label: chartId.includes('Borrowed') ? 'Jumlah Peminjaman' : 'Jumlah Permintaan',
                data: data,
                backgroundColor: type === 'bar' ? 'rgba(26, 154, 170, 0.85)' : chartColors,
                borderColor: type === 'bar' ? 'rgba(10, 107, 124, 1)' : chartBorderColors,
                borderWidth: type === 'bar' ? 0 : 2,
                borderRadius: type === 'bar' ? 8 : 0,
                hoverBackgroundColor: type === 'bar' ? 'rgba(45, 212, 191, 0.95)' : undefined
            }]
        },
        options: commonOptions
    };
    
    // Type specific options
    if (type === 'bar') {
        config.options.scales = {
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(0, 0, 0, 0.05)', drawBorder: false },
                ticks: { font: { size: 11 } }
            },
            x: {
                grid: { display: false },
                ticks: { font: { size: 11 } }
            }
        };
        config.options.plugins.legend.display = false;
    } else if (type === 'doughnut') {
        config.options.cutout = '60%';
    }
    
    return new Chart(ctx, config);
}

// Initialize charts when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    if (chartLabels.length > 0) {
        topBorrowedChart = createChart('topBorrowedChart', chartLabels, chartData, 'bar');
    }
    if (requestChartLabels.length > 0) {
        topRequestedChart = createChart('topRequestedChart', requestChartLabels, requestChartData, 'bar');
    }
    
    // Chart type toggle buttons
    document.querySelectorAll('.chart-type-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const chartType = this.dataset.chart;
            const type = this.dataset.type;
            
            // Toggle active class within same chart group
            this.closest('.card-actions').querySelectorAll('.chart-type-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            if (chartType === 'borrow') {
                if (topBorrowedChart) topBorrowedChart.destroy();
                topBorrowedChart = createChart('topBorrowedChart', chartLabels, chartData, type);
            } else if (chartType === 'request') {
                if (topRequestedChart) topRequestedChart.destroy();
                topRequestedChart = createChart('topRequestedChart', requestChartLabels, requestChartData, type);
            }
        });
    });
});
</script>
