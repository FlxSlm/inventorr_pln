<?php
// app/admin/requests.php - Simplified Workflow with Grouped Transactions
// Admin approves once + uploads BAST document for permanent item requests
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
    $request_id = (int)($_POST['request_id'] ?? 0);
    
    // APPROVE with BAST upload (single approval)
    if ($action === 'approve' && ($group_id || $request_id)) {
        $pdo->beginTransaction();
        try {
            // Handle BAST document upload
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
                
                $filename = 'bast_request_' . ($group_id ?: $request_id) . '_' . time() . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                    throw new Exception('Gagal mengupload dokumen.');
                }
                $adminDocPath = 'uploads/documents/bast/' . $filename;
            }
            
            // Get requests to approve
            if ($group_id) {
                $stmt = $pdo->prepare('SELECT r.*, i.stock_total, i.stock_available, i.name as inventory_name FROM requests r JOIN inventories i ON i.id = r.inventory_id WHERE r.group_id = ? AND r.stage = "pending" FOR UPDATE');
                $stmt->execute([$group_id]);
            } else {
                $stmt = $pdo->prepare('SELECT r.*, i.stock_total, i.stock_available, i.name as inventory_name FROM requests r JOIN inventories i ON i.id = r.inventory_id WHERE r.id = ? AND r.stage = "pending" FOR UPDATE');
                $stmt->execute([$request_id]);
            }
            $requests = $stmt->fetchAll();
            
            if (empty($requests)) {
                throw new Exception('Permintaan tidak ditemukan atau sudah diproses.');
            }
            
            $userId = $requests[0]['user_id'];
            $itemNames = [];
            
            foreach ($requests as $request) {
                // Check stock
                if ($request['stock_available'] < $request['quantity']) {
                    throw new Exception('Stok tidak cukup untuk ' . $request['inventory_name']);
                }
                
                // Reduce stock (permanent request reduces both total and available)
                $stmt = $pdo->prepare('UPDATE inventories SET stock_total = stock_total - ?, stock_available = stock_available - ? WHERE id = ?');
                $stmt->execute([$request['quantity'], $request['quantity'], $request['inventory_id']]);
                
                // Update request status
                $stmt = $pdo->prepare('UPDATE requests SET stage = "approved", status = "completed", approved_at = NOW(), completed_at = NOW(), approved_by = ?, admin_document_path = ? WHERE id = ?');
                $stmt->execute([$_SESSION['user']['id'], $adminDocPath, $request['id']]);
                
                $itemNames[] = $request['inventory_name'] . ' (' . $request['quantity'] . ' unit)';
            }
            
            // Create notification
            $notifTitle = 'Permintaan Barang Disetujui';
            $notifMessage = 'Permintaan Anda untuk ' . implode(', ', $itemNames) . ' telah disetujui.';
            if ($adminDocPath) {
                $notifMessage .= ' Dokumen BAST tersedia untuk diunduh.';
            }
            
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type) VALUES (?, 'request_approved', ?, ?, ?, 'request')");
            $stmt->execute([$userId, $notifTitle, $notifMessage, $group_id ?: $request_id]);
            
            $pdo->commit();
            $success = 'Permintaan berhasil disetujui dan stok dikurangi.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
    
    // REJECT
    elseif ($action === 'reject' && ($group_id || $request_id)) {
        $rejection_note = trim($_POST['rejection_note'] ?? '');
        
        if ($group_id) {
            $stmt = $pdo->prepare('SELECT r.*, i.name as inventory_name FROM requests r JOIN inventories i ON i.id = r.inventory_id WHERE r.group_id = ?');
            $stmt->execute([$group_id]);
        } else {
            $stmt = $pdo->prepare('SELECT r.*, i.name as inventory_name FROM requests r JOIN inventories i ON i.id = r.inventory_id WHERE r.id = ?');
            $stmt->execute([$request_id]);
        }
        $requests = $stmt->fetchAll();
        
        if (!empty($requests)) {
            $userId = $requests[0]['user_id'];
            $itemNames = [];
            
            foreach ($requests as $request) {
                $stmt = $pdo->prepare('UPDATE requests SET stage = "rejected", status = "rejected", rejection_note = ?, rejected_by = ? WHERE id = ?');
                $stmt->execute([$rejection_note, $_SESSION['user']['id'], $request['id']]);
                $itemNames[] = $request['inventory_name'];
            }
            
            $notifMsg = 'Permintaan Anda untuk ' . implode(', ', $itemNames) . ' telah ditolak.';
            if ($rejection_note) $notifMsg .= ' Alasan: ' . $rejection_note;
            
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type) VALUES (?, 'request_rejected', ?, ?, ?, 'request')");
            $stmt->execute([$userId, 'Permintaan Ditolak', $notifMsg, $group_id ?: $request_id]);
            
            $success = 'Permintaan ditolak.';
        }
    }
}

