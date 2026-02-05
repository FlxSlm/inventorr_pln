<?php
// app/admin/loan_tracking.php
// Detail tracking peminjaman - barang yang belum dikembalikan

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

$pageTitle = 'Tracking Peminjaman';
$pdo = require __DIR__ . '/../config/database.php';

// Fetch all active loans (approved but not fully returned)
$stmt = $pdo->query("
    SELECT l.*, 
           u.name as user_name, u.email as user_email, u.id as user_id,
           i.name as inventory_name, i.code as inventory_code, i.image as inventory_image,
           i.unit, i.item_condition
    FROM loans l
    JOIN users u ON u.id = l.user_id
    JOIN inventories i ON i.id = l.inventory_id
    WHERE l.stage = 'approved' 
      AND (l.return_stage IS NULL OR l.return_stage NOT IN ('return_approved'))
    ORDER BY l.approved_at DESC, l.user_id, l.inventory_id
");
$activeLoans = $stmt->fetchAll();

// Group by user
$loansByUser = [];
$loansByItem = [];

foreach ($activeLoans as $loan) {
    $userId = $loan['user_id'];
    $itemId = $loan['inventory_id'];
    
    // Group by user
    if (!isset($loansByUser[$userId])) {
        $loansByUser[$userId] = [
            'user_name' => $loan['user_name'],
            'user_email' => $loan['user_email'],
            'items' => [],
            'total_qty' => 0
        ];
    }
    $loansByUser[$userId]['items'][] = $loan;
    $loansByUser[$userId]['total_qty'] += $loan['quantity'];
    
    // Group by item
    if (!isset($loansByItem[$itemId])) {
        $loansByItem[$itemId] = [
            'inventory_name' => $loan['inventory_name'],
            'inventory_code' => $loan['inventory_code'],
            'inventory_image' => $loan['inventory_image'],
            'unit' => $loan['unit'],
            'borrowers' => [],
            'total_qty' => 0
        ];
    }
    $loansByItem[$itemId]['borrowers'][] = $loan;
    $loansByItem[$itemId]['total_qty'] += $loan['quantity'];
}

// Stats
$totalBorrowers = count($loansByUser);
$totalItemTypes = count($loansByItem);
$totalQuantity = array_sum(array_column($activeLoans, 'quantity'));

// === SERVER-SIDE SEARCH ===
$searchQuery = trim($_GET['search'] ?? '');
$filteredUserLoans = $loansByUser;

if (!empty($searchQuery)) {
    $searchLower = strtolower($searchQuery);
    $filteredUserLoans = array_filter($loansByUser, function($userData, $userId) use ($searchLower) {
        // Search in user name
        $userName = strtolower($userData['user_name'] ?? '');
        if (strpos($userName, $searchLower) !== false) {
            return true;
        }
        // Search in item names
        foreach ($userData['loans'] as $loan) {
            $itemName = strtolower($loan['inventory_name'] ?? '');
            $itemCode = strtolower($loan['inventory_code'] ?? '');
            if (strpos($itemName, $searchLower) !== false || strpos($itemCode, $searchLower) !== false) {
                return true;
            }
        }
        return false;
    }, ARRAY_FILTER_USE_BOTH);
}

// === PAGINATION ===
$itemsPerPage = 50;
$currentPage = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$totalBorrowersFiltered = count($filteredUserLoans);
$totalPages = max(1, ceil($totalBorrowersFiltered / $itemsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $itemsPerPage;
$usersToDisplay = array_slice($filteredUserLoans, $offset, $itemsPerPage, true);
$displayFrom = $totalBorrowersFiltered > 0 ? $offset + 1 : 0;
$displayTo = min($offset + $itemsPerPage, $totalBorrowersFiltered);

// URL builder for pagination
$buildPaginationUrl = function($pageNum) use ($searchQuery) {
    $params = ['page' => 'admin_loan_tracking', 'p' => $pageNum];
    if (!empty($searchQuery)) {
        $params['search'] = $searchQuery;
    }
    return '?' . http_build_query($params);
};
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-geo-alt-fill"></i> Tracking Peminjaman</h3>
        <p>Pantau lokasi barang dan siapa yang sedang meminjam</p>
    </div>
    <div class="page-header-actions">
        <a href="/index.php?page=admin_loans" class="btn btn-primary">
            <i class="bi bi-clipboard-check me-1"></i> Kelola Peminjaman
        </a>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card primary" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Total Peminjam Aktif</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $totalBorrowers ?></p>
            </div>
            <div class="stat-card-icon primary"><i class="bi bi-people"></i></div>
        </div>
    </div>
    <div class="stat-card info" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Jenis Barang Dipinjam</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $totalItemTypes ?></p>
            </div>
            <div class="stat-card-icon info"><i class="bi bi-box-seam"></i></div>
        </div>
    </div>
    <div class="stat-card warning" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Total Unit Dipinjam</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $totalQuantity ?></p>
            </div>
            <div class="stat-card-icon warning"><i class="bi bi-boxes"></i></div>
        </div>
    </div>
    <div class="stat-card success" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Transaksi Aktif</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= count($activeLoans) ?></p>
            </div>
            <div class="stat-card-icon success"><i class="bi bi-arrow-repeat"></i></div>
        </div>
    </div>
</div>

<!-- Toggle View -->
<div class="modern-card" style="margin-bottom: 24px;">
    <div class="card-body" style="padding: 16px 24px;">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <span style="color: var(--text-muted); font-size: 14px;"><i class="bi bi-eye me-1"></i>Tampilan:</span>
            <button class="btn btn-primary btn-sm active" id="viewByUser" onclick="toggleView('user')">
                <i class="bi bi-person me-1"></i> Per Peminjam
            </button>
            <button class="btn btn-secondary btn-sm" id="viewByItem" onclick="toggleView('item')">
                <i class="bi bi-box me-1"></i> Per Barang
            </button>
        </div>
    </div>
</div>

<!-- Search & Pagination -->
<div class="modern-card" style="margin-bottom: 24px;">
    <div class="card-body" style="padding: 16px 24px;">
        <!-- Search Form -->
        <form method="GET" action="" class="d-flex gap-2 mb-3">
            <input type="hidden" name="page" value="admin_loan_tracking">
            <div class="flex-grow-1">
                <div class="topbar-search" style="max-width: 100%;">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Cari berdasarkan nama karyawan atau nama barang..." 
                           value="<?= htmlspecialchars($searchQuery) ?>" style="width: 100%;">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-search me-1"></i>Cari
            </button>
            <?php if (!empty($searchQuery)): ?>
            <a href="?page=admin_loan_tracking" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle"></i>
            </a>
            <?php endif; ?>
        </form>
        
        <!-- Pagination Info -->
        <?php if ($totalBorrowersFiltered > 0): ?>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="text-muted small">
                <i class="bi bi-list-ul me-1"></i>
                Menampilkan <strong><?= $displayFrom ?></strong> - <strong><?= $displayTo ?></strong> dari <strong><?= $totalBorrowersFiltered ?></strong> peminjam
                <?php if (!empty($searchQuery)): ?>
                    <span class="text-primary"> (hasil pencarian)</span>
                <?php endif; ?>
            </div>
            
            <?php if ($totalPages > 1): ?>
            <nav aria-label="Pagination">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $buildPaginationUrl(1) ?>"><i class="bi bi-chevron-bar-left"></i></a>
                    </li>
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $buildPaginationUrl($currentPage - 1) ?>"><i class="bi bi-chevron-left"></i></a>
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
                        <a class="page-link" href="<?= $buildPaginationUrl($currentPage + 1) ?>"><i class="bi bi-chevron-right"></i></a>
                    </li>
                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $buildPaginationUrl($totalPages) ?>"><i class="bi bi-chevron-bar-right"></i></a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- View By User -->
<div id="viewUserSection">
    <?php if (empty($usersToDisplay)): ?>
    <div class="modern-card">
        <div class="card-body">
            <div class="empty-state">
                <div class="empty-state-icon"><i class="bi bi-<?= !empty($searchQuery) ? 'search' : 'check-circle' ?>"></i></div>
                <?php if (!empty($searchQuery)): ?>
                    <h5 class="empty-state-title">Tidak Ada Hasil</h5>
                    <p class="empty-state-text">Tidak ada peminjam yang cocok dengan pencarian "<?= htmlspecialchars($searchQuery) ?>".</p>
                    <a href="?page=admin_loan_tracking" class="btn btn-sm btn-outline-primary mt-2">
                        <i class="bi bi-x-circle me-1"></i>Hapus Filter
                    </a>
                <?php else: ?>
                    <h5 class="empty-state-title">Semua Barang Tersedia</h5>
                    <p class="empty-state-text">Tidak ada peminjaman yang sedang berlangsung.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="row g-4">
        <?php 
        $rowNum = $offset;
        foreach ($usersToDisplay as $userId => $data): 
            $rowNum++;
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="modern-card h-100">
                <div class="card-header" style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); color: #fff; padding: 16px 20px; border-radius: 12px 12px 0 0;">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width: 48px; height: 48px; background: rgba(255,255,255,0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 600;">
                            <?= strtoupper(substr($data['user_name'], 0, 1)) ?>
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 600; font-size: 16px;"><?= htmlspecialchars($data['user_name']) ?></div>
                            <small style="opacity: 0.8;"><?= htmlspecialchars($data['user_email']) ?></small>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 24px; font-weight: 700;"><?= $data['total_qty'] ?></div>
                            <small style="opacity: 0.8;">unit</small>
                        </div>
                    </div>
                </div>
                <div class="card-body" style="padding: 16px 20px; max-height: 300px; overflow-y: auto;">
                    <?php foreach ($data['items'] as $item): ?>
                    <div style="display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border-color);">
                        <?php if ($item['inventory_image']): ?>
                        <img src="/public/assets/uploads/<?= htmlspecialchars($item['inventory_image']) ?>" 
                             style="width: 45px; height: 45px; object-fit: cover; border-radius: 8px;">
                        <?php else: ?>
                        <div style="width: 45px; height: 45px; background: var(--bg-main); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-box-seam" style="color: var(--text-muted);"></i>
                        </div>
                        <?php endif; ?>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 600; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?= htmlspecialchars($item['inventory_name']) ?>
                            </div>
                            <small style="color: var(--text-muted);">
                                <?= htmlspecialchars($item['inventory_code']) ?>
                            </small>
                        </div>
                        <div style="text-align: right;">
                            <span class="badge bg-primary"><?= $item['quantity'] ?> <?= htmlspecialchars($item['unit'] ?? 'unit') ?></span>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                                <?= date('d M Y', strtotime($item['loan_date'] ?? $item['requested_at'])) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="padding: 12px 20px; border-top: 1px solid var(--border-color);">
                    <small style="color: var(--text-muted);">
                        <i class="bi bi-info-circle me-1"></i><?= count($data['items']) ?> jenis barang dipinjam
                    </small>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- View By Item -->
<div id="viewItemSection" style="display: none;">
    <?php if (empty($loansByItem)): ?>
    <div class="modern-card">
        <div class="card-body">
            <div class="empty-state">
                <div class="empty-state-icon"><i class="bi bi-check-circle"></i></div>
                <h5 class="empty-state-title">Semua Barang Tersedia</h5>
                <p class="empty-state-text">Tidak ada peminjaman yang sedang berlangsung.</p>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="table-card">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Barang</th>
                        <th>Total Dipinjam</th>
                        <th>Peminjam</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($loansByItem as $itemId => $data): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <?php if ($data['inventory_image']): ?>
                                <img src="/public/assets/uploads/<?= htmlspecialchars($data['inventory_image']) ?>" 
                                     style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px;">
                                <?php else: ?>
                                <div style="width: 50px; height: 50px; background: var(--bg-main); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-box-seam" style="color: var(--text-muted);"></i>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight: 600;"><?= htmlspecialchars($data['inventory_name']) ?></div>
                                    <small style="color: var(--text-muted);"><?= htmlspecialchars($data['inventory_code']) ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-warning" style="font-size: 14px; padding: 8px 12px;">
                                <?= $data['total_qty'] ?> <?= htmlspecialchars($data['unit'] ?? 'unit') ?>
                            </span>
                        </td>
                        <td>
                            <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                <?php 
                                $uniqueBorrowers = [];
                                foreach ($data['borrowers'] as $b) {
                                    $uniqueBorrowers[$b['user_id']] = $b['user_name'];
                                }
                                foreach ($uniqueBorrowers as $uId => $uName): 
                                ?>
                                <span class="badge bg-secondary"><?= htmlspecialchars($uName) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#itemDetailModal<?= $itemId ?>">
                                <i class="bi bi-eye me-1"></i> Detail
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Item Detail Modals -->
    <?php foreach ($loansByItem as $itemId => $data): ?>
    <div class="modal fade" id="itemDetailModal<?= $itemId ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-box-seam me-2" style="color: var(--primary-light);"></i><?= htmlspecialchars($data['inventory_name']) ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div style="margin-bottom: 16px; padding: 12px; background: var(--bg-main); border-radius: 8px; text-align: center;">
                        <div style="font-size: 32px; font-weight: 700; color: var(--warning);"><?= $data['total_qty'] ?></div>
                        <div style="color: var(--text-muted);"><?= htmlspecialchars($data['unit'] ?? 'unit') ?> sedang dipinjam</div>
                    </div>
                    
                    <h6 style="margin-bottom: 12px; color: var(--text-dark);">
                        <i class="bi bi-people me-1"></i>Daftar Peminjam:
                    </h6>
                    
                    <?php foreach ($data['borrowers'] as $borrower): ?>
                    <div style="display: flex; align-items: center; gap: 12px; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 8px;">
                        <div style="width: 40px; height: 40px; background: var(--primary-light); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600;">
                            <?= strtoupper(substr($borrower['user_name'], 0, 1)) ?>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-weight: 600;"><?= htmlspecialchars($borrower['user_name']) ?></div>
                            <small style="color: var(--text-muted);">
                                Sejak <?= date('d M Y', strtotime($borrower['loan_date'] ?? $borrower['requested_at'])) ?>
                            </small>
                        </div>
                        <span class="badge bg-primary"><?= $borrower['quantity'] ?> <?= htmlspecialchars($data['unit'] ?? 'unit') ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function toggleView(view) {
    const userSection = document.getElementById('viewUserSection');
    const itemSection = document.getElementById('viewItemSection');
    const btnUser = document.getElementById('viewByUser');
    const btnItem = document.getElementById('viewByItem');
    
    if (view === 'user') {
        userSection.style.display = '';
        itemSection.style.display = 'none';
        btnUser.classList.remove('btn-secondary');
        btnUser.classList.add('btn-primary');
        btnItem.classList.remove('btn-primary');
        btnItem.classList.add('btn-secondary');
    } else {
        userSection.style.display = 'none';
        itemSection.style.display = '';
        btnItem.classList.remove('btn-secondary');
        btnItem.classList.add('btn-primary');
        btnUser.classList.remove('btn-primary');
        btnUser.classList.add('btn-secondary');
    }
}
</script>
