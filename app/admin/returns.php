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
                $stmt = $pdo->prepare('UPDATE loans SET return_stage = "return_approved", status = "returned", returned_at = NOW(), return_admin_document_path = ? WHERE id = ?');
                $stmt->execute([$adminDocPath, $loan['id']]);
                
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
                $stmt = $pdo->prepare('UPDATE loans SET return_stage = "return_rejected", return_rejection_note = ? WHERE id = ?');
                $stmt->execute([$rejection_note, $loan['id']]);
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
           i.name AS inventory_name, i.code AS inventory_code, i.image AS inventory_image
    FROM loans l
    JOIN users u ON u.id = l.user_id
    JOIN inventories i ON i.id = l.inventory_id
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
                'return_admin_document_path' => $loan['return_admin_document_path'] ?? null
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
            'return_admin_document_path' => $loan['return_admin_document_path'] ?? null
        ];
    }
}

// Count stats
$pendingCount = count(array_filter($groupedReturns, fn($g) => $g['return_stage'] === 'pending_return'));
$approvedCount = count(array_filter($groupedReturns, fn($g) => $g['return_stage'] === 'return_approved'));
$rejectedCount = count(array_filter($groupedReturns, fn($g) => $g['return_stage'] === 'return_rejected'));

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
    <div class="card-body p-0">
        <?php if (empty($groupedReturns)): ?>
        <div class="text-center py-5">
            <div class="empty-state">
                <i class="bi bi-inbox" style="font-size: 48px; color: var(--text-muted);"></i>
                <h5>Belum Ada Pengembalian</h5>
                <p class="text-muted">Belum ada permintaan pengembalian dari karyawan.</p>
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
                    $rowNum = 0;
                    foreach($groupedReturns as $key => $group): 
                        $rowNum++;
                        $filterClass = $group['return_stage'];
                        $isMulti = count($group['items']) > 1;
                        $stageInfo = $returnStageLabels[$group['return_stage']] ?? ['Unknown', 'secondary', 'question'];
                    ?>
                    <!-- Group Header Row -->
                    <tr class="group-header" data-status="<?= $filterClass ?>" data-group="<?= $key ?>" <?= $isMulti ? 'style="cursor:pointer;" onclick="toggleGroup(\'' . $key . '\')"' : '' ?>>
                        <td><span class="badge bg-secondary"><?= $rowNum ?></span></td>
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
                    <?php foreach($group['items'] as $item): ?>
                    <tr class="group-detail-row" data-parent="<?= $key ?>" data-status="<?= $filterClass ?>" style="display: none; background: var(--bg-secondary);">
                        <td></td>
                        <td></td>
                        <td>
                            <div class="d-flex align-items-center" style="padding-left: 20px;">
                                <?php if ($item['inventory_image']): ?>
                                <img src="/public/assets/uploads/<?= htmlspecialchars($item['inventory_image']) ?>" alt="" class="rounded me-2" style="width: 32px; height: 32px; object-fit: cover;">
                                <?php else: ?>
                                <div class="rounded me-2 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: var(--bg-main);">
                                    <i class="bi bi-box-seam text-muted" style="font-size: 12px;"></i>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-size: 13px;"><?= htmlspecialchars($item['inventory_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($item['inventory_code']) ?></small>
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
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check-circle me-2" style="color: var(--success);"></i>Setujui Pengembalian</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="approve_return">
                    <?php if ($group['type'] === 'group'): ?>
                    <input type="hidden" name="group_id" value="<?= htmlspecialchars($group['group_id']) ?>">
                    <?php else: ?>
                    <input type="hidden" name="loan_id" value="<?= $group['items'][0]['id'] ?>">
                    <?php endif; ?>
                    
                    <div style="margin-bottom: 20px; padding: 16px; background: var(--bg-main); border-radius: var(--radius);">
                        <h6><i class="bi bi-person me-2"></i>Peminjam: <?= htmlspecialchars($group['user_name']) ?></h6>
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
                    
                    <?php if (!empty($group['return_note'])): ?>
                    <div class="alert alert-info mb-3">
                        <strong><i class="bi bi-chat-text me-1"></i>Catatan dari peminjam:</strong><br>
                        <?= nl2br(htmlspecialchars($group['return_note'])) ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="bi bi-file-earmark-arrow-up me-1"></i>Upload Dokumen BAST Pengembalian</label>
                        <input type="file" name="bast_document" class="form-control" accept=".pdf,.xlsx,.xls,.doc,.docx">
                        <small class="text-muted">Format: PDF, Excel, Word. Maksimal 10MB. (Opsional)</small>
                        <?php if ($bastTemplate): ?>
                        <div class="mt-2">
                            <a href="/public/assets/uploads/templates/<?= htmlspecialchars(basename($bastTemplate['file_path'])) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-download me-1"></i>Download Template
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="alert alert-success mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        Dengan menyetujui, stok barang akan dikembalikan secara otomatis.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Setujui Pengembalian</button>
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
        
        const filter = this.dataset.filter;
        document.querySelectorAll('#returnsTableBody tr').forEach(row => {
            if (filter === 'all' || row.dataset.status === filter) {
                if (!row.classList.contains('group-detail-row')) {
                    row.style.display = '';
                }
            } else {
                row.style.display = 'none';
            }
        });
    });
});
</script>