// Fetch all requests
$stmt = $pdo->query("
    SELECT r.*, u.name AS user_name, u.email AS user_email, 
           i.name AS inventory_name, i.code AS inventory_code, i.image AS inventory_image,
           i.unit, i.item_condition, i.stock_available,
           ua.name AS approved_by_name, ur.name AS rejected_by_name
    FROM requests r
    JOIN users u ON u.id = r.user_id
    JOIN inventories i ON i.id = r.inventory_id
    LEFT JOIN users ua ON ua.id = r.approved_by
    LEFT JOIN users ur ON ur.id = r.rejected_by
    ORDER BY r.requested_at DESC, r.group_id, r.id
");
$allRequests = $stmt->fetchAll();

// Group requests by group_id
$groupedRequests = [];
foreach ($allRequests as $request) {
    if (!empty($request['group_id'])) {
        if (!isset($groupedRequests[$request['group_id']])) {
            $groupedRequests[$request['group_id']] = [
                'type' => 'group',
                'group_id' => $request['group_id'],
                'user_id' => $request['user_id'],
                'user_name' => $request['user_name'],
                'user_email' => $request['user_email'],
                'requested_at' => $request['requested_at'],
                'stage' => $request['stage'],
                'items' => [],
                'total_quantity' => 0,
                'note' => $request['note'],
                'rejection_note' => $request['rejection_note'],
                'admin_document_path' => $request['admin_document_path'] ?? null,
                'approved_at' => $request['approved_at'] ?? null,
                'approved_by_name' => $request['approved_by_name'] ?? null,
                'rejected_by_name' => $request['rejected_by_name'] ?? null
            ];
        }
        $groupedRequests[$request['group_id']]['items'][] = $request;
        $groupedRequests[$request['group_id']]['total_quantity'] += $request['quantity'];
        // Use first non-empty note
        if (!empty($request['note']) && empty($groupedRequests[$request['group_id']]['note'])) {
            $groupedRequests[$request['group_id']]['note'] = $request['note'];
        }
    } else {
        $groupedRequests['single_' . $request['id']] = [
            'type' => 'single',
            'group_id' => null,
            'request_id' => $request['id'],
            'user_id' => $request['user_id'],
            'user_name' => $request['user_name'],
            'user_email' => $request['user_email'],
            'requested_at' => $request['requested_at'],
            'stage' => $request['stage'],
            'items' => [$request],
            'total_quantity' => $request['quantity'],
            'note' => $request['note'],
            'rejection_note' => $request['rejection_note'],
            'admin_document_path' => $request['admin_document_path'] ?? null,
            'approved_at' => $request['approved_at'] ?? null,
            'approved_by_name' => $request['approved_by_name'] ?? null,
            'rejected_by_name' => $request['rejected_by_name'] ?? null
        ];
    }
}

// Count stats (unfiltered)
$totalPendingCount = count(array_filter($groupedRequests, fn($g) => $g['stage'] === 'pending'));
$totalApprovedCount = count(array_filter($groupedRequests, fn($g) => $g['stage'] === 'approved'));
$totalRejectedCount = count(array_filter($groupedRequests, fn($g) => $g['stage'] === 'rejected'));

// === SERVER-SIDE SEARCH ===
$searchQuery = trim($_GET['search'] ?? '');
$filteredRequests = $groupedRequests;

if (!empty($searchQuery)) {
    $searchLower = strtolower($searchQuery);
    $filteredRequests = array_filter($groupedRequests, function($request) use ($searchLower) {
        $userName = strtolower($request['user_name'] ?? '');
        $userEmail = strtolower($request['user_email'] ?? '');
        $itemNames = '';
        foreach ($request['items'] as $item) {
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
$totalRequests = count($filteredRequests);
$totalPages = max(1, ceil($totalRequests / $itemsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $itemsPerPage;
$requestsToDisplay = array_slice($filteredRequests, $offset, $itemsPerPage);
$displayFrom = $totalRequests > 0 ? $offset + 1 : 0;
$displayTo = min($offset + $itemsPerPage, $totalRequests);

// URL builder for pagination
$buildPaginationUrl = function($pageNum) use ($searchQuery) {
    $params = ['page' => 'admin_requests', 'p' => $pageNum];
    if (!empty($searchQuery)) {
        $params['search'] = $searchQuery;
    }
    return '?' . http_build_query($params);
};

// Stats for display
$pendingCount = $totalPendingCount;
$approvedCount = $totalApprovedCount;
$rejectedCount = $totalRejectedCount;

// Fetch active BAST template for requests
$bastTemplate = null;
try {
    $bastTemplate = $pdo->query("SELECT * FROM document_templates WHERE template_type = 'request' AND is_active = 1 LIMIT 1")->fetch();
} catch (Exception $e) {}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title"><i class="bi bi-cart-check me-2"></i>Kelola Permintaan</h1>
        <p class="text-muted mb-0">Kelola permintaan barang (permanen) dari karyawan</p>
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
    <div class="col-6 col-lg-3">
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
    <div class="col-6 col-lg-3">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #10b981, #34d399); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-check-circle" style="color: #fff; font-size: 20px;"></i>
                </div>
                <div>
                    <div style="font-size: 24px; font-weight: 700;"><?= $approvedCount ?></div>
                    <div style="font-size: 13px; color: var(--text-muted);">Disetujui</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
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
    <div class="col-6 col-lg-3">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--primary), var(--primary-light)); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-clipboard-data" style="color: #fff; font-size: 20px;"></i>
                </div>
                <div>
                    <div style="font-size: 24px; font-weight: 700;"><?= count($groupedRequests) ?></div>
                    <div style="font-size: 13px; color: var(--text-muted);">Total Transaksi</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Requests Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="bi bi-list-ul me-2"></i>Daftar Permintaan</h5>
        <div class="table-filters" style="padding: 0;">
            <button class="table-filter-btn active" data-filter="all">Semua</button>
            <button class="table-filter-btn" data-filter="pending">Pending</button>
            <button class="table-filter-btn" data-filter="approved">Disetujui</button>
        </div>
    </div>
    
    <!-- Pagination Info Header -->
    <?php if (!empty($groupedRequests)): ?>
    <div style="padding: 12px 24px; border-bottom: 1px solid var(--border-color); background: var(--bg-white);">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="text-muted small">
                <i class="bi bi-list-ul me-1"></i>
                Menampilkan <strong><?= $displayFrom ?></strong> - <strong><?= $displayTo ?></strong> dari <strong><?= $totalRequests ?></strong> data
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
    </div>
    <?php endif; ?>
    
    <!-- Server-Side Search Form -->
    <div style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); background: var(--bg-main);">
        <form method="GET" action="" class="d-flex gap-2">
            <input type="hidden" name="page" value="admin_requests">
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
            <a href="?page=admin_requests" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle"></i>
            </a>
            <?php endif; ?>
        </form>
    </div>
    
    <div class="card-body p-0">
        <?php if (empty($requestsToDisplay)): ?>
        <div class="text-center py-5">
            <div class="empty-state">
                <i class="bi bi-inbox" style="font-size: 48px; color: var(--text-muted);"></i>
                <?php if (!empty($searchQuery)): ?>
                    <h5>Tidak Ada Hasil</h5>
                    <p class="text-muted">Tidak ada permintaan yang cocok dengan pencarian "<?= htmlspecialchars($searchQuery) ?>".</p>
                    <a href="?page=admin_requests" class="btn btn-sm btn-outline-primary mt-2">
                        <i class="bi bi-x-circle me-1"></i>Hapus Filter
                    </a>
                <?php else: ?>
                    <h5>Belum Ada Permintaan</h5>
                    <p class="text-muted">Belum ada permintaan barang dari karyawan.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="requestsTable">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Pemohon</th>
                        <th>Barang</th>
                        <th>Qty</th>
                        <th>Catatan</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="requestsTableBody">
                    <?php 
                    $totalRows = count($groupedRequests);
                    $rowNum = $offset;
                    foreach($requestsToDisplay as $key => $group): 
                        $rowNum++;
                        $displayNum = $totalRows - $rowNum + 1; // Oldest gets highest number, newest gets 1
                        $filterClass = $group['stage'] === 'pending' ? 'pending' : ($group['stage'] === 'approved' ? 'approved' : 'rejected');
                        $isMulti = count($group['items']) > 1;
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
                            <?php if (!empty($group['note'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#noteModal<?= $key ?>" onclick="event.stopPropagation();">
                                <i class="bi bi-chat-text me-1"></i>Lihat
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= date('d M Y', strtotime($group['requested_at'])) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($group['requested_at'])) ?></small>
                        </td>
                        <td>
                            <?php if($group['stage'] === 'pending'): ?>
                            <span class="badge bg-warning"><i class="bi bi-hourglass me-1"></i>Menunggu</span>
                            <?php elseif($group['stage'] === 'approved'): ?>
                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Disetujui</span>
                            <?php if(!empty($group['approved_by_name'])): ?>
                            <br><small class="text-muted">oleh <?= htmlspecialchars($group['approved_by_name']) ?></small>
                            <?php endif; ?>
                            <?php if(!empty($group['approved_at'])): ?>
                            <br><small class="text-muted"><?= date('d/m/Y H:i', strtotime($group['approved_at'])) ?></small>
                            <?php endif; ?>
                            <?php elseif($group['stage'] === 'rejected'): ?>
                            <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Ditolak</span>
                            <?php if(!empty($group['rejected_by_name'])): ?>
                            <br><small class="text-muted">oleh <?= htmlspecialchars($group['rejected_by_name']) ?></small>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($group['stage'] === 'pending'): ?>
                            <div class="btn-group btn-group-sm" onclick="event.stopPropagation();">
                                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal<?= $key ?>">
                                    <i class="bi bi-check-lg me-1"></i>Approve
                                </button>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $key ?>">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <?php elseif($group['stage'] === 'approved' && !empty($group['admin_document_path'])): ?>
                            <a href="/public/assets/<?= htmlspecialchars($group['admin_document_path']) ?>" target="_blank" class="btn btn-outline-primary btn-sm" onclick="event.stopPropagation();">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i>BAST
                            </a>
                            <?php elseif($group['stage'] === 'rejected'): ?>
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
                                        <span class="badge bg-secondary" style="font-size: 10px;">Stok: <?= $item['stock_available'] ?? '-' ?></span>
                                        <?php if (!empty($item['item_condition']) && $item['item_condition'] !== 'Baik'): ?>
                                        <span class="badge bg-warning" style="font-size: 10px;"><?= htmlspecialchars($item['item_condition']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge bg-secondary"><?= $item['quantity'] ?></span></td>
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
<?php foreach($groupedRequests as $key => $group): ?>

<!-- Note Modal -->
<?php if (!empty($group['note'])): ?>
<div class="modal fade" id="noteModal<?= $key ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-chat-left-text me-2"></i>Catatan dari <?= htmlspecialchars($group['user_name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Barang yang diminta:</label>
                    <?php foreach($group['items'] as $item): ?>
                    <p class="mb-1">&bull; <?= htmlspecialchars($item['inventory_name']) ?> (<?= $item['quantity'] ?> unit)</p>
                    <?php endforeach; ?>
                </div>
                <div class="p-3 rounded" style="background: var(--bg-main); border-left: 4px solid var(--primary-light);">
                    <label class="form-label fw-semibold">Catatan:</label>
                    <p class="mb-0"><?= nl2br(htmlspecialchars($group['note'])) ?></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Approve Modal -->
<?php if ($group['stage'] === 'pending'): ?>
<div class="modal fade" id="approveModal<?= $key ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check-circle me-2" style="color: var(--success);"></i>Setujui Permintaan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="approve">
                    <?php if ($group['type'] === 'group'): ?>
                    <input type="hidden" name="group_id" value="<?= htmlspecialchars($group['group_id']) ?>">
                    <?php $docRefId = $group['group_id']; ?>
                    <?php else: ?>
                    <input type="hidden" name="request_id" value="<?= $group['items'][0]['id'] ?>">
                    <?php $docRefId = 'single_' . $group['items'][0]['id']; ?>
                    <?php endif; ?>
                    
                    <div style="margin-bottom: 20px; padding: 16px; background: var(--bg-main); border-radius: var(--radius);">
                        <h6><i class="bi bi-person me-2"></i>Pemohon: <?= htmlspecialchars($group['user_name']) ?></h6>
                        <div class="table-responsive mt-3">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Barang</th><th>Qty</th></tr></thead>
                                <tbody>
                                    <?php foreach($group['items'] as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['inventory_name']) ?> <small class="text-muted">(<?= htmlspecialchars($item['inventory_code']) ?>)</small></td>
                                        <td><span class="badge bg-primary"><?= $item['quantity'] ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Document Generation Section -->
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 600;">
                            <i class="bi bi-file-earmark-text me-1"></i>Dokumen BAST (Berita Acara Serah Terima)
                        </label>
                        <div class="d-flex gap-2 flex-wrap mb-2">
                            <a href="/index.php?page=admin_generate_document&type=request&ref=<?= urlencode($docRefId) ?>" target="_blank" class="btn btn-primary">
                                <i class="bi bi-file-earmark-plus me-1"></i> Generate & Download Document
                            </a>
                        </div>
                        <div class="mt-2">
                            <label class="form-label" style="font-size: 13px; margin-bottom: 6px;">
                                <i class="bi bi-upload me-1"></i>Upload Dokumen BAST <span class="text-danger">*</span>
                            </label>
                            <input type="file" name="bast_document" class="form-control form-control-sm" accept=".pdf,.xlsx,.xls,.doc,.docx" required>
                            <small class="text-muted">Format: PDF, Excel, Word. Maksimal 10MB. <strong class="text-danger">Wajib diupload!</strong></small>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Wajib upload dokumen BAST!</strong> Permintaan barang bersifat permanen. Stok total akan dikurangi secara permanen.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Setujui</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal<?= $key ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-x-circle me-2" style="color: var(--danger);"></i>Tolak Permintaan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reject">
                    <?php if ($group['type'] === 'group'): ?>
                    <input type="hidden" name="group_id" value="<?= htmlspecialchars($group['group_id']) ?>">
                    <?php else: ?>
                    <input type="hidden" name="request_id" value="<?= $group['items'][0]['id'] ?>">
                    <?php endif; ?>
                    
                    <div style="margin-bottom: 16px; padding: 16px; background: var(--bg-main); border-radius: var(--radius);">
                        <strong><?= htmlspecialchars($group['user_name']) ?></strong> mengajukan:
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
<?php if ($group['stage'] === 'rejected' && !empty($group['rejection_note'])): ?>
<div class="modal fade" id="rejectionModal<?= $key ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Alasan Penolakan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="p-3 rounded" style="background: var(--bg-main); border-left: 4px solid var(--danger);">
                    <?= nl2br(htmlspecialchars($group['rejection_note'])) ?>
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
    
    document.querySelectorAll('#requestsTableBody tr.group-header').forEach(row => {
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
