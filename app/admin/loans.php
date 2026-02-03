<?php
// app/admin/loans.php - Simplified Workflow with Grouped Transactions
// Admin approves once + uploads BAST document
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

$pageTitle = 'Kelola Peminjaman';
$pdo = require __DIR__ . '/../config/database.php';

$msg = $_GET['msg'] ?? '';
$errors = [];
$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $group_id = $_POST['group_id'] ?? '';
    $loan_id = (int)($_POST['loan_id'] ?? 0);
    
    // APPROVE with BAST upload (single approval)
    if ($action === 'approve' && ($group_id || $loan_id)) {
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
                
                $filename = 'bast_loan_' . ($group_id ?: $loan_id) . '_' . time() . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                    throw new Exception('Gagal mengupload dokumen.');
                }
                $adminDocPath = 'uploads/documents/bast/' . $filename;
            }
            
            // Get loans to approve
            if ($group_id) {
                $stmt = $pdo->prepare('SELECT l.*, i.stock_available, i.name as inventory_name FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.group_id = ? AND l.stage = "pending" FOR UPDATE');
                $stmt->execute([$group_id]);
            } else {
                $stmt = $pdo->prepare('SELECT l.*, i.stock_available, i.name as inventory_name FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.id = ? AND l.stage = "pending" FOR UPDATE');
                $stmt->execute([$loan_id]);
            }
            $loans = $stmt->fetchAll();
            
            if (empty($loans)) {
                throw new Exception('Peminjaman tidak ditemukan atau sudah diproses.');
            }
            
            $userId = $loans[0]['user_id'];
            $itemNames = [];
            
            foreach ($loans as $loan) {
                // Check stock
                if ($loan['stock_available'] < $loan['quantity']) {
                    throw new Exception('Stok tidak cukup untuk ' . $loan['inventory_name']);
                }
                
                // Reduce stock
                $stmt = $pdo->prepare('UPDATE inventories SET stock_available = stock_available - ? WHERE id = ?');
                $stmt->execute([$loan['quantity'], $loan['inventory_id']]);
                
                // Update loan status
                $stmt = $pdo->prepare('UPDATE loans SET stage = "approved", status = "approved", approved_at = NOW(), admin_document_path = ? WHERE id = ?');
                $stmt->execute([$adminDocPath, $loan['id']]);
                
                $itemNames[] = $loan['inventory_name'] . ' (' . $loan['quantity'] . ' unit)';
            }
            
            // Create notification
            $notifTitle = 'Peminjaman Disetujui';
            $notifMessage = 'Peminjaman Anda untuk ' . implode(', ', $itemNames) . ' telah disetujui.';
            if ($adminDocPath) {
                $notifMessage .= ' Dokumen BAST tersedia untuk diunduh.';
            }
            
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type) VALUES (?, 'loan_approved', ?, ?, ?, 'loan')");
            $stmt->execute([$userId, $notifTitle, $notifMessage, $group_id ?: $loan_id]);
            
            $pdo->commit();
            $success = 'Peminjaman berhasil disetujui dan stok dikurangi.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
    
    // REJECT
    elseif ($action === 'reject' && ($group_id || $loan_id)) {
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
                $stmt = $pdo->prepare('UPDATE loans SET stage = "rejected", status = "rejected", rejection_note = ? WHERE id = ?');
                $stmt->execute([$rejection_note, $loan['id']]);
                $itemNames[] = $loan['inventory_name'];
            }
            
            $notifMsg = 'Peminjaman Anda untuk ' . implode(', ', $itemNames) . ' telah ditolak.';
            if ($rejection_note) $notifMsg .= ' Alasan: ' . $rejection_note;
            
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type) VALUES (?, 'loan_rejected', ?, ?, ?, 'loan')");
            $stmt->execute([$userId, 'Peminjaman Ditolak', $notifMsg, $group_id ?: $loan_id]);
            
            $success = 'Peminjaman ditolak.';
        }
    }
}

