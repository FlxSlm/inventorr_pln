<?php
// app/user/request_history.php - Simplified Workflow Request History
// Employee submits request → Admin validates & uploads BAST → Done
if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$userId = $_SESSION['user']['id'];
$msg = $_GET['msg'] ?? '';

// Fetch user's requests
$stmt = $pdo->prepare("
    SELECT r.*, i.name as inventory_name, i.code as inventory_code, i.image as inventory_image,
           ua.name AS approved_by_name, ur.name AS rejected_by_name
    FROM requests r
    JOIN inventories i ON i.id = r.inventory_id
    LEFT JOIN users ua ON ua.id = r.approved_by
    LEFT JOIN users ur ON ur.id = r.rejected_by
    WHERE r.user_id = ?
    ORDER BY r.requested_at DESC, r.group_id, r.id
");
$stmt->execute([$userId]);
$rawRequests = $stmt->fetchAll();

// Group requests by group_id
$requests = [];
$groupedRequests = [];
foreach ($rawRequests as $req) {
    if (!empty($req['group_id'])) {
        if (!isset($groupedRequests[$req['group_id']])) {
            $groupedRequests[$req['group_id']] = [
                'is_group' => true,
                'group_id' => $req['group_id'],
                'items' => [],
                'requested_at' => $req['requested_at'],
                'stage' => $req['stage'],
                'note' => $req['note'],
                'rejection_note' => $req['rejection_note'],
                'admin_document_path' => $req['admin_document_path'] ?? null,
                'total_quantity' => 0,
                'approved_at' => $req['approved_at'] ?? null,
                'approved_by_name' => $req['approved_by_name'] ?? null,
                'rejected_by_name' => $req['rejected_by_name'] ?? null
            ];
        }
        $groupedRequests[$req['group_id']]['items'][] = $req;
        $groupedRequests[$req['group_id']]['total_quantity'] += $req['quantity'];
        // Capture note from first item that has one
        if (!empty($req['note']) && empty($groupedRequests[$req['group_id']]['note'])) {
            $groupedRequests[$req['group_id']]['note'] = $req['note'];
        }
        // Capture admin document from first item that has one
        if (!empty($req['admin_document_path']) && empty($groupedRequests[$req['group_id']]['admin_document_path'])) {
            $groupedRequests[$req['group_id']]['admin_document_path'] = $req['admin_document_path'];
        }
        if ($req['stage'] === 'rejected') {
            $groupedRequests[$req['group_id']]['stage'] = 'rejected';
            $groupedRequests[$req['group_id']]['rejection_note'] = $req['rejection_note'];
        }
    } else {
        $req['is_group'] = false;
        $requests[] = $req;
    }
}
foreach ($groupedRequests as $group) {
    $requests[] = $group;
}
usort($requests, fn($a, $b) => strtotime($b['requested_at'] ?? $b['items'][0]['requested_at'] ?? 'now') - strtotime($a['requested_at'] ?? $a['items'][0]['requested_at'] ?? 'now'));

// Stats
$totalRequests = count($requests);
$pendingRequests = count(array_filter($requests, fn($r) => ($r['stage'] ?? $r['items'][0]['stage'] ?? '') === 'pending'));
$approvedRequests = count(array_filter($requests, fn($r) => ($r['stage'] ?? $r['items'][0]['stage'] ?? '') === 'approved'));
$rejectedRequests = count(array_filter($requests, fn($r) => ($r['stage'] ?? $r['items'][0]['stage'] ?? '') === 'rejected'));

// === SERVER-SIDE SEARCH ===
$searchQuery = trim($_GET['search'] ?? '');
$filteredRequests = $requests;

if (!empty($searchQuery)) {
    $searchLower = strtolower($searchQuery);
    $filteredRequests = array_filter($requests, function($request) use ($searchLower) {
        $itemNames = '';
        $items = $request['items'] ?? [$request];
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
$totalRequestsFiltered = count($filteredRequests);
$totalPages = max(1, ceil($totalRequestsFiltered / $itemsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $itemsPerPage;
$requestsToDisplay = array_slice($filteredRequests, $offset, $itemsPerPage);
$displayFrom = $totalRequestsFiltered > 0 ? $offset + 1 : 0;
$displayTo = min($offset + $itemsPerPage, $totalRequestsFiltered);

// URL builder for pagination
$buildPaginationUrl = function($pageNum) use ($searchQuery) {
    $params = ['page' => 'user_request_history', 'p' => $pageNum];
    if (!empty($searchQuery)) {
        $params['search'] = $searchQuery;
    }
    return '?' . http_build_query($params);
};

$stageLabels = [
    'pending' => ['Menunggu Persetujuan', 'warning', 'hourglass-split'],
    'approved' => ['Disetujui', 'success', 'check-circle'],
    'rejected' => ['Ditolak', 'danger', 'x-circle']
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title"><i class="bi bi-clock-history me-2"></i>Riwayat Permintaan</h1>
        <p class="text-muted mb-0">Daftar permintaan barang permanen Anda</p>
    </div>
    <a href="/index.php?page=user_request_item" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Ajukan Permintaan</a>
</div>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon stat-icon-fixed" style="background: linear-gradient(135deg, var(--primary), var(--primary-light));">
                <i class="bi bi-collection"></i>
            </div>
            <div class="stat-details">
                <div class="stat-number"><?= $totalRequests ?></div>
                <div class="stat-label">Total Permintaan</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon stat-icon-fixed" style="background: linear-gradient(135deg, #f59e0b, #fbbf24);">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <div class="stat-details">
                <div class="stat-number"><?= $pendingRequests ?></div>
                <div class="stat-label">Menunggu</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon stat-icon-fixed" style="background: linear-gradient(135deg, #10b981, #34d399);">
                <i class="bi bi-check-circle"></i>
            </div>
            <div class="stat-details">
                <div class="stat-number"><?= $approvedRequests ?></div>
                <div class="stat-label">Disetujui</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon stat-icon-fixed" style="background: linear-gradient(135deg, #ef4444, #f87171);">
                <i class="bi bi-x-circle"></i>
            </div>
            <div class="stat-details">
                <div class="stat-number"><?= $rejectedRequests ?></div>
                <div class="stat-label">Ditolak</div>
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
            <li class="nav-item"><button class="nav-link" data-filter="approved"><i class="bi bi-check-circle me-1"></i>Disetujui</button></li>
            <li class="nav-item"><button class="nav-link" data-filter="rejected"><i class="bi bi-x-circle me-1"></i>Ditolak</button></li>
        </ul>
    </div>
</div>

<!-- Search Form -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="d-flex gap-2">
            <input type="hidden" name="page" value="user_request_history">
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
            <a href="?page=user_request_history" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle"></i>
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="modern-card">
    <!-- Pagination Header -->
    <?php if ($totalRequestsFiltered > 0): ?>
    <div style="padding: 12px 24px; border-bottom: 1px solid var(--border-color); background: var(--bg-white);">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="text-muted small">
                <i class="bi bi-list-ul me-1"></i>
                Menampilkan <strong><?= $displayFrom ?></strong> - <strong><?= $displayTo ?></strong> dari <strong><?= $totalRequestsFiltered ?></strong> data
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
    
    <div class="card-body p-0">
        <?php if (empty($requestsToDisplay)): ?>
        <div class="text-center py-5">
            <div class="empty-state">
                <i class="bi bi-<?= !empty($searchQuery) ? 'search' : 'inbox' ?>" style="font-size: 48px; color: var(--text-muted);"></i>
                <?php if (!empty($searchQuery)): ?>
                    <h5>Tidak Ada Hasil</h5>
                    <p class="text-muted">Tidak ada permintaan yang cocok dengan pencarian "<?= htmlspecialchars($searchQuery) ?>".</p>
                    <a href="?page=user_request_history" class="btn btn-sm btn-outline-primary mt-2">
                        <i class="bi bi-x-circle me-1"></i>Hapus Filter
                    </a>
                <?php else: ?>
                    <h5>Belum Ada Permintaan</h5>
                    <p class="text-muted">Anda belum mengajukan permintaan barang.</p>
                    <a href="/index.php?page=user_request_item" class="btn btn-primary mt-3"><i class="bi bi-plus-lg me-1"></i>Ajukan Permintaan</a>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Barang</th>
                        <th>Jumlah</th>
                        <th>Catatan Saya</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totalRows = count($requests);
                    $rowNumber = $offset;
                    foreach($requestsToDisplay as $r):
                        $rowNumber++;
                        $displayNum = $totalRows - $rowNumber + 1;
                        $isGroup = !empty($r['is_group']);
                        
                        if ($isGroup):
                            $firstItem = $r['items'][0];
                            $itemCount = count($r['items']);
                            $stage = $r['stage'] ?? $firstItem['stage'];
                            $stageInfo = $stageLabels[$stage] ?? ['Unknown', 'secondary', 'question'];
                            $userNote = $r['note'] ?? null;
                            $adminDoc = $r['admin_document_path'] ?? null;
                            
                            $filterClass = 'all ' . $stage;
                    ?>
                    <tr class="request-row group-header <?= $filterClass ?>" data-group="<?= $r['group_id'] ?>" style="cursor: pointer;" onclick="toggleGroup('<?= $r['group_id'] ?>')">
                        <td><span class="badge bg-secondary"><?= $displayNum ?></span></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="rounded me-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary), var(--primary-light));">
                                    <i class="bi bi-stack text-white"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold">
                                        <i class="bi bi-chevron-right group-chevron me-1" id="chevron-<?= $r['group_id'] ?>"></i>
                                        <?= $itemCount ?> Barang
                                    </div>
                                    <small class="text-muted">Klik untuk detail</small>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge bg-primary"><?= (int)$r['total_quantity'] ?></span></td>
                        <td>
                            <?php if ($userNote): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#noteHistoryModalGroup<?= $r['group_id'] ?>" onclick="event.stopPropagation();">
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
                            <?php if ($stage === 'approved' && !empty($r['approved_by_name'])): ?>
                            <br><small class="text-muted">oleh <?= htmlspecialchars($r['approved_by_name']) ?></small>
                            <?php elseif ($stage === 'rejected' && !empty($r['rejected_by_name'])): ?>
                            <br><small class="text-muted">oleh <?= htmlspecialchars($r['rejected_by_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td onclick="event.stopPropagation();">
                            <?php if ($stage === 'approved' && $adminDoc): ?>
                            <a href="/public/assets/<?= htmlspecialchars($adminDoc) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Download BAST">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i>BAST
                            </a>
                            <?php elseif ($stage === 'rejected' && !empty($r['rejection_note'])): ?>
                            <button type="button" class="btn-alasan" data-bs-toggle="modal" data-bs-target="#rejectionModalGroup<?= $r['group_id'] ?>">
                                <i class="bi bi-exclamation-circle"></i> Alasan
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php foreach($r['items'] as $item): ?>
                    <tr class="group-detail-row <?= $filterClass ?>" data-parent="<?= $r['group_id'] ?>" style="display: none; background: var(--bg-secondary);">
                        <td></td>
                        <td>
                            <div class="d-flex align-items-center ps-3">
                                <div class="border-start border-2 border-primary ps-3 d-flex align-items-center">
                                    <?php if ($item['inventory_image']): ?>
                                    <img src="/public/assets/uploads/<?= htmlspecialchars($item['inventory_image']) ?>" class="rounded me-2" style="width: 36px; height: 36px; object-fit: cover;">
                                    <?php else: ?>
                                    <div class="rounded me-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; background: var(--bg-main);"><i class="bi bi-box-seam text-muted"></i></div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($item['inventory_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($item['inventory_code']) ?></small>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge bg-secondary"><?= $item['quantity'] ?></span></td>
                        <td colspan="4"></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php else:
                        $stage = $r['stage'] ?? 'pending';
                        $stageInfo = $stageLabels[$stage] ?? ['Unknown', 'secondary', 'question'];
                        $userNote = $r['note'] ?? null;
                        $adminDoc = $r['admin_document_path'] ?? null;
                        
                        $filterClass = 'all ' . $stage;
                    ?>
                    <tr class="request-row <?= $filterClass ?>">
                        <td><span class="badge bg-secondary"><?= $displayNum ?></span></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <?php if ($r['inventory_image']): ?>
                                <img src="/public/assets/uploads/<?= htmlspecialchars($r['inventory_image']) ?>" alt="" class="rounded me-2" style="width: 40px; height: 40px; object-fit: cover;">
                                <?php else: ?>
                                <div class="rounded me-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; background: var(--bg-main);"><i class="bi bi-box-seam text-muted"></i></div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($r['inventory_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($r['inventory_code']) ?></small>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge bg-primary"><?= $r['quantity'] ?></span></td>
                        <td>
                            <?php if ($userNote): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#noteHistoryModal<?= $r['id'] ?>">
                                <i class="bi bi-chat-left-text me-1"></i>Lihat
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= date('d M Y', strtotime($r['requested_at'])) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($r['requested_at'])) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-<?= $stageInfo[1] ?>"><i class="bi bi-<?= $stageInfo[2] ?> me-1"></i><?= $stageInfo[0] ?></span>
                            <?php if ($stage === 'approved' && !empty($r['approved_by_name'])): ?>
                            <br><small class="text-muted">oleh <?= htmlspecialchars($r['approved_by_name']) ?></small>
                            <?php elseif ($stage === 'rejected' && !empty($r['rejected_by_name'])): ?>
                            <br><small class="text-muted">oleh <?= htmlspecialchars($r['rejected_by_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($stage === 'approved' && $adminDoc): ?>
                            <a href="/public/assets/<?= htmlspecialchars($adminDoc) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Download BAST">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i>BAST
                            </a>
                            <?php elseif ($stage === 'rejected' && $r['rejection_note']): ?>
                            <button type="button" class="btn-alasan" data-bs-toggle="modal" data-bs-target="#rejectionModal<?= $r['id'] ?>">
                                <i class="bi bi-exclamation-circle"></i> Alasan
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
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
</div>

<!-- MODALS -->
<?php foreach($requests as $r): ?>
<?php if (!empty($r['is_group'])): ?>
    <!-- Group: Note History Modal -->
    <?php if (!empty($r['note'])): ?>
    <div class="modal fade" id="noteHistoryModalGroup<?= $r['group_id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-chat-left-text me-2"></i>Riwayat Catatan Saya</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 p-3 rounded" style="background: var(--bg-main);">
                        <div class="fw-semibold mb-2"><?= count($r['items']) ?> Barang:</div>
                        <?php foreach($r['items'] as $gi): ?>
                        <div class="d-flex align-items-center mb-1">
                            <i class="bi bi-box-seam text-muted me-2"></i>
                            <span><?= htmlspecialchars($gi['inventory_name']) ?> (<?= (int)$gi['quantity'] ?> unit)</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="p-3 rounded" style="background: var(--bg-main); border-left: 4px solid var(--primary);">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold"><i class="bi bi-pencil me-1"></i>Catatan Permintaan</span>
                            <small class="text-muted"><?= date('d M Y H:i', strtotime($r['requested_at'])) ?></small>
                        </div>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($r['note'])) ?></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Group: Rejection Modal -->
    <?php if (($r['stage'] ?? '') === 'rejected' && !empty($r['rejection_note'])): ?>
    <div class="modal fade" id="rejectionModalGroup<?= $r['group_id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Permintaan Ditolak</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 p-3 rounded" style="background: var(--bg-main);">
                        <div class="fw-semibold mb-2"><?= count($r['items']) ?> Barang:</div>
                        <?php foreach($r['items'] as $gi): ?>
                        <div class="d-flex align-items-center mb-1">
                            <i class="bi bi-box-seam text-muted me-2"></i>
                            <span><?= htmlspecialchars($gi['inventory_name']) ?> (<?= (int)$gi['quantity'] ?> unit)</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="rejection-reason-box">
                        <div class="reason-label"><i class="bi bi-exclamation-triangle me-1"></i>Alasan Penolakan</div>
                        <p class="reason-text"><?= nl2br(htmlspecialchars($r['rejection_note'])) ?></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <a href="/index.php?page=user_request_item" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Ajukan Baru</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php else: ?>
    <!-- Single: Note History Modal -->
    <?php if (!empty($r['note'])): ?>
    <div class="modal fade" id="noteHistoryModal<?= $r['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-chat-left-text me-2"></i>Riwayat Catatan Saya</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center mb-3 p-3 rounded" style="background: var(--bg-main);">
                        <?php if($r['inventory_image']): ?>
                        <img src="/public/assets/uploads/<?= htmlspecialchars($r['inventory_image']) ?>" class="rounded me-3" style="width: 50px; height: 50px; object-fit: cover;">
                        <?php endif; ?>
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($r['inventory_name']) ?></div>
                            <small class="text-muted"><?= (int)$r['quantity'] ?> unit</small>
                        </div>
                    </div>
                    
                    <div class="p-3 rounded" style="background: var(--bg-main); border-left: 4px solid var(--primary);">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold"><i class="bi bi-pencil me-1"></i>Catatan Permintaan</span>
                            <small class="text-muted"><?= date('d M Y H:i', strtotime($r['requested_at'])) ?></small>
                        </div>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($r['note'])) ?></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Single: Rejection Modal -->
    <?php if ($r['stage'] === 'rejected' && !empty($r['rejection_note'])): ?>
    <div class="modal fade" id="rejectionModal<?= $r['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Permintaan Ditolak</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center mb-3 p-3 rounded" style="background: var(--bg-main);">
                        <?php if($r['inventory_image']): ?>
                        <img src="/public/assets/uploads/<?= htmlspecialchars($r['inventory_image']) ?>" class="rounded me-3" style="width: 50px; height: 50px; object-fit: cover;">
                        <?php endif; ?>
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($r['inventory_name']) ?></div>
                            <small class="text-muted"><?= (int)$r['quantity'] ?> unit</small>
                        </div>
                    </div>
                    <div class="rejection-reason-box">
                        <div class="reason-label"><i class="bi bi-exclamation-triangle me-1"></i>Alasan Penolakan</div>
                        <p class="reason-text"><?= nl2br(htmlspecialchars($r['rejection_note'])) ?></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <a href="/index.php?page=user_request_item" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Ajukan Baru</a>
                </div>
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
    const requestRows = document.querySelectorAll('.request-row');
    const detailRows = document.querySelectorAll('.group-detail-row');
    
    filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const filter = this.dataset.filter;
            filterButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            requestRows.forEach(row => {
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
</style>
