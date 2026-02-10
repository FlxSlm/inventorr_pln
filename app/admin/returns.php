<?php
// app/admin/returns.php - Simplified Workflow with Grouped Transactions
// Admin approves return once + uploads BAST return document
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';

$msg = $_GET['msg'] ?? '';
$errors = [];
$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $group_id = $_POST['group_id'] ?? '';
    $loan_id = (int)($_POST['loan_id'] ?? 0);
    
    // APPROVE RETURN with BAST upload (single approval)
    if ($action === 'approve_return' && ($group_id || $loan_id)) {
        $pdo->beginTransaction();
        try {
            // Handle BAST return document upload
            $adminDocPath = null;
            if (isset($_FILES['bast_document']) && $_FILES['bast_document']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['bast_document'];
                $allowedExt = ['pdf', 'xlsx', 'xls', 'doc', 'docx'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($ext, $allowedExt)) {
                    throw new Exception('Format dokumen tidak valid. Hanya PDF, Excel, Word.');
                }
                if ($file['size'] > 10 * 1024 * 1024) {
                    throw new Exception('Ukuran file maksimal 10MB.');
                }
                
                $uploadDir = __DIR__ . '/../../public/assets/uploads/documents/bast/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                
                $filename = 'bast_return_' . ($group_id ?: $loan_id) . '_' . time() . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                    throw new Exception('Gagal mengupload dokumen.');
                }
                $adminDocPath = 'uploads/documents/bast/' . $filename;
            }
            
            // Get loans to return
            if ($group_id) {
                $stmt = $pdo->prepare('SELECT l.*, i.name as inventory_name FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.group_id = ? AND l.return_stage = "pending_return" FOR UPDATE');
                $stmt->execute([$group_id]);
            } else {
                $stmt = $pdo->prepare('SELECT l.*, i.name as inventory_name FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.id = ? AND l.return_stage = "pending_return" FOR UPDATE');
                $stmt->execute([$loan_id]);
            }
            $loans = $stmt->fetchAll();
            
            if (empty($loans)) {
                throw new Exception('Pengembalian tidak ditemukan atau sudah diproses.');
            }
            
            $userId = $loans[0]['user_id'];
            $itemNames = [];
            
            foreach ($loans as $loan) {
                // Return stock
                $stmt = $pdo->prepare('UPDATE inventories SET stock_available = stock_available + ? WHERE id = ?');
                $stmt->execute([$loan['quantity'], $loan['inventory_id']]);
                
                // Update loan status
                $stmt = $pdo->prepare('UPDATE loans SET return_stage = "return_approved", status = "returned", returned_at = NOW(), return_approved_by = ?, return_approved_at = NOW(), return_admin_document_path = ? WHERE id = ?');
                $stmt->execute([$_SESSION['user']['id'], $adminDocPath, $loan['id']]);
                
                $itemNames[] = $loan['inventory_name'] . ' (' . $loan['quantity'] . ' unit)';
            }
            
            // Create notification
            $notifTitle = 'Pengembalian Disetujui';
            $notifMessage = 'Pengembalian Anda untuk ' . implode(', ', $itemNames) . ' telah disetujui. Terima kasih!';
            if ($adminDocPath) {
                $notifMessage .= ' Dokumen BAST tersedia untuk diunduh.';
            }
            
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type) VALUES (?, 'return_approved', ?, ?, ?, 'loan')");
            $stmt->execute([$userId, $notifTitle, $notifMessage, $group_id ?: $loan_id]);
            
            $pdo->commit();
            $success = 'Pengembalian berhasil disetujui. Stok telah dikembalikan.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
    
    // REJECT RETURN
    elseif ($action === 'reject_return' && ($group_id || $loan_id)) {
        $rejection_note = trim($_POST['rejection_note'] ?? '');
        
        if ($group_id) {
            $stmt = $pdo->prepare('SELECT l.*, i.name as inventory_name FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.group_id = ?');
            $stmt->execute([$group_id]);
        } else {
            $stmt = $pdo->prepare('SELECT l.*, i.name as inventory_name FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.id = ?');
            $stmt->execute([$loan_id]);
        }
        $loans = $stmt->fetchAll();
        
        if (!empty($loans)) {
            $userId = $loans[0]['user_id'];
            $itemNames = [];
            
            foreach ($loans as $loan) {
                $stmt = $pdo->prepare('UPDATE loans SET return_stage = "return_rejected", return_rejection_note = ?, rejected_by = ? WHERE id = ?');
                $stmt->execute([$rejection_note, $_SESSION['user']['id'], $loan['id']]);
                $itemNames[] = $loan['inventory_name'];
            }
            
            $notifMsg = 'Pengembalian Anda untuk ' . implode(', ', $itemNames) . ' telah ditolak.';
            if ($rejection_note) $notifMsg .= ' Alasan: ' . $rejection_note;
            
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type) VALUES (?, 'return_rejected', ?, ?, ?, 'loan')");
            $stmt->execute([$userId, 'Pengembalian Ditolak', $notifMsg, $group_id ?: $loan_id]);
            
            $success = 'Pengembalian ditolak.';
        }
    }
}

