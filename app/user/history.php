<?php
// app/user/history.php - Simplified Workflow History Page
// Employee submits loan → Admin validates & uploads BAST → Done
if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$userId = $_SESSION['user']['id'];

$msg = $_GET['msg'] ?? '';
$errors = [];
$success = '';

// === HANDLE POST ACTIONS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $loan_id = (int)($_POST['loan_id'] ?? 0);
    $group_id = $_POST['group_id'] ?? '';

    // === USER REQUEST RETURN ===
    if ($action === 'request_return' && ($group_id || $loan_id)) {
        $return_note = trim($_POST['return_note'] ?? '');
        
        if ($group_id) {
            // Multi-item return
            $stmt = $pdo->prepare('SELECT * FROM loans WHERE group_id = ? AND user_id = ?');
            $stmt->execute([$group_id, $userId]);
        } else {
            // Single item return
            $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = ? AND user_id = ?');
            $stmt->execute([$loan_id, $userId]);
        }
        $loansToReturn = $stmt->fetchAll();
        
        $valid = true;
        foreach ($loansToReturn as $loan) {
            if ($loan['stage'] !== 'approved' || ($loan['return_stage'] ?? 'none') !== 'none') {
                $valid = false;
                break;
            }
        }
        
        if (!$valid || empty($loansToReturn)) {
            $errors[] = 'Barang tidak dapat diajukan pengembalian.';
        } else {
            foreach ($loansToReturn as $loan) {
                $stmt = $pdo->prepare('UPDATE loans SET return_stage = ?, return_requested_at = NOW(), return_note = ? WHERE id = ?');
                $stmt->execute(['pending_return', $return_note, $loan['id']]);
            }
            $success = 'Pengajuan pengembalian berhasil. Menunggu persetujuan admin.';
        }
    }
}

