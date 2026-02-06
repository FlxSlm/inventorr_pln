<?php
// app/admin/dashboard.php
// Modern Admin Dashboard with Teal/Cyan Theme
$pageTitle = 'Dashboard';
$pdo = require __DIR__ . '/../config/database.php';

// Low stock now uses per-item threshold
$lowStockThreshold = 5; // default fallback for items without threshold

// Quick stats - using per-item threshold
$totalItems = $pdo->query('SELECT COUNT(*) FROM inventories WHERE deleted_at IS NULL')->fetchColumn();
$lowStock = $pdo->query("SELECT COUNT(*) FROM inventories WHERE stock_available <= COALESCE(low_stock_threshold, 5) AND deleted_at IS NULL")->fetchColumn();
$totalPendingLoans = $pdo->query("SELECT COUNT(*) FROM loans WHERE status = 'pending'")->fetchColumn();
$totalPendingReturns = $pdo->query("SELECT COUNT(*) FROM loans WHERE return_stage = 'pending_return' OR return_stage = 'return_submitted'")->fetchColumn();
$totalUsers = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalLoans = $pdo->query('SELECT COUNT(*) FROM loans')->fetchColumn();

// Request stats
$totalPendingRequests = 0;
try {
    $totalPendingRequests = $pdo->query("SELECT COUNT(*) FROM requests WHERE stage IN ('pending', 'submitted')")->fetchColumn();
} catch (Exception $e) {}

$totalRequests = 0;
try {
    $totalRequests = $pdo->query("SELECT COUNT(*) FROM requests")->fetchColumn();
} catch (Exception $e) {}