// Fetch all loans with return requests (return_stage not null and not 'none')
$stmt = $pdo->query("
    SELECT l.*, u.name AS user_name, u.email AS user_email, 
           i.name AS inventory_name, i.code AS inventory_code, i.image AS inventory_image,
           i.unit, i.item_condition, i.stock_available,
           ua.name AS return_approved_by_name, ur.name AS rejected_by_name
    FROM loans l
    JOIN users u ON u.id = l.user_id
    JOIN inventories i ON i.id = l.inventory_id
    LEFT JOIN users ua ON ua.id = l.return_approved_by
    LEFT JOIN users ur ON ur.id = l.rejected_by
    WHERE l.return_stage IS NOT NULL AND l.return_stage != 'none'
    ORDER BY l.return_requested_at DESC, l.group_id, l.id
");
$allReturns = $stmt->fetchAll();

// Group returns by group_id
$groupedReturns = [];
foreach ($allReturns as $loan) {
    if (!empty($loan['group_id'])) {
        if (!isset($groupedReturns[$loan['group_id']])) {
            $groupedReturns[$loan['group_id']] = [
                'type' => 'group',
                'group_id' => $loan['group_id'],
                'user_id' => $loan['user_id'],
                'user_name' => $loan['user_name'],
                'user_email' => $loan['user_email'],
                'return_requested_at' => $loan['return_requested_at'],
                'return_stage' => $loan['return_stage'],
                'items' => [],
                'total_quantity' => 0,
                'return_note' => $loan['return_note'] ?? null,
                'return_rejection_note' => $loan['return_rejection_note'] ?? null,
                'return_admin_document_path' => $loan['return_admin_document_path'] ?? null,
                'returned_at' => $loan['returned_at'] ?? null,
                'return_approved_by_name' => $loan['return_approved_by_name'] ?? null,
                'rejected_by_name' => $loan['rejected_by_name'] ?? null
            ];
        }
        $groupedReturns[$loan['group_id']]['items'][] = $loan;
        $groupedReturns[$loan['group_id']]['total_quantity'] += $loan['quantity'];
        // Use first non-empty note
        if (!empty($loan['return_note']) && empty($groupedReturns[$loan['group_id']]['return_note'])) {
            $groupedReturns[$loan['group_id']]['return_note'] = $loan['return_note'];
        }
    } else {
        $groupedReturns['single_' . $loan['id']] = [
            'type' => 'single',
            'group_id' => null,
            'loan_id' => $loan['id'],
            'user_id' => $loan['user_id'],
            'user_name' => $loan['user_name'],
            'user_email' => $loan['user_email'],
            'return_requested_at' => $loan['return_requested_at'],
            'return_stage' => $loan['return_stage'],
            'items' => [$loan],
            'total_quantity' => $loan['quantity'],
            'return_note' => $loan['return_note'] ?? null,
            'return_rejection_note' => $loan['return_rejection_note'] ?? null,
            'return_admin_document_path' => $loan['return_admin_document_path'] ?? null,
            'returned_at' => $loan['returned_at'] ?? null,
            'return_approved_by_name' => $loan['return_approved_by_name'] ?? null,
            'rejected_by_name' => $loan['rejected_by_name'] ?? null
        ];
    }
}

// Count stats (unfiltered)
$totalPendingCount = count(array_filter($groupedReturns, fn($g) => $g['return_stage'] === 'pending_return'));
$totalApprovedCount = count(array_filter($groupedReturns, fn($g) => $g['return_stage'] === 'return_approved'));
$totalRejectedCount = count(array_filter($groupedReturns, fn($g) => $g['return_stage'] === 'return_rejected'));

// === SERVER-SIDE SEARCH ===
$searchQuery = trim($_GET['search'] ?? '');
$filteredReturns = $groupedReturns;

if (!empty($searchQuery)) {
    $searchLower = strtolower($searchQuery);
    $filteredReturns = array_filter($groupedReturns, function($return) use ($searchLower) {
        $userName = strtolower($return['user_name'] ?? '');
        $userEmail = strtolower($return['user_email'] ?? '');
        $itemNames = '';
        foreach ($return['items'] as $item) {
            $itemNames .= strtolower($item['inventory_name'] ?? '') . ' ';
            $itemNames .= strtolower($item['inventory_code'] ?? '') . ' ';
        }
        return (
            strpos($userName, $searchLower) !== false ||
            strpos($userEmail, $searchLower) !== false ||
            strpos($itemNames, $searchLower) !== false
        );
    });
}

// === PAGINATION ===
$itemsPerPage = 50;
$currentPage = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$totalReturns = count($filteredReturns);
$totalPages = max(1, ceil($totalReturns / $itemsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $itemsPerPage;
$returnsToDisplay = array_slice($filteredReturns, $offset, $itemsPerPage);
$displayFrom = $totalReturns > 0 ? $offset + 1 : 0;
$displayTo = min($offset + $itemsPerPage, $totalReturns);

// URL builder for pagination
$buildPaginationUrl = function($pageNum) use ($searchQuery) {
    $params = ['page' => 'admin_returns', 'p' => $pageNum];
    if (!empty($searchQuery)) {
        $params['search'] = $searchQuery;
    }
    return '?' . http_build_query($params);
};

// Stats for display
$pendingCount = $totalPendingCount;
$approvedCount = $totalApprovedCount;
$rejectedCount = $totalRejectedCount;

// Fetch active BAST template for returns
$bastTemplate = null;
try {
    $bastTemplate = $pdo->query("SELECT * FROM document_templates WHERE template_type = 'return' AND is_active = 1 LIMIT 1")->fetch();
} catch (Exception $e) {}

$returnStageLabels = [
    'pending_return' => ['Menunggu Validasi', 'warning', 'hourglass'],
    'return_approved' => ['Dikembalikan', 'success', 'check-circle'],
    'return_rejected' => ['Ditolak', 'danger', 'x-circle']
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title"><i class="bi bi-box-arrow-in-left me-2"></i>Kelola Pengembalian</h1>
        <p class="text-muted mb-0">Kelola permintaan pengembalian barang dari karyawan</p>
    </div>
</div>

<!-- Alerts -->
<?php if($msg || $success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($msg ?: $success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php foreach($errors as $e): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #f59e0b, #fbbf24); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-hourglass-split" style="color: #fff; font-size: 20px;"></i>
                </div>
                <div>
                    <div style="font-size: 24px; font-weight: 700;"><?= $pendingCount ?></div>
                    <div style="font-size: 13px; color: var(--text-muted);">Menunggu Validasi</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #10b981, #34d399); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-check-circle" style="color: #fff; font-size: 20px;"></i>
                </div>
                <div>
                    <div style="font-size: 24px; font-weight: 700;"><?= $approvedCount ?></div>
                    <div style="font-size: 13px; color: var(--text-muted);">Dikembalikan</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #ef4444, #f87171); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-x-circle" style="color: #fff; font-size: 20px;"></i>
                </div>
                <div>
                    <div style="font-size: 24px; font-weight: 700;"><?= $rejectedCount ?></div>
                    <div style="font-size: 13px; color: var(--text-muted);">Ditolak</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Returns Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="bi bi-list-ul me-2"></i>Daftar Pengembalian</h5>
        <div class="table-filters" style="padding: 0;">
            <button class="table-filter-btn active" data-filter="all">Semua</button>
            <button class="table-filter-btn" data-filter="pending_return">Pending</button>
            <button class="table-filter-btn" data-filter="return_approved">Selesai</button>
        </div>
    </div>
    
    <!-- Pagination Info Header -->
    <?php if (!empty($groupedReturns)): ?>
    <div style="padding: 12px 24px; border-bottom: 1px solid var(--border-color); background: var(--bg-white);">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="text-muted small">
                <i class="bi bi-list-ul me-1"></i>
                Menampilkan <strong><?= $displayFrom ?></strong> - <strong><?= $displayTo ?></strong> dari <strong><?= $totalReturns ?></strong> data
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
                        <li class="page-item"><a class="page-link" href="<?= $buildPaginationUrl($totalPages) ?>">><?= $totalPages ?></a></li>
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
    </div>
    <?php endif; ?>
    
    <!-- Server-Side Search Form -->
    <div style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); background: var(--bg-main);">
        <form method="GET" action="" class="d-flex gap-2">
            <input type="hidden" name="page" value="admin_returns">
            <div class="flex-grow-1">
                <div class="topbar-search" style="max-width: 100%;">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Cari berdasarkan nama karyawan, email, atau nama barang..." 
                           value="<?= htmlspecialchars($searchQuery) ?>" style="width: 100%;">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-search me-1"></i>Cari
            </button>
            <?php if (!empty($searchQuery)): ?>
            <a href="?page=admin_returns" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle"></i>
            </a>
            <?php endif; ?>
        </form>
    </div>
    
    <div class="card-body p-0">
        <?php if (empty($returnsToDisplay)): ?>
        <div class="text-center py-5">
            <div class="empty-state">
                <i class="bi bi-inbox" style="font-size: 48px; color: var(--text-muted);"></i>
                <?php if (!empty($searchQuery)): ?>
                    <h5>Tidak Ada Hasil</h5>
                    <p class="text-muted">Tidak ada pengembalian yang cocok dengan pencarian "<?= htmlspecialchars($searchQuery) ?>".</p>
                    <a href="?page=admin_returns" class="btn btn-sm btn-outline-primary mt-2">
                        <i class="bi bi-x-circle me-1"></i>Hapus Filter
                    </a>
                <?php else: ?>
                    <h5>Belum Ada Pengembalian</h5>
                    <p class="text-muted">Belum ada permintaan pengembalian dari karyawan.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="returnsTable">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Peminjam</th>
                        <th>Barang</th>
                        <th>Qty</th>
                        <th>Catatan</th>
                        <th>Tanggal Pengajuan</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="returnsTableBody">
                    <?php 
                    $totalRows = count($groupedReturns);
                    $rowNum = $offset;
                    foreach($returnsToDisplay as $key => $group): 
                        $rowNum++;
                        $displayNum = $totalRows - $rowNum + 1; // Oldest gets highest number, newest gets 1
                        $filterClass = $group['return_stage'];
                        $isMulti = count($group['items']) > 1;
                        $stageInfo = $returnStageLabels[$group['return_stage']] ?? ['Unknown', 'secondary', 'question'];
                    ?>
                    <!-- Group Header Row -->
                    <tr class="group-header" data-status="<?= $filterClass ?>" data-group="<?= $key ?>" <?= $isMulti ? 'style="cursor:pointer;" onclick="toggleGroup(\'' . $key . '\')"' : '' ?>>
                        <td><span class="badge bg-secondary"><?= $displayNum ?></span></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="avatar me-2"><?= strtoupper(substr($group['user_name'], 0, 1)) ?></div>
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($group['user_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($group['user_email']) ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($isMulti): ?>
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-chevron-right group-chevron" id="chevron-<?= $key ?>"></i>
                                <div>
                                    <span class="badge bg-primary"><?= count($group['items']) ?> barang</span>
                                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                        <?= htmlspecialchars($group['items'][0]['inventory_name']) ?><?= count($group['items']) > 1 ? ', ...' : '' ?>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="d-flex align-items-center">
                                <?php $item = $group['items'][0]; ?>
                                <?php if ($item['inventory_image']): ?>
                                <img src="/public/assets/uploads/<?= htmlspecialchars($item['inventory_image']) ?>" alt="" class="rounded me-2" style="width: 40px; height: 40px; object-fit: cover;">
                                <?php else: ?>
                                <div class="rounded me-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; background: var(--bg-main);">
                                    <i class="bi bi-box-seam text-muted"></i>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($item['inventory_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($item['inventory_code']) ?></small>
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-primary"><?= $group['total_quantity'] ?></span></td>
                        <td>
                            <?php if (!empty($group['return_note'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#noteModal<?= $key ?>" onclick="event.stopPropagation();">
                                <i class="bi bi-chat-text me-1"></i>Lihat
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($group['return_requested_at']): ?>
                            <div><?= date('d M Y', strtotime($group['return_requested_at'])) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($group['return_requested_at'])) ?></small>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $stageInfo[1] ?>">
                                <i class="bi bi-<?= $stageInfo[2] ?> me-1"></i><?= $stageInfo[0] ?>
                            </span>
                            <?php if($group['return_stage'] === 'return_approved' && !empty($group['return_approved_by_name'])): ?>
                            <br><small class="text-muted">oleh <?= htmlspecialchars($group['return_approved_by_name']) ?></small>
                            <?php if(!empty($group['returned_at'])): ?>
                            <br><small class="text-muted"><?= date('d/m/Y H:i', strtotime($group['returned_at'])) ?></small>
                            <?php endif; ?>
                            <?php elseif($group['return_stage'] === 'return_rejected' && !empty($group['rejected_by_name'])): ?>
                            <br><small class="text-muted">oleh <?= htmlspecialchars($group['rejected_by_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($group['return_stage'] === 'pending_return'): ?>
                            <div class="btn-group btn-group-sm" onclick="event.stopPropagation();">
                                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal<?= $key ?>">
                                    <i class="bi bi-check-lg me-1"></i>Approve
                                </button>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $key ?>">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <?php elseif($group['return_stage'] === 'return_approved' && !empty($group['return_admin_document_path'])): ?>
                            <a href="/public/assets/<?= htmlspecialchars($group['return_admin_document_path']) ?>" target="_blank" class="btn btn-outline-primary btn-sm" onclick="event.stopPropagation();">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i>BAST
                            </a>
                            <?php elseif($group['return_stage'] === 'return_rejected'): ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#rejectionModal<?= $key ?>" onclick="event.stopPropagation();">
                                <i class="bi bi-info-circle me-1"></i>Alasan
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <!-- Detail Rows for Multi-item -->
                    <?php if ($isMulti): ?>
                    <?php foreach($group['items'] as $idx => $item): ?>
                    <tr class="group-detail-row" data-parent="<?= $key ?>" data-status="<?= $filterClass ?>" style="display: none; background: var(--bg-secondary);">
                        <td></td>
                        <td></td>
                        <td>
                            <div class="d-flex align-items-center gap-3" style="padding-left: 20px;">
                                <?php if ($item['inventory_image']): ?>
                                <img src="/public/assets/uploads/<?= htmlspecialchars($item['inventory_image']) ?>" alt="" class="rounded" style="width: 50px; height: 50px; object-fit: cover;">
                                <?php else: ?>
                                <div class="rounded d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; background: var(--bg-main);">
                                    <i class="bi bi-box-seam text-muted" style="font-size: 18px;"></i>
                                </div>
                                <?php endif; ?>
                                <div style="flex: 1;">
                                    <div style="font-weight: 600; font-size: 14px;"><?= htmlspecialchars($item['inventory_name']) ?></div>
                                    <div style="display: flex; gap: 12px; margin-top: 4px; flex-wrap: wrap;">
                                        <small style="color: var(--text-muted);"><i class="bi bi-upc me-1"></i><?= htmlspecialchars($item['inventory_code']) ?></small>
                                    </div>
                                    <div style="display: flex; gap: 8px; margin-top: 6px;">
                                        <?php if (!empty($item['item_condition']) && $item['item_condition'] !== 'Baik'): ?>
                                        <span class="badge bg-warning" style="font-size: 10px;"><?= htmlspecialchars($item['item_condition']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td><span style="font-weight: 600; font-size: 16px;"><?= $item['quantity'] ?></span> <small style="color: var(--text-muted);"><?= htmlspecialchars($item['unit'] ?? 'unit') ?></small></td>
                        <td colspan="4"></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modals -->
<?php foreach($groupedReturns as $key => $group): ?>

<!-- Note Modal -->
<?php if (!empty($group['return_note'])): ?>
<div class="modal fade" id="noteModal<?= $key ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-chat-left-text me-2"></i>Catatan Pengembalian dari <?= htmlspecialchars($group['user_name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Barang yang dikembalikan:</label>
                    <?php foreach($group['items'] as $item): ?>
                    <p class="mb-1">&bull; <?= htmlspecialchars($item['inventory_name']) ?> (<?= $item['quantity'] ?> unit)</p>
                    <?php endforeach; ?>
                </div>
                <div class="p-3 rounded" style="background: var(--bg-main); border-left: 4px solid var(--primary-light);">
                    <label class="form-label fw-semibold">Catatan:</label>
                    <p class="mb-0"><?= nl2br(htmlspecialchars($group['return_note'])) ?></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Approve Return Modal -->
<?php if ($group['return_stage'] === 'pending_return'): ?>
<div class="modal fade" id="approveModal<?= $key ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border: none; border-radius: 16px; overflow: hidden;">
            <!-- Gradient Header -->
            <div class="modal-header" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: none; padding: 24px 28px;">
                <div class="d-flex align-items-center gap-3">
                    <div style="width: 48px; height: 48px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                        <i class="bi bi-check-circle-fill" style="color: #fff; font-size: 24px;"></i>
                    </div>
                    <div>
                        <h5 class="modal-title" style="color: #fff; font-weight: 600; margin: 0;">Setujui Pengembalian</h5>
                        <small style="color: rgba(255,255,255,0.8);">Verifikasi dan selesaikan proses pengembalian</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body" style="padding: 28px;">
                    <input type="hidden" name="action" value="approve_return">
                    <?php if ($group['type'] === 'group'): ?>
                    <input type="hidden" name="group_id" value="<?= htmlspecialchars($group['group_id']) ?>">
                    <?php $docRefId = $group['group_id']; ?>
                    <?php else: ?>
                    <input type="hidden" name="loan_id" value="<?= $group['items'][0]['id'] ?>">
                    <?php $docRefId = 'single_' . $group['items'][0]['id']; ?>
                    <?php endif; ?>
                    
                    <!-- Borrower Info Card -->
                    <div style="background: linear-gradient(135deg, var(--bg-main) 0%, var(--bg-card) 100%); border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--border-color);">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="avatar" style="width: 44px; height: 44px; font-size: 18px;"><?= strtoupper(substr($group['user_name'], 0, 1)) ?></div>
                            <div>
                                <h6 style="margin: 0; font-weight: 600;"><?= htmlspecialchars($group['user_name']) ?></h6>
                                <small class="text-muted"><?= htmlspecialchars($group['user_email']) ?></small>
                            </div>
                        </div>
                        
                        <!-- Items List -->
                        <div style="background: var(--bg-card); border-radius: 8px; overflow: hidden;">
                            <div style="padding: 12px 16px; background: rgba(16, 185, 129, 0.1); border-bottom: 1px solid var(--border-color);">
                                <strong style="color: var(--success);"><i class="bi bi-box-seam me-2"></i>Barang yang Dikembalikan</strong>
                            </div>
                            <?php foreach($group['items'] as $idx => $item): ?>
                            <div style="padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; <?= $idx < count($group['items']) - 1 ? 'border-bottom: 1px solid var(--border-color);' : '' ?>">
                                <div class="d-flex align-items-center gap-3">
                                    <?php if (!empty($item['inventory_image'])): ?>
                                    <img src="/public/assets/uploads/<?= htmlspecialchars($item['inventory_image']) ?>" alt="" style="width: 40px; height: 40px; object-fit: cover; border-radius: 8px;">
                                    <?php else: ?>
                                    <div style="width: 40px; height: 40px; background: var(--bg-main); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                        <i class="bi bi-box-seam text-muted"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight: 500;"><?= htmlspecialchars($item['inventory_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($item['inventory_code'] ?? '-') ?></small>
                                    </div>
                                </div>
                                <span class="badge bg-primary" style="font-size: 14px; padding: 8px 12px;"><?= $item['quantity'] ?> unit</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($group['return_note'])): ?>
                    <!-- Return Note -->
                    <div style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(37, 99, 235, 0.05) 100%); border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; border-left: 4px solid #3b82f6;">
                        <div class="d-flex align-items-start gap-3">
                            <i class="bi bi-chat-quote-fill" style="color: #3b82f6; font-size: 20px; margin-top: 2px;"></i>
                            <div>
                                <strong style="color: #3b82f6;">Catatan dari Peminjam</strong>
                                <p style="margin: 8px 0 0 0; color: var(--text-dark);"><?= nl2br(htmlspecialchars($group['return_note'])) ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Document Generation Section -->
                    <div style="background: var(--bg-main); border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 2px dashed var(--border-color);">
                        <label class="form-label fw-semibold d-flex align-items-center gap-2">
                            <i class="bi bi-file-earmark-text" style="font-size: 20px; color: var(--primary);"></i>
                            Dokumen BAST Pengembalian
                        </label>
                        <div class="d-flex gap-2 flex-wrap mb-3 mt-3">
                            <a href="/index.php?page=admin_generate_document&type=return&ref=<?= urlencode($docRefId) ?>" target="_blank" class="btn btn-primary">
                                <i class="bi bi-file-earmark-plus me-1"></i> Generate & Download Document
                            </a>
                        </div>
                        <div class="mt-2 pt-2" style="border-top: 1px solid var(--border-color);">
                            <label class="form-label" style="font-size: 13px; margin-bottom: 6px;">
                                <i class="bi bi-upload me-1"></i>Upload Dokumen BAST <span class="text-danger">*</span>
                            </label>
                            <input type="file" name="bast_document" class="form-control form-control-sm" accept=".pdf,.xlsx,.xls,.doc,.docx" required>
                            <small class="text-muted d-block mt-2">Format: PDF, Excel, Word. Maksimal 10MB. <strong class="text-danger">Wajib diupload!</strong></small>
                        </div>
                    </div>
                    
                    <!-- Info Alert -->
                    <div style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(217, 119, 6, 0.05) 100%); border-radius: 12px; padding: 16px 20px; display: flex; align-items: center; gap: 12px;">
                        <i class="bi bi-exclamation-triangle-fill" style="color: var(--warning); font-size: 20px;"></i>
                        <span><strong>Wajib upload dokumen BAST!</strong> Dengan menyetujui, stok barang akan dikembalikan secara otomatis ke inventaris.</span>
                    </div>
                </div>
                <div class="modal-footer" style="padding: 20px 28px; border-top: 1px solid var(--border-color);">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i>Batal
                    </button>
                    <button type="submit" class="btn btn-success" style="padding: 10px 24px;">
                        <i class="bi bi-check-lg me-1"></i>Setujui Pengembalian
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Return Modal -->
<div class="modal fade" id="rejectModal<?= $key ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-x-circle me-2" style="color: var(--danger);"></i>Tolak Pengembalian</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reject_return">
                    <?php if ($group['type'] === 'group'): ?>
                    <input type="hidden" name="group_id" value="<?= htmlspecialchars($group['group_id']) ?>">
                    <?php else: ?>
                    <input type="hidden" name="loan_id" value="<?= $group['items'][0]['id'] ?>">
                    <?php endif; ?>
                    
                    <div style="margin-bottom: 16px; padding: 16px; background: var(--bg-main); border-radius: var(--radius);">
                        <strong><?= htmlspecialchars($group['user_name']) ?></strong> mengajukan pengembalian:
                        <?php foreach($group['items'] as $item): ?>
                        <div class="mt-1">&bull; <?= htmlspecialchars($item['inventory_name']) ?> (<?= $item['quantity'] ?> unit)</div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Alasan Penolakan <span class="text-danger">*</span></label>
                        <textarea name="rejection_note" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg me-1"></i>Tolak</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Rejection Reason Modal -->
<?php if ($group['return_stage'] === 'return_rejected' && !empty($group['return_rejection_note'])): ?>
<div class="modal fade" id="rejectionModal<?= $key ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Alasan Penolakan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="p-3 rounded" style="background: var(--bg-main); border-left: 4px solid var(--danger);">
                    <?= nl2br(htmlspecialchars($group['return_rejection_note'])) ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endforeach; ?>

<style>
.avatar { width: 36px; height: 36px; background: var(--primary-light); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; }
.group-chevron { transition: transform 0.2s ease; }
.group-chevron.expanded { transform: rotate(90deg); }
.group-detail-row td { padding: 8px 12px !important; }
</style>

<script>
function toggleGroup(groupId) {
    const chevron = document.getElementById('chevron-' + groupId);
    const detailRows = document.querySelectorAll(`tr[data-parent="${groupId}"]`);
    
    chevron.classList.toggle('expanded');
    detailRows.forEach(row => {
        row.style.display = row.style.display === 'none' ? '' : 'none';
    });
}

document.querySelectorAll('.table-filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.table-filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        applyAllFilters();
    });
});

// Status filter only (client-side)
function applyStatusFilter() {
    const activeFilter = document.querySelector('.table-filter-btn.active')?.dataset.filter || 'all';
    
    document.querySelectorAll('#returnsTableBody tr.group-header').forEach(row => {
        const status = row.dataset.status;
        const groupId = row.dataset.group;
        
        if (activeFilter === 'all' || status === activeFilter) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
            // Hide detail rows too
            document.querySelectorAll(`tr[data-parent="${groupId}"]`).forEach(dr => dr.style.display = 'none');
        }
    });
}

// Status filter buttons
document.querySelectorAll('.table-filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.table-filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        applyStatusFilter();
    });
});
</script>