// Fetch user's loans with group_id support
$stmt = $pdo->prepare("
  SELECT l.*, i.name AS inventory_name, i.code AS inventory_code, i.image AS inventory_image
  FROM loans l
  JOIN inventories i ON i.id = l.inventory_id
  WHERE l.user_id = ?
  ORDER BY l.requested_at DESC, l.group_id, l.id
");
$stmt->execute([$userId]);
$rawLoans = $stmt->fetchAll();

// Group loans by group_id for multi-item transactions
$loans = [];
$groupedLoans = [];
foreach ($rawLoans as $loan) {
    if (!empty($loan['group_id'])) {
        if (!isset($groupedLoans[$loan['group_id']])) {
            $groupedLoans[$loan['group_id']] = [
                'is_group' => true,
                'group_id' => $loan['group_id'],
                'items' => [],
                'requested_at' => $loan['requested_at'],
                'stage' => $loan['stage'],
                'return_stage' => $loan['return_stage'] ?? 'none',
                'note' => $loan['note'],
                'rejection_note' => $loan['rejection_note'],
                'admin_document_path' => $loan['admin_document_path'] ?? null,
                'return_admin_document_path' => $loan['return_admin_document_path'] ?? null,
                'return_note' => $loan['return_note'] ?? null,
                'total_quantity' => 0,
                'first_id' => $loan['id']
            ];
        }
        $groupedLoans[$loan['group_id']]['items'][] = $loan;
        $groupedLoans[$loan['group_id']]['total_quantity'] += $loan['quantity'];
        
        // Use first non-empty note
        if (!empty($loan['note']) && empty($groupedLoans[$loan['group_id']]['note'])) {
            $groupedLoans[$loan['group_id']]['note'] = $loan['note'];
        }
        // Use first non-empty admin doc
        if (!empty($loan['admin_document_path']) && empty($groupedLoans[$loan['group_id']]['admin_document_path'])) {
            $groupedLoans[$loan['group_id']]['admin_document_path'] = $loan['admin_document_path'];
        }
        // Update group status
        if ($loan['stage'] === 'rejected') {
            $groupedLoans[$loan['group_id']]['stage'] = 'rejected';
            $groupedLoans[$loan['group_id']]['rejection_note'] = $loan['rejection_note'];
        }
    } else {
        $loan['is_group'] = false;
        $loans[] = $loan;
    }
}

// Merge grouped loans into main array
foreach ($groupedLoans as $group) {
    $loans[] = $group;
}

// Sort by requested_at DESC
usort($loans, fn($a, $b) => strtotime($b['requested_at'] ?? $b['items'][0]['requested_at'] ?? 'now') - strtotime($a['requested_at'] ?? $a['items'][0]['requested_at'] ?? 'now'));

// Calculate stats
$totalLoans = count($loans);
$activeLoans = 0;
$pendingLoans = 0;
$completedLoans = 0;
foreach ($loans as $l) {
    $stage = $l['stage'] ?? ($l['items'][0]['stage'] ?? 'pending');
    $returnStage = $l['return_stage'] ?? ($l['items'][0]['return_stage'] ?? 'none');
    if ($stage === 'pending') {
        $pendingLoans++;
    } elseif ($stage === 'approved' && $returnStage !== 'return_approved') {
        $activeLoans++;
    } elseif ($returnStage === 'return_approved') {
        $completedLoans++;
    }
}

// === SERVER-SIDE SEARCH ===
$searchQuery = trim($_GET['search'] ?? '');
$filteredLoans = $loans;

if (!empty($searchQuery)) {
    $searchLower = strtolower($searchQuery);
    $filteredLoans = array_filter($loans, function($loan) use ($searchLower) {
        $itemNames = '';
        $items = $loan['items'] ?? [$loan];
        foreach ($items as $item) {
            $itemNames .= strtolower($item['inventory_name'] ?? '') . ' ';
            $itemNames .= strtolower($item['inventory_code'] ?? '') . ' ';
        }
        return strpos($itemNames, $searchLower) !== false;
    });
}

// === PAGINATION ===
$itemsPerPage = 50;
$currentPage = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$totalLoansFiltered = count($filteredLoans);
$totalPages = max(1, ceil($totalLoansFiltered / $itemsPerPage));
$currentPage = min($currentPage, $totalPages); // Prevent exceeding max page
$offset = ($currentPage - 1) * $itemsPerPage;

// Slice the loans array for current page
$loansToDisplay = array_slice($filteredLoans, $offset, $itemsPerPage);

// Calculate display range
$displayFrom = $totalLoansFiltered > 0 ? $offset + 1 : 0;
$displayTo = min($offset + $itemsPerPage, $totalLoansFiltered);

// URL builder for pagination
$buildPaginationUrl = function($pageNum) use ($searchQuery) {
    $params = ['page' => 'history', 'p' => $pageNum];
    if (!empty($searchQuery)) {
        $params['search'] = $searchQuery;
    }
    return '?' . http_build_query($params);
};

$stageLabels = [
    'pending' => ['Menunggu Persetujuan', 'warning', 'hourglass'],
    'approved' => ['Disetujui', 'success', 'check-circle'],
    'rejected' => ['Ditolak', 'danger', 'x-circle']
];
$returnStageLabels = [
    'none' => ['Belum Dikembalikan', 'secondary', 'dash'],
    'pending_return' => ['Menunggu Persetujuan', 'warning', 'hourglass'],
    'return_approved' => ['Dikembalikan', 'success', 'check-circle'],
    'return_rejected' => ['Ditolak', 'danger', 'x-circle']
];
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title"><i class="bi bi-clock-history me-2"></i>Riwayat Peminjaman</h1>
        <p class="text-muted mb-0">Kelola dan pantau status peminjaman barang Anda</p>
    </div>
    <a href="/index.php?page=catalog" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Pinjam Baru</a>
</div>

<!-- Alert Messages -->
<?php if ($msg || $success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg ?: $success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php foreach($errors as $e): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<!-- Stats Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon stat-icon-fixed" style="background: linear-gradient(135deg, var(--primary), var(--primary-light));">
                <i class="bi bi-collection"></i>
            </div>
            <div class="stat-details">
                <div class="stat-number"><?= $totalLoans ?></div>
                <div class="stat-label">Total Peminjaman</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon stat-icon-fixed" style="background: linear-gradient(135deg, #f59e0b, #fbbf24);">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <div class="stat-details">
                <div class="stat-number"><?= $pendingLoans ?></div>
                <div class="stat-label">Menunggu</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon stat-icon-fixed" style="background: linear-gradient(135deg, var(--accent), var(--accent-light));">
                <i class="bi bi-box-seam"></i>
            </div>
            <div class="stat-details">
                <div class="stat-number"><?= $activeLoans ?></div>
                <div class="stat-label">Sedang Dipinjam</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon stat-icon-fixed" style="background: linear-gradient(135deg, #10b981, #34d399);">
                <i class="bi bi-check-circle"></i>
            </div>
            <div class="stat-details">
                <div class="stat-number"><?= $completedLoans ?></div>
                <div class="stat-label">Selesai</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter Tabs -->
<div class="card mb-4">
    <div class="card-body py-2">
        <ul class="nav nav-pills" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-filter="all"><i class="bi bi-grid me-1"></i>Semua</button></li>
            <li class="nav-item"><button class="nav-link" data-filter="pending"><i class="bi bi-hourglass me-1"></i>Menunggu</button></li>
            <li class="nav-item"><button class="nav-link" data-filter="active"><i class="bi bi-box-seam me-1"></i>Aktif</button></li>
            <li class="nav-item"><button class="nav-link" data-filter="completed"><i class="bi bi-check-circle me-1"></i>Selesai</button></li>
        </ul>
    </div>
</div>

<!-- Search Form -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="d-flex gap-2">
            <input type="hidden" name="page" value="history">
            <div class="flex-grow-1">
                <div class="topbar-search" style="max-width: 100%;">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Cari berdasarkan nama atau kode barang..." 
                           value="<?= htmlspecialchars($searchQuery) ?>" style="width: 100%;">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-search me-1"></i>Cari
            </button>
            <?php if (!empty($searchQuery)): ?>
            <a href="?page=history" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle"></i>
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Loans Table -->
<div class="card">
    <!-- Pagination Info Header -->
    <div class="card-header bg-white border-bottom">
        <div class="d-flex justify-content-between align-items-center">
            <div class="text-muted">
                <i class="bi bi-list-ul me-2"></i>
                Menampilkan <strong><?= $displayFrom ?></strong> - <strong><?= $displayTo ?></strong> dari <strong><?= $totalLoansFiltered ?></strong> data
                <?php if (!empty($searchQuery)): ?>
                    <span class="text-primary"> (hasil pencarian)</span>
                <?php endif; ?>
            </div>
            
            <!-- Page Navigation (Top) -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination-controls">
                <nav aria-label="Pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $buildPaginationUrl(1) ?>" aria-label="First">
                                <i class="bi bi-chevron-bar-left"></i>
                            </a>
                        </li>
                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $buildPaginationUrl($currentPage - 1) ?>" aria-label="Previous">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php
                        // Show page numbers (Gmail style)
                        $pageRange = 2; // Show 2 pages before and after current
                        $startPage = max(1, $currentPage - $pageRange);
                        $endPage = min($totalPages, $currentPage + $pageRange);
                        
                        if ($startPage > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= $buildPaginationUrl(1) ?>">1</a></li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                                <a class="page-link" href="<?= $buildPaginationUrl($i) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?= $buildPaginationUrl($totalPages) ?>"><?= $totalPages ?></a></li>
                        <?php endif; ?>
                        
                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $buildPaginationUrl($currentPage + 1) ?>" aria-label="Next">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $buildPaginationUrl($totalPages) ?>" aria-label="Last">
                                <i class="bi bi-chevron-bar-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="card-body p-0">
        <?php if(empty($loansToDisplay)): ?>
        <div class="text-center py-5">
            <div class="empty-state">
                <i class="bi bi-<?= !empty($searchQuery) ? 'search' : 'inbox' ?>" style="font-size: 48px; color: var(--text-muted);"></i>
                <?php if (!empty($searchQuery)): ?>
                    <h5>Tidak Ada Hasil</h5>
                    <p class="text-muted">Tidak ada peminjaman yang cocok dengan pencarian "<?= htmlspecialchars($searchQuery) ?>".</p>
                    <a href="?page=history" class="btn btn-sm btn-outline-primary mt-2">
                        <i class="bi bi-x-circle me-1"></i>Hapus Filter
                    </a>
                <?php else: ?>
                    <h5>Belum Ada Peminjaman</h5>
                    <p class="text-muted">Anda belum memiliki riwayat peminjaman barang.</p>
                    <a href="/index.php?page=catalog" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Pinjam Sekarang</a>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th width="50">No</th>
                        <th>Barang</th>
                        <th width="80">Qty</th>
                        <th>Catatan Saya</th>
                        <th width="120">Tanggal</th>
                        <th width="160">Status Peminjaman</th>
                        <th width="160">Status Pengembalian</th>
                        <th width="180">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totalRows = count($loans); // Total all records
                    $rowNumber = $offset; // Start from offset for continuous numbering
                    foreach($loansToDisplay as $l):  // Use loansToDisplay instead of loans
                        $rowNumber++;
                        $isGroup = !empty($l['is_group']);
                        
                        if ($isGroup):
                            $firstItem = $l['items'][0];
                            $itemCount = count($l['items']);
                            $stage = $l['stage'] ?? $firstItem['stage'];
                            $returnStage = $l['return_stage'] ?? $firstItem['return_stage'] ?? 'none';
                            $stageInfo = $stageLabels[$stage] ?? [$stage, 'secondary', 'question'];
                            $returnInfo = $returnStageLabels[$returnStage] ?? ['N/A', 'secondary', 'question'];
                            $adminDoc = $l['admin_document_path'] ?? null;
                            $returnAdminDoc = $l['return_admin_document_path'] ?? null;
                            $userNote = $l['note'] ?? null;
                            $returnNote = $l['return_note'] ?? null;
                            
                            $filterClass = 'all';
                            if ($stage === 'pending') { $filterClass .= ' pending'; }
                            elseif ($stage === 'approved' && $returnStage !== 'return_approved') { $filterClass .= ' active'; }
                            elseif ($returnStage === 'return_approved') { $filterClass .= ' completed'; }
                    ?>
                    <!-- Group Header Row -->
                    <tr class="loan-row group-header <?= $filterClass ?>" data-group="<?= $l['group_id'] ?>" style="cursor: pointer;" onclick="toggleGroup('<?= $l['group_id'] ?>')">
                        <td><span class="badge bg-secondary"><?= $totalRows - $rowNumber + 1 ?></span></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="rounded me-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary), var(--primary-light));">
                                    <i class="bi bi-stack text-white"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold">
                                        <i class="bi bi-chevron-right group-chevron me-1" id="chevron-<?= $l['group_id'] ?>"></i>
                                        <?= $itemCount ?> Barang
                                    </div>
                                    <small class="text-muted">Klik untuk lihat detail</small>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge bg-primary"><?= (int)$l['total_quantity'] ?> unit</span></td>
                        <td>
                            <?php if ($userNote || $returnNote): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#noteHistoryModalGroup<?= $l['group_id'] ?>" onclick="event.stopPropagation();">
                                <i class="bi bi-chat-left-text me-1"></i>Lihat
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= date('d M Y', strtotime($firstItem['requested_at'])) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($firstItem['requested_at'])) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-<?= $stageInfo[1] ?>"><i class="bi bi-<?= $stageInfo[2] ?> me-1"></i><?= $stageInfo[0] ?></span>
                            <?php if ($stage === 'rejected' && !empty($l['rejection_note'])): ?>
                            <button type="button" class="btn-alasan ms-1" data-bs-toggle="modal" data-bs-target="#rejectionModalGroup<?= $l['group_id'] ?>" onclick="event.stopPropagation();">
                                <i class="bi bi-exclamation-circle"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($stage === 'approved'): ?>
                            <span class="badge bg-<?= $returnInfo[1] ?>"><i class="bi bi-<?= $returnInfo[2] ?> me-1"></i><?= $returnInfo[0] ?></span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td onclick="event.stopPropagation();">
                            <?php if ($stage === 'approved' && $adminDoc): ?>
                            <a href="/public/assets/<?= htmlspecialchars($adminDoc) ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1" title="Download BAST Peminjaman">
                                <i class="bi bi-file-earmark-arrow-down"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($returnStage === 'return_approved' && $returnAdminDoc): ?>
                            <a href="/public/assets/<?= htmlspecialchars($returnAdminDoc) ?>" target="_blank" class="btn btn-sm btn-outline-success me-1" title="Download BAST Pengembalian">
                                <i class="bi bi-file-earmark-check"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($stage === 'approved' && $returnStage === 'none'): ?>
                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#returnModalGroup<?= $l['group_id'] ?>">
                                <i class="bi bi-box-arrow-left me-1"></i>Kembalikan
                            </button>
                            <?php elseif ($returnStage === 'pending_return'): ?>
                            <span class="badge bg-warning"><i class="bi bi-hourglass me-1"></i>Proses</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <!-- Expandable detail rows -->
                    <?php foreach($l['items'] as $item): ?>
                    <tr class="group-detail-row <?= $filterClass ?>" data-parent="<?= $l['group_id'] ?>" style="display: none; background: var(--bg-secondary);">
                        <td></td>
                        <td>
                            <div class="d-flex align-items-center ps-3">
                                <div class="border-start border-2 border-primary ps-3 d-flex align-items-center">
                                    <?php if($item['inventory_image']): ?>
                                    <img src="/public/assets/uploads/<?= htmlspecialchars($item['inventory_image']) ?>" class="rounded me-2" style="width: 36px; height: 36px; object-fit: cover;">
                                    <?php else: ?>
                                    <div class="rounded me-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; background: var(--bg-tertiary);"><i class="bi bi-box-seam text-muted"></i></div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($item['inventory_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($item['inventory_code'] ?? '') ?></small>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge bg-secondary"><?= (int)$item['quantity'] ?> unit</span></td>
                        <td colspan="5"></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php else:
                        // Single item transaction
                        $stageInfo = $stageLabels[$l['stage']] ?? [$l['stage'], 'secondary', 'question'];
                        $returnInfo = $returnStageLabels[$l['return_stage'] ?? 'none'] ?? ['N/A', 'secondary', 'question'];
                        $adminDoc = $l['admin_document_path'] ?? null;
                        $returnAdminDoc = $l['return_admin_document_path'] ?? null;
                        $userNote = $l['note'] ?? null;
                        $returnNote = $l['return_note'] ?? null;
                        
                        $filterClass = 'all';
                        if ($l['stage'] === 'pending') { $filterClass .= ' pending'; }
                        elseif ($l['stage'] === 'approved' && ($l['return_stage'] ?? 'none') !== 'return_approved') { $filterClass .= ' active'; }
                        elseif (($l['return_stage'] ?? 'none') === 'return_approved') { $filterClass .= ' completed'; }
                    ?>
                    <tr class="loan-row <?= $filterClass ?>">
                        <td><span class="badge bg-secondary"><?= $totalRows - $rowNumber + 1 ?></span></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <?php if($l['inventory_image']): ?>
                                <img src="/public/assets/uploads/<?= htmlspecialchars($l['inventory_image']) ?>" class="rounded me-2" style="width: 40px; height: 40px; object-fit: cover;">
                                <?php else: ?>
                                <div class="rounded me-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; background: var(--bg-tertiary);"><i class="bi bi-box-seam text-muted"></i></div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($l['inventory_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($l['inventory_code'] ?? '') ?></small>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge bg-primary"><?= (int)$l['quantity'] ?> unit</span></td>
                        <td>
                            <?php if ($userNote || $returnNote): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#noteHistoryModal<?= $l['id'] ?>">
                                <i class="bi bi-chat-left-text me-1"></i>Lihat
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= date('d M Y', strtotime($l['requested_at'])) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($l['requested_at'])) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-<?= $stageInfo[1] ?>"><i class="bi bi-<?= $stageInfo[2] ?> me-1"></i><?= $stageInfo[0] ?></span>
                            <?php if ($l['stage'] === 'rejected' && !empty($l['rejection_note'])): ?>
                            <button type="button" class="btn-alasan ms-1" data-bs-toggle="modal" data-bs-target="#rejectionModal<?= $l['id'] ?>">
                                <i class="bi bi-exclamation-circle"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($l['stage'] === 'approved'): ?>
                            <span class="badge bg-<?= $returnInfo[1] ?>"><i class="bi bi-<?= $returnInfo[2] ?> me-1"></i><?= $returnInfo[0] ?></span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($l['stage'] === 'approved' && $adminDoc): ?>
                            <a href="/public/assets/<?= htmlspecialchars($adminDoc) ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1" title="Download BAST Peminjaman">
                                <i class="bi bi-file-earmark-arrow-down"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (($l['return_stage'] ?? 'none') === 'return_approved' && $returnAdminDoc): ?>
                            <a href="/public/assets/<?= htmlspecialchars($returnAdminDoc) ?>" target="_blank" class="btn btn-sm btn-outline-success me-1" title="Download BAST Pengembalian">
                                <i class="bi bi-file-earmark-check"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($l['stage'] === 'approved' && ($l['return_stage'] ?? 'none') === 'none'): ?>
                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#returnModal<?= $l['id'] ?>">
                                <i class="bi bi-box-arrow-left me-1"></i>Kembalikan
                            </button>
                            <?php elseif (($l['return_stage'] ?? 'none') === 'pending_return'): ?>
                            <span class="badge bg-warning"><i class="bi bi-hourglass me-1"></i>Proses</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Pagination Footer -->
    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white border-top">
        <div class="d-flex justify-content-between align-items-center">
            <div class="text-muted small">
                Halaman <?= $currentPage ?> dari <?= $totalPages ?>
            </div>
            
            <!-- Page Navigation (Bottom) -->
            <nav aria-label="Pagination">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $buildPaginationUrl(1) ?>" aria-label="First">
                            <i class="bi bi-chevron-bar-left"></i>
                        </a>
                    </li>
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $buildPaginationUrl($currentPage - 1) ?>" aria-label="Previous">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    
                    <?php
                    $pageRange = 2;
                    $startPage = max(1, $currentPage - $pageRange);
                    $endPage = min($totalPages, $currentPage + $pageRange);
                    
                    if ($startPage > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?= $buildPaginationUrl(1) ?>">1</a></li>
                        <?php if ($startPage > 2): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="<?= $buildPaginationUrl($i) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item"><a class="page-link" href="<?= $buildPaginationUrl($totalPages) ?>"><?= $totalPages ?></a></li>
                    <?php endif; ?>
                    
                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $buildPaginationUrl($currentPage + 1) ?>" aria-label="Next">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $buildPaginationUrl($totalPages) ?>" aria-label="Last">
                            <i class="bi bi-chevron-bar-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- MODALS -->
<?php foreach($loansToDisplay as $l): // Use loansToDisplay for modals ?>
<?php if (!empty($l['is_group'])): ?>
    <!-- Group: Note History Modal -->
    <?php if (!empty($l['note']) || !empty($l['return_note'])): ?>
    <div class="modal fade" id="noteHistoryModalGroup<?= $l['group_id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-chat-left-text me-2"></i>Riwayat Catatan Saya</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 p-3 rounded" style="background: var(--bg-main);">
                        <div class="fw-semibold mb-2"><?= count($l['items']) ?> Barang:</div>
                        <?php foreach($l['items'] as $gi): ?>
                        <div class="d-flex align-items-center mb-1">
                            <span><?= htmlspecialchars($gi['inventory_name']) ?> (<?= (int)$gi['quantity'] ?> unit)</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (!empty($l['note'])): ?>
                    <div class="p-3 rounded mb-3" style="background: var(--bg-main); border-left: 4px solid var(--primary);">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold"><i class="bi bi-pencil me-1"></i>Catatan Peminjaman</span>
                            <small class="text-muted"><?= date('d M Y H:i', strtotime($l['requested_at'])) ?></small>
                        </div>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($l['note'])) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($l['return_note'])): ?>
                    <div class="p-3 rounded" style="background: var(--bg-main); border-left: 4px solid var(--warning);">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold"><i class="bi bi-box-arrow-left me-1"></i>Catatan Pengembalian</span>
                            <small class="text-muted"><?= !empty($l['items'][0]['return_requested_at']) ? date('d M Y H:i', strtotime($l['items'][0]['return_requested_at'])) : '-' ?></small>
                        </div>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($l['return_note'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Group: Rejection Modal -->
    <?php if ($l['stage'] === 'rejected' && !empty($l['rejection_note'])): ?>
    <div class="modal fade" id="rejectionModalGroup<?= $l['group_id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Peminjaman Ditolak</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="rejection-reason-box">
                        <div class="reason-label"><i class="bi bi-exclamation-triangle me-1"></i>Alasan Penolakan</div>
                        <p class="reason-text"><?= nl2br(htmlspecialchars($l['rejection_note'])) ?></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <a href="/index.php?page=catalog" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Ajukan Baru</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Group: Return Request Modal -->
    <?php if (($l['stage'] ?? '') === 'approved' && ($l['return_stage'] ?? 'none') === 'none'): ?>
    <div class="modal fade" id="returnModalGroup<?= $l['group_id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-box-arrow-left me-2"></i>Ajukan Pengembalian</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="request_return">
                        <input type="hidden" name="group_id" value="<?= htmlspecialchars($l['group_id']) ?>">
                        
                        <div class="mb-3 p-3 rounded" style="background: var(--bg-tertiary);">
                            <div class="fw-semibold mb-2"><?= count($l['items']) ?> Barang akan dikembalikan:</div>
                            <?php foreach($l['items'] as $gi): ?>
                            <div class="d-flex align-items-center mb-1">
                                <i class="bi bi-box-seam text-muted me-2"></i>
                                <span><?= htmlspecialchars($gi['inventory_name']) ?> (<?= (int)$gi['quantity'] ?> unit)</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Catatan Pengembalian (opsional)</label>
                            <textarea name="return_note" class="form-control" rows="3" placeholder="Tambahkan catatan jika diperlukan..."></textarea>
                            <small class="text-muted">Catatan ini akan tersimpan dan dapat Anda lihat kapan saja di riwayat.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-warning"><i class="bi bi-send me-1"></i>Ajukan Pengembalian</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

<?php else: ?>
    <!-- Single: Note History Modal -->
    <?php if (!empty($l['note']) || !empty($l['return_note'])): ?>
    <div class="modal fade" id="noteHistoryModal<?= $l['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-chat-left-text me-2"></i>Riwayat Catatan Saya</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center mb-3 p-3 rounded" style="background: var(--bg-main);">
                        <?php if($l['inventory_image']): ?>
                        <img src="/public/assets/uploads/<?= htmlspecialchars($l['inventory_image']) ?>" class="rounded me-3" style="width: 50px; height: 50px; object-fit: cover;">
                        <?php endif; ?>
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($l['inventory_name']) ?></div>
                            <small class="text-muted"><?= (int)$l['quantity'] ?> unit</small>
                        </div>
                    </div>
                    
                    <?php if (!empty($l['note'])): ?>
                    <div class="p-3 rounded mb-3" style="background: var(--bg-main); border-left: 4px solid var(--primary);">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold"><i class="bi bi-pencil me-1"></i>Catatan Peminjaman</span>
                            <small class="text-muted"><?= date('d M Y H:i', strtotime($l['requested_at'])) ?></small>
                        </div>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($l['note'])) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($l['return_note'])): ?>
                    <div class="p-3 rounded" style="background: var(--bg-main); border-left: 4px solid var(--warning);">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold"><i class="bi bi-box-arrow-left me-1"></i>Catatan Pengembalian</span>
                            <small class="text-muted"><?= !empty($l['return_requested_at']) ? date('d M Y H:i', strtotime($l['return_requested_at'])) : '-' ?></small>
                        </div>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($l['return_note'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Single: Rejection Modal -->
    <?php if ($l['stage'] === 'rejected' && !empty($l['rejection_note'])): ?>
    <div class="modal fade" id="rejectionModal<?= $l['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Peminjaman Ditolak</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="rejection-reason-box">
                        <div class="reason-label"><i class="bi bi-exclamation-triangle me-1"></i>Alasan Penolakan</div>
                        <p class="reason-text"><?= nl2br(htmlspecialchars($l['rejection_note'])) ?></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <a href="/index.php?page=catalog" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Ajukan Baru</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Single: Return Request Modal -->
    <?php if ($l['stage'] === 'approved' && ($l['return_stage'] ?? 'none') === 'none'): ?>
    <div class="modal fade" id="returnModal<?= $l['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-box-arrow-left me-2"></i>Ajukan Pengembalian</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="request_return">
                        <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                        
                        <div class="mb-3 p-3 rounded" style="background: var(--bg-tertiary);">
                            <div class="d-flex align-items-center">
                                <?php if($l['inventory_image']): ?>
                                <img src="/public/assets/uploads/<?= htmlspecialchars($l['inventory_image']) ?>" class="rounded me-3" style="width: 60px; height: 60px; object-fit: cover;">
                                <?php endif; ?>
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($l['inventory_name']) ?></div>
                                    <div class="text-muted"><?= $l['quantity'] ?> unit</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Catatan Pengembalian (opsional)</label>
                            <textarea name="return_note" class="form-control" rows="3" placeholder="Tambahkan catatan jika diperlukan..."></textarea>
                            <small class="text-muted">Catatan ini akan tersimpan dan dapat Anda lihat kapan saja di riwayat.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-warning"><i class="bi bi-send me-1"></i>Ajukan Pengembalian</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php endforeach; ?>

<script>
function toggleGroup(groupId) {
    const detailRows = document.querySelectorAll(`tr[data-parent="${groupId}"]`);
    const chevron = document.getElementById(`chevron-${groupId}`);
    
    detailRows.forEach(row => {
        row.style.display = row.style.display === 'none' ? '' : 'none';
    });
    
    if (chevron) {
        chevron.classList.toggle('bi-chevron-right');
        chevron.classList.toggle('bi-chevron-down');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const filterButtons = document.querySelectorAll('[data-filter]');
    const loanRows = document.querySelectorAll('.loan-row');
    const detailRows = document.querySelectorAll('.group-detail-row');
    
    filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const filter = this.dataset.filter;
            filterButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            loanRows.forEach(row => {
                row.style.display = (filter === 'all' || row.classList.contains(filter)) ? '' : 'none';
            });
            
            detailRows.forEach(row => {
                const parentGroup = row.dataset.parent;
                const parentRow = document.querySelector(`tr[data-group="${parentGroup}"]`);
                if (parentRow && parentRow.style.display === 'none') {
                    row.style.display = 'none';
                }
            });
        });
    });
});
</script>

<style>
.nav-pills .nav-link { color: var(--text-secondary); border-radius: 8px; padding: 0.5rem 1rem; margin-right: 0.5rem; }
.nav-pills .nav-link:hover { background: var(--bg-tertiary); }
.nav-pills .nav-link.active { background: var(--primary); color: white; }
.group-header:hover { background: var(--bg-tertiary) !important; }
.group-detail-row { font-size: 0.9em; }
.group-chevron { transition: transform 0.2s; }
.btn-alasan { background: none; border: none; color: var(--danger); cursor: pointer; font-size: 12px; }
.btn-alasan:hover { text-decoration: underline; }
.rejection-reason-box { background: var(--bg-main); border-left: 4px solid var(--danger); padding: 16px; border-radius: 8px; }
.reason-label { font-weight: 600; color: var(--danger); margin-bottom: 8px; }
.reason-text { margin: 0; color: var(--text-primary); }

/* Pagination Styles - Gmail inspired */
.pagination-controls { display: flex; align-items: center; }
.pagination { margin-bottom: 0; }
.pagination .page-link {
    border: 1px solid #dee2e6;
    color: #5f6368;
    padding: 0.375rem 0.75rem;
    margin: 0 2px;
    border-radius: 6px;
    font-size: 0.875rem;
    transition: all 0.2s ease;
}
.pagination .page-link:hover {
    background-color: #f1f3f4;
    border-color: #dadce0;
    color: #202124;
}
.pagination .page-item.active .page-link {
    background-color: #1a73e8;
    border-color: #1a73e8;
    color: white;
    font-weight: 500;
}
.pagination .page-item.disabled .page-link {
    background-color: #fff;
    border-color: #dee2e6;
    color: #dadce0;
}
.card-header, .card-footer {
    padding: 0.75rem 1.25rem;
}
</style>