// Recent loans - grouped by transaction (group_id)
$stmt = $pdo->query("
  SELECT 
    COALESCE(l.group_id, CONCAT('single_', l.id)) AS transaction_id,
    MIN(l.id) as first_loan_id,
    u.name AS user_name,
    u.id AS user_id,
    COUNT(*) as item_count,
    SUM(l.quantity) as total_quantity,
    GROUP_CONCAT(DISTINCT i.name SEPARATOR ', ') as item_names,
    MIN(l.requested_at) as requested_at,
    MAX(l.status) as status,
    MAX(l.stage) as stage
  FROM loans l
  JOIN users u ON u.id = l.user_id
  JOIN inventories i ON i.id = l.inventory_id
  GROUP BY COALESCE(l.group_id, CONCAT('single_', l.id)), u.id
  ORDER BY MIN(l.requested_at) DESC
  LIMIT 5
");
$recentLoans = $stmt->fetchAll();

// Recent requests - grouped by transaction (group_id)
$recentRequests = [];
try {
    $stmt = $pdo->query("
      SELECT 
        COALESCE(r.group_id, CONCAT('single_', r.id)) AS transaction_id,
        MIN(r.id) as first_request_id,
        u.name AS user_name,
        u.id AS user_id,
        COUNT(*) as item_count,
        SUM(r.quantity) as total_quantity,
        GROUP_CONCAT(DISTINCT i.name SEPARATOR ', ') as item_names,
        MIN(r.requested_at) as requested_at,
        MAX(r.stage) as stage
      FROM requests r
      JOIN users u ON u.id = r.user_id
      JOIN inventories i ON i.id = r.inventory_id
      GROUP BY COALESCE(r.group_id, CONCAT('single_', r.id)), u.id
      ORDER BY MIN(r.requested_at) DESC
      LIMIT 5
    ");
    $recentRequests = $stmt->fetchAll();
} catch (Exception $e) {}

// Top borrowed items (for chart) - Only count FINAL approved loans (stage 2 validation completed)
// Loans must have stage='approved' which only happens after admin does final approval
// Also fetch category color for each item
$topBorrowed = $pdo->query("
  SELECT i.name, i.id as inventory_id, COUNT(l.id) as borrow_count, SUM(l.quantity) as total_qty,
         (SELECT c.color FROM inventory_categories ic 
          JOIN categories c ON c.id = ic.category_id 
          WHERE ic.inventory_id = i.id LIMIT 1) as category_color
  FROM loans l
  JOIN inventories i ON i.id = l.inventory_id
  WHERE l.stage = 'approved'
  GROUP BY l.inventory_id
  ORDER BY borrow_count DESC
  LIMIT 7
")->fetchAll();

$chartLabels = [];
$chartData = [];
$chartColors = [];
foreach ($topBorrowed as $item) {
    $chartLabels[] = $item['name'];
    $chartData[] = (int)$item['borrow_count'];
    $chartColors[] = $item['category_color'] ?: '#1a9aaa'; // Default teal if no category
}

// Top requested items (permanent requests) - Only count FINAL approved requests
// Requests must have stage='approved' which only happens after admin does final approval (stage 2)
// This excludes pending, awaiting_document, and submitted stages
$topRequested = [];
try {
    $topRequested = $pdo->query("
      SELECT i.name, i.id as inventory_id, COUNT(r.id) as request_count, SUM(r.quantity) as total_qty,
             (SELECT c.color FROM inventory_categories ic 
              JOIN categories c ON c.id = ic.category_id 
              WHERE ic.inventory_id = i.id LIMIT 1) as category_color
      FROM requests r
      JOIN inventories i ON i.id = r.inventory_id
      WHERE r.stage = 'approved'
      GROUP BY r.inventory_id
      ORDER BY request_count DESC
      LIMIT 7
    ")->fetchAll();
} catch (Exception $e) {}

$requestChartLabels = [];
$requestChartData = [];
$requestChartColors = [];
foreach ($topRequested as $item) {
    $requestChartLabels[] = $item['name'];
    $requestChartData[] = (int)$item['request_count'];
    $requestChartColors[] = $item['category_color'] ?: '#f59e0b'; // Default amber if no category
}

// Low stock items - using per-item threshold
$lowStockItems = $pdo->query("
  SELECT name, stock_available, stock_total, image, COALESCE(low_stock_threshold, 5) as threshold
  FROM inventories 
  WHERE stock_available <= COALESCE(low_stock_threshold, 5) AND deleted_at IS NULL 
  ORDER BY stock_available ASC 
  LIMIT 5
")->fetchAll();

// Out of stock items (stock_available = 0)
$outOfStockItems = $pdo->query("
  SELECT id, name, stock_available, stock_total, image
  FROM inventories 
  WHERE stock_available = 0 AND deleted_at IS NULL 
  ORDER BY name ASC 
  LIMIT 5
")->fetchAll();

$totalOutOfStockItems = $pdo->query("SELECT COUNT(*) FROM inventories WHERE stock_available = 0 AND deleted_at IS NULL")->fetchColumn();
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
                <p class="stat-card-title">Stok Habis</p>
                <p class="stat-card-period">Barang Tidak Tersedia</p>
            </div>
            <div class="stat-card-icon info">
                <i class="bi bi-x-circle"></i>
            </div>
        </div>
        <p class="stat-card-value"><?= number_format($totalOutOfStockItems) ?></p>
        <div class="stat-card-footer">
            <span class="stat-card-change <?= $totalOutOfStockItems > 0 ? 'down' : 'up' ?>">
                <i class="bi bi-<?= $totalOutOfStockItems > 0 ? 'exclamation' : 'check' ?>"></i> 
                <?= $totalOutOfStockItems > 0 ? 'Perlu Restock' : 'Semua Tersedia' ?>
            </span>
        </div>
        <a href="/index.php?page=admin_inventory_list&out_of_stock=1" class="stat-card-btn">
            <i class="bi bi-eye"></i> Lihat Detail
        </a>
    </div>
</div>

<?php
// Current semester defaults
$curMonth = (int)date('n');
$curYear = (int)date('Y');
$curSem = ($curMonth <= 6) ? 1 : 2;
// Generate year options (from 2020 to current year + 1)
$yearOptions = range(2020, $curYear + 1);
?>

<!-- Charts Row - Side by Side -->
<div class="row g-4" style="margin-bottom: 24px;">
    <!-- Chart Card - Most Borrowed -->
    <div class="col-lg-6">
        <div class="modern-card h-100">
            <div class="card-header" style="flex-direction: column; align-items: stretch !important;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <h3 class="card-title" style="margin: 0;">
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
                <!-- Semester Filter -->
                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap; font-size: 12px;">
                    <select id="borrowSemStart" class="form-select form-select-sm" style="width: auto; font-size: 12px; padding: 4px 28px 4px 8px;">
                        <option value="1" <?= $curSem === 1 ? 'selected' : '' ?>>Sem 1</option>
                        <option value="2" <?= $curSem === 2 ? 'selected' : '' ?>>Sem 2</option>
                    </select>
                    <select id="borrowYearStart" class="form-select form-select-sm" style="width: auto; font-size: 12px; padding: 4px 28px 4px 8px;">
                        <?php foreach($yearOptions as $y): ?>
                        <option value="<?= $y ?>" <?= $y === $curYear ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span style="color: var(--text-muted);">s/d</span>
                    <select id="borrowSemEnd" class="form-select form-select-sm" style="width: auto; font-size: 12px; padding: 4px 28px 4px 8px;">
                        <option value="1" <?= $curSem === 1 ? 'selected' : '' ?>>Sem 1</option>
                        <option value="2" <?= $curSem === 2 ? 'selected' : '' ?>>Sem 2</option>
                    </select>
                    <select id="borrowYearEnd" class="form-select form-select-sm" style="width: auto; font-size: 12px; padding: 4px 28px 4px 8px;">
                        <?php foreach($yearOptions as $y): ?>
                        <option value="<?= $y ?>" <?= $y === $curYear ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary btn-sm" onclick="loadBorrowChart()" style="font-size: 12px; padding: 4px 10px;">
                        <i class="bi bi-funnel"></i>
                    </button>
                    <!-- Export Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" style="font-size: 12px; padding: 4px 10px;">
                            <i class="bi bi-download"></i> Ekspor
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="exportReport('borrow','pdf')"><i class="bi bi-file-pdf me-2 text-danger"></i>PDF</a></li>
                            <li><a class="dropdown-item" href="#" onclick="exportReport('borrow','word')"><i class="bi bi-file-word me-2 text-primary"></i>Word</a></li>
                            <li><a class="dropdown-item" href="#" onclick="exportReport('borrow','excel')"><i class="bi bi-file-excel me-2 text-success"></i>Excel</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="card-body" style="padding: 16px;">
                <!-- Summary (dynamic) -->
                <div id="borrowSummary" style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
                    <div style="background: var(--bg-main); padding: 10px 14px; border-radius: 8px; flex: 1; min-width: 80px;">
                        <div id="borrowTotal" style="font-size: 18px; font-weight: 700; color: var(--primary-light);"><?= array_sum($chartData) ?></div>
                        <div style="font-size: 10px; color: var(--text-muted);">Total</div>
                    </div>
                    <div style="background: var(--bg-main); padding: 10px 14px; border-radius: 8px; flex: 1; min-width: 80px;">
                        <div id="borrowTypes" style="font-size: 18px; font-weight: 700; color: var(--text-dark);"><?= count($chartLabels) ?></div>
                        <div style="font-size: 10px; color: var(--text-muted);">Jenis</div>
                    </div>
                    <div style="background: var(--bg-main); padding: 10px 14px; border-radius: 8px; flex: 2; min-width: 120px;">
                        <div id="borrowTopName" style="font-size: 13px; font-weight: 600; color: var(--success); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($chartLabels[0] ?? '-') ?></div>
                        <div id="borrowTopCount" style="font-size: 10px; color: var(--text-muted);">Terpopuler (<?= $chartData[0] ?? 0 ?>x)</div>
                    </div>
                </div>
                <div id="borrowChartWrapper">
                    <div class="chart-container" style="height: 220px;">
                        <canvas id="topBorrowedChart"></canvas>
                    </div>
                </div>
                <div id="borrowEmpty" style="display: none;">
                    <div class="empty-state" style="padding: 30px;">
                        <div class="empty-state-icon" style="width: 50px; height: 50px; font-size: 20px;"><i class="bi bi-bar-chart"></i></div>
                        <h5 class="empty-state-title" style="font-size: 14px;">Belum Ada Data</h5>
                        <p class="empty-state-text mb-0" style="font-size: 12px;">Data peminjaman akan muncul di sini</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Chart Card - Most Requested -->
    <div class="col-lg-6">
        <div class="modern-card h-100">
            <div class="card-header" style="flex-direction: column; align-items: stretch !important;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <h3 class="card-title" style="margin: 0;">
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
                <!-- Semester Filter -->
                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap; font-size: 12px;">
                    <select id="requestSemStart" class="form-select form-select-sm" style="width: auto; font-size: 12px; padding: 4px 28px 4px 8px;">
                        <option value="1" <?= $curSem === 1 ? 'selected' : '' ?>>Sem 1</option>
                        <option value="2" <?= $curSem === 2 ? 'selected' : '' ?>>Sem 2</option>
                    </select>
                    <select id="requestYearStart" class="form-select form-select-sm" style="width: auto; font-size: 12px; padding: 4px 28px 4px 8px;">
                        <?php foreach($yearOptions as $y): ?>
                        <option value="<?= $y ?>" <?= $y === $curYear ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span style="color: var(--text-muted);">s/d</span>
                    <select id="requestSemEnd" class="form-select form-select-sm" style="width: auto; font-size: 12px; padding: 4px 28px 4px 8px;">
                        <option value="1" <?= $curSem === 1 ? 'selected' : '' ?>>Sem 1</option>
                        <option value="2" <?= $curSem === 2 ? 'selected' : '' ?>>Sem 2</option>
                    </select>
                    <select id="requestYearEnd" class="form-select form-select-sm" style="width: auto; font-size: 12px; padding: 4px 28px 4px 8px;">
                        <?php foreach($yearOptions as $y): ?>
                        <option value="<?= $y ?>" <?= $y === $curYear ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary btn-sm" onclick="loadRequestChart()" style="font-size: 12px; padding: 4px 10px;">
                        <i class="bi bi-funnel"></i>
                    </button>
                    <!-- Export Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" style="font-size: 12px; padding: 4px 10px;">
                            <i class="bi bi-download"></i> Ekspor
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="exportReport('request','pdf')"><i class="bi bi-file-pdf me-2 text-danger"></i>PDF</a></li>
                            <li><a class="dropdown-item" href="#" onclick="exportReport('request','word')"><i class="bi bi-file-word me-2 text-primary"></i>Word</a></li>
                            <li><a class="dropdown-item" href="#" onclick="exportReport('request','excel')"><i class="bi bi-file-excel me-2 text-success"></i>Excel</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="card-body" style="padding: 16px;">
                <!-- Summary (dynamic) -->
                <div id="requestSummary" style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
                    <div style="background: var(--bg-main); padding: 10px 14px; border-radius: 8px; flex: 1; min-width: 80px;">
                        <div id="requestTotal" style="font-size: 18px; font-weight: 700; color: var(--primary-light);"><?= array_sum($requestChartData) ?></div>
                        <div style="font-size: 10px; color: var(--text-muted);">Total</div>
                    </div>
                    <div style="background: var(--bg-main); padding: 10px 14px; border-radius: 8px; flex: 1; min-width: 80px;">
                        <div id="requestTypes" style="font-size: 18px; font-weight: 700; color: var(--text-dark);"><?= count($requestChartLabels) ?></div>
                        <div style="font-size: 10px; color: var(--text-muted);">Jenis</div>
                    </div>
                    <div style="background: var(--bg-main); padding: 10px 14px; border-radius: 8px; flex: 2; min-width: 120px;">
                        <div id="requestTopName" style="font-size: 13px; font-weight: 600; color: var(--success); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($requestChartLabels[0] ?? '-') ?></div>
                        <div id="requestTopCount" style="font-size: 10px; color: var(--text-muted);">Terpopuler (<?= $requestChartData[0] ?? 0 ?>x)</div>
                    </div>
                </div>
                <div id="requestChartWrapper">
                    <div class="chart-container" style="height: 220px;">
                        <canvas id="topRequestedChart"></canvas>
                    </div>
                </div>
                <div id="requestEmpty" style="display: none;">
                    <div class="empty-state" style="padding: 30px;">
                        <div class="empty-state-icon" style="width: 50px; height: 50px; font-size: 20px;"><i class="bi bi-cart-check"></i></div>
                        <h5 class="empty-state-title" style="font-size: 14px;">Belum Ada Data</h5>
                        <p class="empty-state-text mb-0" style="font-size: 12px;">Data permintaan akan muncul di sini</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Info Stats Row -->
<div class="row g-4" style="margin-bottom: 24px;">
    <!-- Quick Stats Card -->
    <div class="col-lg-4">
        <div class="modern-card h-100">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="bi bi-pie-chart-fill"></i> Statistik Cepat
                </h3>
            </div>
            <div class="card-body" style="padding: 16px;">
                <div class="info-stat-main" style="padding: 12px 0;">
                    <p class="info-stat-value" style="font-size: 28px;"><?= number_format($totalUsers) ?></p>
                    <p class="info-stat-label" style="font-size: 12px;">Total Pengguna Terdaftar</p>
                </div>
                <ul class="info-list" style="margin: 0;">
                    <li class="info-list-item" style="padding: 10px 0;">
                        <div class="info-list-left">
                            <div class="info-list-icon" style="background: rgba(26, 154, 170, 0.1); color: var(--primary-light); width: 32px; height: 32px;">
                                <i class="bi bi-box-seam" style="font-size: 14px;"></i>
                            </div>
                            <span class="info-list-text" style="font-size: 13px;">Barang</span>
                        </div>
                        <span class="info-list-value" style="font-size: 14px;"><?= number_format($totalItems) ?></span>
                    </li>
                    <li class="info-list-item" style="padding: 10px 0;">
                        <div class="info-list-left">
                            <div class="info-list-icon" style="background: var(--success-light); color: var(--success); width: 32px; height: 32px;">
                                <i class="bi bi-clipboard-check" style="font-size: 14px;"></i>
                            </div>
                            <span class="info-list-text" style="font-size: 13px;">Peminjaman</span>
                        </div>
                        <span class="info-list-value" style="font-size: 14px;"><?= number_format($totalLoans) ?></span>
                    </li>
                    <li class="info-list-item" style="padding: 10px 0;">
                        <div class="info-list-left">
                            <div class="info-list-icon" style="background: var(--warning-light); color: var(--warning); width: 32px; height: 32px;">
                                <i class="bi bi-hourglass-split" style="font-size: 14px;"></i>
                            </div>
                            <span class="info-list-text" style="font-size: 13px;">Pending</span>
                        </div>
                        <span class="info-list-value" style="font-size: 14px;"><?= number_format($totalPendingLoans) ?></span>
                    </li>
                    <li class="info-list-item" style="padding: 10px 0;">
                        <div class="info-list-left">
                            <div class="info-list-icon" style="background: var(--danger-light); color: var(--danger); width: 32px; height: 32px;">
                                <i class="bi bi-exclamation-triangle" style="font-size: 14px;"></i>
                            </div>
                            <span class="info-list-text" style="font-size: 13px;">Stok Rendah</span>
                        </div>
                        <span class="info-list-value" style="font-size: 14px;"><?= number_format($lowStock) ?></span>
                    </li>
                </ul>
                <a href="/index.php?page=admin_users_list" class="info-btn" style="margin-top: 12px; padding: 10px;">
                    <i class="bi bi-people"></i> Kelola Pengguna
                </a>
            </div>
        </div>
    </div>
    
    <!-- Low Stock Items -->
    <div class="col-lg-4">
        <div class="modern-card h-100">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="bi bi-exclamation-triangle-fill"></i> Stok Menipis
                </h3>
                <a href="/index.php?page=admin_inventory_list&low_stock=1" style="color: var(--primary-light); font-size: 12px;">Semua</a>
            </div>
            <div class="card-body" style="padding: 12px;">
                <?php if (empty($lowStockItems)): ?>
                <div class="empty-state" style="padding: 30px 15px;">
                    <div class="empty-state-icon" style="width: 50px; height: 50px; font-size: 20px;">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <h5 class="empty-state-title" style="font-size: 14px;">Stok Aman</h5>
                    <p class="empty-state-text mb-0" style="font-size: 12px;">Semua barang memiliki stok cukup</p>
                </div>
                <?php else: ?>
                <?php foreach($lowStockItems as $item): ?>
                <div class="product-item" style="padding: 10px 0;">
                    <?php if (!empty($item['image'])): ?>
                    <img src="/public/assets/uploads/<?= htmlspecialchars($item['image']) ?>" 
                         alt="<?= htmlspecialchars($item['name']) ?>"
                         class="product-img"
                         style="width: 40px; height: 40px; object-fit: cover; border-radius: var(--radius);">
                    <?php else: ?>
                    <div class="product-img" style="background: var(--danger-light); width: 40px; height: 40px;">
                        <i class="bi bi-box-seam" style="color: var(--danger); font-size: 16px;"></i>
                    </div>
                    <?php endif; ?>
                    <div class="product-info" style="flex: 1; min-width: 0;">
                        <p class="product-name" style="font-size: 13px; margin: 0 0 2px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($item['name']) ?></p>
                        <p class="product-category" style="font-size: 11px; margin: 0;">Total: <?= $item['stock_total'] ?></p>
                    </div>
                    <div class="product-stock" style="text-align: right;">
                        <p class="product-stock-value" style="color: var(--danger); font-size: 16px; margin: 0;"><?= $item['stock_available'] ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Out of Stock Items -->
    <div class="col-lg-4">
        <div class="modern-card h-100">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="bi bi-x-circle"></i> Stok Habis
                </h3>
                <a href="/index.php?page=admin_inventory_list&low_stock=1" style="color: var(--primary-light); font-size: 12px;">(<?= $totalOutOfStockItems ?>)</a>
            </div>
            <div class="card-body" style="padding: 12px;">
                <?php if (empty($outOfStockItems)): ?>
                <div class="empty-state" style="padding: 30px 15px;">
                    <div class="empty-state-icon" style="width: 50px; height: 50px; font-size: 20px;">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <h5 class="empty-state-title" style="font-size: 14px;">Stok Tersedia</h5>
                    <p class="empty-state-text mb-0" style="font-size: 12px;">Semua barang memiliki stok</p>
                </div>
                <?php else: ?>
                <?php foreach($outOfStockItems as $item): ?>
                <div class="product-item" style="padding: 10px 0;">
                    <?php if (!empty($item['image'])): ?>
                    <img src="/public/assets/uploads/<?= htmlspecialchars($item['image']) ?>" 
                         alt="<?= htmlspecialchars($item['name']) ?>"
                         class="product-img"
                         style="width: 40px; height: 40px; object-fit: cover; border-radius: var(--radius);">
                    <?php else: ?>
                    <div class="product-img" style="background: var(--danger-light); width: 40px; height: 40px;">
                        <i class="bi bi-box-seam" style="color: var(--danger); font-size: 16px;"></i>
                    </div>
                    <?php endif; ?>
                    <div class="product-info" style="flex: 1; min-width: 0;">
                        <p class="product-name" style="font-size: 13px; margin: 0 0 2px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($item['name']) ?></p>
                        <span class="badge bg-danger" style="font-size: 10px; padding: 3px 6px;">Habis</span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Loans Table -->
<div class="row g-4">
    <div class="col-12">
        <div class="table-card">
            <div class="card-header" style="padding: 16px 20px; border-bottom: 1px solid var(--border-color);">
                <h3 class="card-title" style="margin: 0; font-size: 16px;">
                    <i class="bi bi-clock-history"></i> Peminjaman Terbaru
                </h3>
                <a href="/index.php?page=admin_loans" class="btn btn-secondary btn-sm">
                    Lihat Semua
                </a>
            </div>
            <div style="padding: 12px 20px 0;">
                <div class="table-filters">
                    <button class="table-filter-btn active" data-filter="all">Semua</button>
                    <button class="table-filter-btn" data-filter="pending">Pending</button>
                    <button class="table-filter-btn" data-filter="approved">Disetujui</button>
                </div>
            </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Peminjam</th>
                        <th>Barang</th>
                        <th>Jumlah</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="dashboardLoansTable">
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
                    <?php $loanNumber = 1; foreach($recentLoans as $l): 
                        $rowStatus = ($l['status'] === 'pending' || $l['stage'] === 'pending') ? 'pending' : 
                                     (($l['status'] === 'approved' || $l['stage'] === 'approved') ? 'approved' : 'other');
                        // Truncate item names if too long
                        $itemNames = $l['item_names'];
                        if (strlen($itemNames) > 40) {
                            $itemNames = substr($itemNames, 0, 37) . '...';
                        }
                    ?>
                    <tr data-status="<?= $rowStatus ?>">
                        <td><span class="badge bg-secondary"><?= $loanNumber++ ?></span></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="topbar-avatar" style="width: 32px; height: 32px; font-size: 12px;">
                                    <?= strtoupper(substr($l['user_name'], 0, 1)) ?>
                                </div>
                                <?= htmlspecialchars($l['user_name']) ?>
                            </div>
                        </td>
                        <td>
                            <span title="<?= htmlspecialchars($l['item_names']) ?>"><?= htmlspecialchars($itemNames) ?></span>
                            <?php if ($l['item_count'] > 1): ?>
                            <span class="badge bg-info ms-1" style="font-size: 10px;"><?= $l['item_count'] ?> item</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= $l['total_quantity'] ?></strong></td>
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
                            <a href="/index.php?page=admin_loans" class="btn btn-sm btn-outline-primary" title="Lihat Detail">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>
</div>

<!-- Recent Requests Table -->
<div class="row g-4" style="margin-top: 24px;">
    <div class="col-12">
        <div class="table-card">
            <div class="card-header" style="padding: 16px 20px; border-bottom: 1px solid var(--border-color);">
                <h3 class="card-title" style="margin: 0; font-size: 16px;">
                    <i class="bi bi-cart-check"></i> Permintaan Terbaru
                </h3>
                <a href="/index.php?page=admin_requests" class="btn btn-secondary btn-sm">
                    Lihat Semua
                </a>
            </div>
            <div style="padding: 12px 20px 0;">
                <div class="table-filters" id="requestFilters">
                    <button class="table-filter-btn active" data-filter="all">Semua</button>
                    <button class="table-filter-btn" data-filter="pending">Pending</button>
                    <button class="table-filter-btn" data-filter="approved">Disetujui</button>
                </div>
            </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Peminta</th>
                        <th>Barang</th>
                        <th>Jumlah</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="dashboardRequestsTable">
                    <?php if(empty($recentRequests)): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state" style="padding: 40px;">
                                <div class="empty-state-icon" style="width: 60px; height: 60px; font-size: 24px;">
                                    <i class="bi bi-cart"></i>
                                </div>
                                <h5 class="empty-state-title">Belum Ada Permintaan</h5>
                                <p class="empty-state-text mb-0">Data permintaan akan muncul di sini</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php $requestNumber = 1; foreach($recentRequests as $r): 
                        $rowStatus = ($r['stage'] === 'pending') ? 'pending' : 
                                     (($r['stage'] === 'approved') ? 'approved' : 'other');
                        // Truncate item names if too long
                        $itemNames = $r['item_names'];
                        if (strlen($itemNames) > 40) {
                            $itemNames = substr($itemNames, 0, 37) . '...';
                        }
                    ?>
                    <tr data-status="<?= $rowStatus ?>">
                        <td><span class="badge bg-secondary"><?= $requestNumber++ ?></span></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="topbar-avatar" style="width: 32px; height: 32px; font-size: 12px;">
                                    <?= strtoupper(substr($r['user_name'], 0, 1)) ?>
                                </div>
                                <?= htmlspecialchars($r['user_name']) ?>
                            </div>
                        </td>
                        <td>
                            <span title="<?= htmlspecialchars($r['item_names']) ?>"><?= htmlspecialchars($itemNames) ?></span>
                            <?php if ($r['item_count'] > 1): ?>
                            <span class="badge bg-info ms-1" style="font-size: 10px;"><?= $r['item_count'] ?> item</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= $r['total_quantity'] ?></strong></td>
                        <td><small><?= date('d M Y', strtotime($r['requested_at'])) ?></small></td>
                        <td>
                            <?php if($r['stage'] === 'pending'): ?>
                                <span class="status-badge warning">Pending</span>
                            <?php elseif($r['stage'] === 'approved'): ?>
                                <span class="status-badge success">Disetujui</span>
                            <?php elseif($r['stage'] === 'rejected'): ?>
                                <span class="status-badge danger">Ditolak</span>
                            <?php else: ?>
                                <span class="status-badge secondary"><?= htmlspecialchars($r['stage']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="/index.php?page=admin_requests" class="btn btn-sm btn-outline-primary" title="Lihat Detail">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initial chart data from PHP (all-time, no filter)
    let borrowLabels = <?= json_encode($chartLabels) ?>;
    let borrowData = <?= json_encode($chartData) ?>;
    let requestLabels = <?= json_encode($requestChartLabels) ?>;
    let requestData = <?= json_encode($requestChartData) ?>;

    const tealPalette = [
        'rgba(13, 79, 92, 0.9)', 'rgba(26, 154, 170, 0.9)', 'rgba(45, 180, 180, 0.9)',
        'rgba(94, 200, 200, 0.9)', 'rgba(140, 215, 215, 0.9)', 'rgba(175, 225, 225, 0.9)', 'rgba(200, 235, 235, 0.9)',
        'rgba(10, 60, 70, 0.9)', 'rgba(50, 160, 160, 0.9)', 'rgba(80, 190, 190, 0.9)'
    ];

    let topBorrowedChart = null;
    let topRequestedChart = null;
    let currentBorrowType = 'bar';
    let currentRequestType = 'bar';

    function createBorrowChart(type) {
        if (type) currentBorrowType = type;
        else type = currentBorrowType;

        const ctx = document.getElementById('topBorrowedChart');
        if (!ctx) return;
        if (topBorrowedChart) topBorrowedChart.destroy();

        if (borrowLabels.length === 0) {
            document.getElementById('borrowChartWrapper').style.display = 'none';
            document.getElementById('borrowEmpty').style.display = 'block';
            return;
        }
        document.getElementById('borrowChartWrapper').style.display = 'block';
        document.getElementById('borrowEmpty').style.display = 'none';

        const isBar = type === 'bar';
        topBorrowedChart = new Chart(ctx, {
            type: type,
            data: {
                labels: borrowLabels,
                datasets: [{
                    label: 'Jumlah Peminjaman',
                    data: borrowData,
                    backgroundColor: isBar ? 'rgba(26, 154, 170, 0.8)' : tealPalette,
                    borderColor: isBar ? 'rgba(13, 79, 92, 1)' : tealPalette.map(c => c.replace('0.9', '1')),
                    borderWidth: isBar ? 0 : 2,
                    borderRadius: isBar ? 6 : 0,
                    hoverBackgroundColor: isBar ? 'rgba(13, 79, 92, 0.95)' : undefined
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: !isBar, position: 'right', labels: { padding: 10, usePointStyle: true, pointStyle: 'circle', font: { size: 11 }, color: '#64748b' } },
                    tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.95)', padding: 10, cornerRadius: 8, titleFont: { size: 12, weight: '600' }, bodyFont: { size: 11 } }
                },
                scales: isBar ? {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)', drawBorder: false }, ticks: { font: { size: 10 }, color: '#94a3b8' } },
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#64748b' } }
                } : {},
                cutout: type === 'doughnut' ? '65%' : undefined
            }
        });
    }

    function createRequestChart(type) {
        if (type) currentRequestType = type;
        else type = currentRequestType;

        const ctx = document.getElementById('topRequestedChart');
        if (!ctx) return;
        if (topRequestedChart) topRequestedChart.destroy();

        if (requestLabels.length === 0) {
            document.getElementById('requestChartWrapper').style.display = 'none';
            document.getElementById('requestEmpty').style.display = 'block';
            return;
        }
        document.getElementById('requestChartWrapper').style.display = 'block';
        document.getElementById('requestEmpty').style.display = 'none';

        const isBar = type === 'bar';
        topRequestedChart = new Chart(ctx, {
            type: type,
            data: {
                labels: requestLabels,
                datasets: [{
                    label: 'Jumlah Permintaan',
                    data: requestData,
                    backgroundColor: isBar ? 'rgba(26, 154, 170, 0.8)' : tealPalette,
                    borderColor: isBar ? 'rgba(13, 79, 92, 1)' : tealPalette.map(c => c.replace('0.9', '1')),
                    borderWidth: isBar ? 0 : 2,
                    borderRadius: isBar ? 6 : 0,
                    hoverBackgroundColor: isBar ? 'rgba(13, 79, 92, 0.95)' : undefined
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: !isBar, position: 'right', labels: { padding: 10, usePointStyle: true, pointStyle: 'circle', font: { size: 11 }, color: '#64748b' } },
                    tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.95)', padding: 10, cornerRadius: 8, titleFont: { size: 12, weight: '600' }, bodyFont: { size: 11 } }
                },
                scales: isBar ? {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)', drawBorder: false }, ticks: { font: { size: 10 }, color: '#94a3b8' } },
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#64748b' } }
                } : {},
                cutout: type === 'doughnut' ? '65%' : undefined
            }
        });
    }

    // Initialize charts
    createBorrowChart('bar');
    createRequestChart('bar');

    // Chart type toggle buttons
    document.querySelectorAll('.chart-type-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const chart = this.dataset.chart;
            this.closest('.card-actions').querySelectorAll('.chart-type-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            if (chart === 'borrow') createBorrowChart(this.dataset.type);
            else if (chart === 'request') createRequestChart(this.dataset.type);
        });
    });

    // ===== AJAX: Load chart data by semester =====
    window.loadBorrowChart = function() {
        const semStart = document.getElementById('borrowSemStart').value;
        const yearStart = document.getElementById('borrowYearStart').value;
        const semEnd = document.getElementById('borrowSemEnd').value;
        const yearEnd = document.getElementById('borrowYearEnd').value;

        fetch(`/index.php?page=admin_chart_data&type=borrow&sem_start=${semStart}&year_start=${yearStart}&sem_end=${semEnd}&year_end=${yearEnd}`)
            .then(r => r.json())
            .then(d => {
                borrowLabels = d.labels;
                borrowData = d.data;
                document.getElementById('borrowTotal').textContent = d.total;
                document.getElementById('borrowTypes').textContent = d.count_types;
                document.getElementById('borrowTopName').textContent = d.top_name || '-';
                document.getElementById('borrowTopCount').textContent = d.top_name ? `Terpopuler (${d.top_count}x)` : '-';
                createBorrowChart();
            })
            .catch(err => console.error('Failed to load borrow data:', err));
    };

    window.loadRequestChart = function() {
        const semStart = document.getElementById('requestSemStart').value;
        const yearStart = document.getElementById('requestYearStart').value;
        const semEnd = document.getElementById('requestSemEnd').value;
        const yearEnd = document.getElementById('requestYearEnd').value;

        fetch(`/index.php?page=admin_chart_data&type=request&sem_start=${semStart}&year_start=${yearStart}&sem_end=${semEnd}&year_end=${yearEnd}`)
            .then(r => r.json())
            .then(d => {
                requestLabels = d.labels;
                requestData = d.data;
                document.getElementById('requestTotal').textContent = d.total;
                document.getElementById('requestTypes').textContent = d.count_types;
                document.getElementById('requestTopName').textContent = d.top_name || '-';
                document.getElementById('requestTopCount').textContent = d.top_name ? `Terpopuler (${d.top_count}x)` : '-';
                createRequestChart();
            })
            .catch(err => console.error('Failed to load request data:', err));
    };

    // ===== EXPORT =====
    window.exportReport = function(type, format) {
        let semStart, yearStart, semEnd, yearEnd;
        if (type === 'borrow') {
            semStart = document.getElementById('borrowSemStart').value;
            yearStart = document.getElementById('borrowYearStart').value;
            semEnd = document.getElementById('borrowSemEnd').value;
            yearEnd = document.getElementById('borrowYearEnd').value;
        } else {
            semStart = document.getElementById('requestSemStart').value;
            yearStart = document.getElementById('requestYearStart').value;
            semEnd = document.getElementById('requestSemEnd').value;
            yearEnd = document.getElementById('requestYearEnd').value;
        }
        const url = `/index.php?page=admin_export_report&type=${type}&format=${format}&sem_start=${semStart}&year_start=${yearStart}&sem_end=${semEnd}&year_end=${yearEnd}`;
        if (format === 'pdf') {
            window.open(url, '_blank');
        } else {
            window.location.href = url;
        }
    };
});
</script>

<script>
// Dashboard filter buttons functionality for Loans
document.querySelectorAll('.table-filters:not(#requestFilters) .table-filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        this.closest('.table-filters').querySelectorAll('.table-filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        const filter = this.dataset.filter;
        const rows = document.querySelectorAll('#dashboardLoansTable tr[data-status]');
        
        rows.forEach(row => {
            if (filter === 'all' || row.dataset.status === filter) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
});

// Dashboard filter buttons functionality for Requests
document.querySelectorAll('#requestFilters .table-filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        this.closest('.table-filters').querySelectorAll('.table-filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        const filter = this.dataset.filter;
        const rows = document.querySelectorAll('#dashboardRequestsTable tr[data-status]');
        
        rows.forEach(row => {
            if (filter === 'all' || row.dataset.status === filter) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
});
</script>