// Fetch all loans
$stmt = $pdo->query("
    SELECT l.*, u.name AS user_name, u.email AS user_email, 
           i.name AS inventory_name, i.code AS inventory_code, i.stock_available, i.image AS inventory_image,
           i.unit, i.item_condition
    FROM loans l
    JOIN users u ON u.id = l.user_id
    JOIN inventories i ON i.id = l.inventory_id
    ORDER BY l.requested_at DESC, l.group_id, l.id
");
$allLoans = $stmt->fetchAll();

// Group loans by group_id
$groupedLoans = [];
foreach ($allLoans as $loan) {
    if (!empty($loan['group_id'])) {
        if (!isset($groupedLoans[$loan['group_id']])) {
            $groupedLoans[$loan['group_id']] = [
                'type' => 'group',
                'group_id' => $loan['group_id'],
                'user_id' => $loan['user_id'],
                'user_name' => $loan['user_name'],
                'user_email' => $loan['user_email'],
                'requested_at' => $loan['requested_at'],
                'stage' => $loan['stage'],
                'items' => [],
                'total_quantity' => 0,
                'note' => $loan['note'],
                'rejection_note' => $loan['rejection_note'],
                'admin_document_path' => $loan['admin_document_path'] ?? null
            ];
        }
        $groupedLoans[$loan['group_id']]['items'][] = $loan;
        $groupedLoans[$loan['group_id']]['total_quantity'] += $loan['quantity'];
        // Use most recent note if any item has one
        if (!empty($loan['note']) && empty($groupedLoans[$loan['group_id']]['note'])) {
            $groupedLoans[$loan['group_id']]['note'] = $loan['note'];
        }
    } else {
        $groupedLoans['single_' . $loan['id']] = [
            'type' => 'single',
            'group_id' => null,
            'loan_id' => $loan['id'],
            'user_id' => $loan['user_id'],
            'user_name' => $loan['user_name'],
            'user_email' => $loan['user_email'],
            'requested_at' => $loan['requested_at'],
            'stage' => $loan['stage'],
            'items' => [$loan],
            'total_quantity' => $loan['quantity'],
            'note' => $loan['note'],
            'rejection_note' => $loan['rejection_note'],
            'admin_document_path' => $loan['admin_document_path'] ?? null
        ];
    }
}

// Count stats
$pendingCount = count(array_filter($groupedLoans, fn($g) => $g['stage'] === 'pending'));
$approvedCount = count(array_filter($groupedLoans, fn($g) => $g['stage'] === 'approved'));
$rejectedCount = count(array_filter($groupedLoans, fn($g) => $g['stage'] === 'rejected'));

// Fetch active BAST template for loans
$bastTemplate = null;
try {
    $bastTemplate = $pdo->query("SELECT * FROM document_templates WHERE template_type = 'loan' AND is_active = 1 LIMIT 1")->fetch();
} catch (Exception $e) {}
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-clipboard-check-fill"></i> Kelola Peminjaman</h3>
        <p>Kelola dan proses permintaan peminjaman barang inventaris</p>
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

<!-- Stats Summary -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card warning" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Menunggu Validasi</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $pendingCount ?></p>
            </div>
            <div class="stat-card-icon warning"><i class="bi bi-hourglass-split"></i></div>
        </div>
    </div>
    <div class="stat-card success" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Disetujui</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $approvedCount ?></p>
            </div>
            <div class="stat-card-icon success"><i class="bi bi-check-circle"></i></div>
        </div>
    </div>
    <div class="stat-card danger" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Ditolak</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $rejectedCount ?></p>
            </div>
            <div class="stat-card-icon danger"><i class="bi bi-x-circle"></i></div>
        </div>
    </div>
    <div class="stat-card" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Total Transaksi</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= count($groupedLoans) ?></p>
            </div>
            <div class="stat-card-icon primary"><i class="bi bi-clipboard-data"></i></div>
        </div>
    </div>
</div>

<!-- Loans Table -->
<div class="table-card">
    <div class="card-header" style="padding: 20px 24px; border-bottom: 1px solid var(--border-color);">
        <h3 class="card-title" style="margin: 0;"><i class="bi bi-list-ul"></i> Daftar Peminjaman</h3>
        <div class="card-actions">
            <div class="table-filters" style="padding: 0;">
                <button class="table-filter-btn active" data-filter="all">Semua</button>
                <button class="table-filter-btn" data-filter="pending">Pending</button>
                <button class="table-filter-btn" data-filter="approved">Disetujui</button>
            </div>
        </div>
    </div>
    
    <!-- Search Filters -->
    <div style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); background: var(--bg-main);">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="topbar-search" style="max-width: 100%;">
                    <i class="bi bi-box-seam"></i>
                    <input type="text" id="searchItem" placeholder="Cari berdasarkan nama barang..." style="width: 100%;">
                </div>
            </div>
            <div class="col-md-6">
                <div class="topbar-search" style="max-width: 100%;">
                    <i class="bi bi-person"></i>
                    <input type="text" id="searchUser" placeholder="Cari berdasarkan nama karyawan..." style="width: 100%;">
                </div>
            </div>
        </div>
    </div>
    
    <?php if (empty($groupedLoans)): ?>
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-state-icon"><i class="bi bi-inbox"></i></div>
            <h5 class="empty-state-title">Belum Ada Peminjaman</h5>
            <p class="empty-state-text">Belum ada permintaan peminjaman dari karyawan.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Peminjam</th>
                    <th>Barang</th>
                    <th>Total Qty</th>
                    <th class="hide-mobile">Catatan</th>
                    <th class="hide-mobile">Tanggal</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="loansTableBody">
                <?php 
                $totalRows = count($groupedLoans);
                $rowNum = 0;
                foreach($groupedLoans as $key => $group): 
                    $rowNum++;
                    $displayNum = $totalRows - $rowNum + 1; // Oldest gets highest number, newest gets 1
                    $filterClass = $group['stage'] === 'pending' ? 'pending' : ($group['stage'] === 'approved' ? 'approved' : 'rejected');
                    $isMulti = count($group['items']) > 1;
                ?>
                <!-- Group Header Row -->
                <tr class="group-header" data-status="<?= $filterClass ?>" data-group="<?= $key ?>" <?= $isMulti ? 'style="cursor:pointer;" onclick="toggleGroup(\'' . $key . '\')"' : '' ?>>
                    <td><span class="badge bg-secondary"><?= $displayNum ?></span></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="topbar-avatar" style="width: 38px; height: 38px; font-size: 14px;">
                                <?= strtoupper(substr($group['user_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div style="font-weight: 600;"><?= htmlspecialchars($group['user_name']) ?></div>
                                <small style="color: var(--text-muted);"><?= htmlspecialchars($group['user_email']) ?></small>
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
                        <div class="d-flex align-items-center gap-2">
                            <?php $item = $group['items'][0]; ?>
                            <?php if ($item['inventory_image']): ?>
                            <img src="/public/assets/uploads/<?= htmlspecialchars($item['inventory_image']) ?>" alt="" style="width: 42px; height: 42px; object-fit: cover; border-radius: var(--radius);">
                            <?php else: ?>
                            <div style="width: 42px; height: 42px; background: var(--bg-main); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-box-seam" style="color: var(--text-muted);"></i>
                            </div>
                            <?php endif; ?>
                            <div>
                                <div style="font-weight: 500;"><?= htmlspecialchars($item['inventory_name']) ?></div>
                                <small style="color: var(--text-muted);"><?= htmlspecialchars($item['inventory_code']) ?></small>
                            </div>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><span style="font-weight: 700; color: var(--primary-light);"><?= $group['total_quantity'] ?></span></td>
                    <td class="hide-mobile">
                        <?php if (!empty($group['note'])): ?>
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#noteModal<?= $key ?>" onclick="event.stopPropagation();">
                            <i class="bi bi-chat-text me-1"></i> Lihat
                        </button>
                        <?php else: ?>
                        <span style="color: var(--text-light);">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="hide-mobile">
                        <div style="font-size: 13px;">
                            <?= date('d M Y', strtotime($group['requested_at'])) ?>
                            <br><small style="color: var(--text-muted);"><?= date('H:i', strtotime($group['requested_at'])) ?></small>
                        </div>
                    </td>
                    <td>
                        <?php if($group['stage'] === 'pending'): ?>
                            <span class="status-badge warning">Menunggu Validasi</span>
                        <?php elseif($group['stage'] === 'approved'): ?>
                            <span class="status-badge success">Disetujui</span>
                        <?php elseif($group['stage'] === 'rejected'): ?>
                            <span class="status-badge danger">Ditolak</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($group['stage'] === 'pending'): ?>
                        <div class="d-flex gap-1" onclick="event.stopPropagation();">
                            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#approveModal<?= $key ?>">
                                <i class="bi bi-check-lg me-1"></i> Approve
                            </button>
                            <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $key ?>">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <?php elseif($group['stage'] === 'approved' && !empty($group['admin_document_path'])): ?>
                        <a href="/public/assets/<?= htmlspecialchars($group['admin_document_path']) ?>" target="_blank" class="btn btn-outline-primary btn-sm" onclick="event.stopPropagation();">
                            <i class="bi bi-file-earmark-arrow-down me-1"></i> BAST
                        </a>
                        <?php elseif($group['stage'] === 'rejected'): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#rejectionModal<?= $key ?>" onclick="event.stopPropagation();">
                            <i class="bi bi-info-circle me-1"></i> Alasan
                        </button>
                        <?php else: ?>
                        <span style="color: var(--text-light);">—</span>
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
                            <img src="/public/assets/uploads/<?= htmlspecialchars($item['inventory_image']) ?>" alt="" style="width: 50px; height: 50px; object-fit: cover; border-radius: var(--radius);">
                            <?php else: ?>
                            <div style="width: 50px; height: 50px; background: var(--bg-main); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-box-seam" style="color: var(--text-muted); font-size: 18px;"></i>
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

<!-- Modals -->
<?php foreach($groupedLoans as $key => $group): ?>

<!-- Note Modal -->
<?php if (!empty($group['note'])): ?>
<div class="modal fade" id="noteModal<?= $key ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-chat-text me-2" style="color: var(--primary-light);"></i>Catatan dari <?= htmlspecialchars($group['user_name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 16px;">
                    <small style="color: var(--text-muted);">Barang yang diminta:</small>
                    <?php foreach($group['items'] as $item): ?>
                    <div style="font-weight: 500;"><?= htmlspecialchars($item['inventory_name']) ?> (<?= $item['quantity'] ?> unit)</div>
                    <?php endforeach; ?>
                </div>
                <div style="background: var(--bg-main); padding: 16px; border-radius: var(--radius); border-left: 4px solid var(--primary-light);">
                    <small style="color: var(--text-muted); display: block; margin-bottom: 8px;">Catatan:</small>
                    <p style="margin: 0;"><?= nl2br(htmlspecialchars($group['note'])) ?></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Approve Modal with BAST Upload -->
<?php if ($group['stage'] === 'pending'): ?>
<div class="modal fade" id="approveModal<?= $key ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check-circle me-2" style="color: var(--success);"></i>Setujui Peminjaman</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="approve">
                    <?php if ($group['type'] === 'group'): ?>
                    <input type="hidden" name="group_id" value="<?= htmlspecialchars($group['group_id']) ?>">
                    <?php $docRefId = $group['group_id']; ?>
                    <?php else: ?>
                    <input type="hidden" name="loan_id" value="<?= $group['items'][0]['id'] ?>">
                    <?php $docRefId = 'single_' . $group['items'][0]['id']; ?>
                    <?php endif; ?>
                    
                    <!-- Items Summary -->
                    <div style="margin-bottom: 20px; padding: 16px; background: var(--bg-main); border-radius: var(--radius);">
                        <h6 style="margin-bottom: 12px;"><i class="bi bi-person me-2"></i>Pemohon: <?= htmlspecialchars($group['user_name']) ?></h6>
                        <div class="table-responsive">
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
                            <a href="/index.php?page=admin_generate_document&type=loan&ref=<?= urlencode($docRefId) ?>" target="_blank" class="btn btn-primary">
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
                        <strong>Wajib upload dokumen BAST!</strong> Dengan menyetujui, stok barang akan otomatis dikurangi dan notifikasi akan dikirim ke karyawan.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i> Setujui Peminjaman</button>
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
                <h5 class="modal-title"><i class="bi bi-x-circle me-2" style="color: var(--danger);"></i>Tolak Peminjaman</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reject">
                    <?php if ($group['type'] === 'group'): ?>
                    <input type="hidden" name="group_id" value="<?= htmlspecialchars($group['group_id']) ?>">
                    <?php else: ?>
                    <input type="hidden" name="loan_id" value="<?= $group['items'][0]['id'] ?>">
                    <?php endif; ?>
                    
                    <div style="margin-bottom: 16px; padding: 16px; background: var(--bg-main); border-radius: var(--radius);">
                        <strong><?= htmlspecialchars($group['user_name']) ?></strong> mengajukan:
                        <?php foreach($group['items'] as $item): ?>
                        <div class="mt-1">&bull; <?= htmlspecialchars($item['inventory_name']) ?> (<?= $item['quantity'] ?> unit)</div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" style="font-weight: 500;">Alasan Penolakan <span class="text-danger">*</span></label>
                        <textarea name="rejection_note" class="form-control" rows="3" placeholder="Berikan alasan penolakan..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg me-1"></i> Tolak</button>
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
                <div style="background: var(--bg-main); padding: 16px; border-radius: var(--radius); border-left: 4px solid var(--danger);">
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
.group-chevron { transition: transform 0.2s ease; }
.group-chevron.expanded { transform: rotate(90deg); }
.group-detail-row td { padding: 8px 12px !important; font-size: 13px; }
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

// Filter functionality
document.querySelectorAll('.table-filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.table-filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        const filter = this.dataset.filter;
        applyAllFilters();
    });
});

// Search filters
const searchItem = document.getElementById('searchItem');
const searchUser = document.getElementById('searchUser');

function applyAllFilters() {
    const activeFilter = document.querySelector('.table-filter-btn.active')?.dataset.filter || 'all';
    const itemQuery = (searchItem?.value || '').toLowerCase();
    const userQuery = (searchUser?.value || '').toLowerCase();
    
    document.querySelectorAll('#loansTableBody tr.group-header').forEach(row => {
        const status = row.dataset.status;
        const itemText = row.querySelector('td:nth-child(3)')?.textContent.toLowerCase() || '';
        const userText = row.querySelector('td:nth-child(2)')?.textContent.toLowerCase() || '';
        const groupId = row.dataset.group;
        
        const matchesStatus = activeFilter === 'all' || status === activeFilter;
        const matchesItem = !itemQuery || itemText.includes(itemQuery);
        const matchesUser = !userQuery || userText.includes(userQuery);
        
        if (matchesStatus && matchesItem && matchesUser) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
            // Hide detail rows too
            document.querySelectorAll(`tr[data-parent="${groupId}"]`).forEach(dr => dr.style.display = 'none');
        }
    });
}

searchItem?.addEventListener('keyup', applyAllFilters);
searchUser?.addEventListener('keyup', applyAllFilters);
</script>
