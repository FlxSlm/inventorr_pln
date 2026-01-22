<?php
// app/admin/returns_new.php - Modern Returns Management Page
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
    $loan_id = (int)($_POST['loan_id'] ?? 0);
    
    if ($action === 'approve_return_stage1' && $loan_id) {
        $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = ?');
        $stmt->execute([$loan_id]);
        $loan = $stmt->fetch();
        
        if (!$loan || ($loan['return_stage'] ?? '') !== 'pending_return') {
            $errors[] = 'Pengembalian tidak valid.';
        } else {
            $stmt = $pdo->prepare('UPDATE loans SET return_stage = ? WHERE id = ?');
            $stmt->execute(['awaiting_return_doc', $loan_id]);
            $success = 'Pengembalian disetujui tahap 1. Menunggu user upload dokumen.';
        }
    }
    
    elseif ($action === 'reject_return' && $loan_id) {
        $rejection_note = trim($_POST['rejection_note'] ?? '');
        
        // Get loan details for notification
        $stmt = $pdo->prepare('SELECT l.*, i.name AS inventory_name FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.id = ?');
        $stmt->execute([$loan_id]);
        $loan = $stmt->fetch();
        
        $stmt = $pdo->prepare('UPDATE loans SET return_stage = ?, return_rejection_note = ? WHERE id = ?');
        $stmt->execute(['return_rejected', $rejection_note, $loan_id]);
        
        // Create notification
        if ($loan) {
            $notifTitle = 'Pengajuan Pengembalian Ditolak';
            $notifMessage = 'Pengajuan pengembalian Anda untuk "' . $loan['inventory_name'] . '" telah ditolak.';
            if ($rejection_note) {
                $notifMessage .= ' Alasan: ' . $rejection_note;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type)
                VALUES (?, 'return_rejected', ?, ?, ?, 'loan')
            ");
            $stmt->execute([$loan['user_id'], $notifTitle, $notifMessage, $loan_id]);
        }
        
        $success = 'Pengajuan pengembalian ditolak.';
    }
    
    elseif ($action === 'final_approve_return' && $loan_id) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT l.*, i.name AS inventory_name FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.id = ? FOR UPDATE');
            $stmt->execute([$loan_id]);
            $loan = $stmt->fetch();
            
            if (!$loan) throw new Exception('Peminjaman tidak ditemukan');
            if (($loan['return_stage'] ?? '') !== 'return_submitted') throw new Exception('Pengembalian tidak dalam tahap submitted');
            
            $stmt = $pdo->prepare('UPDATE inventories SET stock_available = stock_available + ? WHERE id = ?');
            $stmt->execute([$loan['quantity'], $loan['inventory_id']]);
            
            $stmt = $pdo->prepare('UPDATE loans SET return_stage = ?, status = ?, returned_at = NOW() WHERE id = ?');
            $stmt->execute(['return_approved', 'returned', $loan_id]);
            
            // Create notification
            $notifTitle = 'Pengembalian Berhasil';
            $notifMessage = 'Pengembalian Anda untuk "' . $loan['inventory_name'] . '" telah berhasil dikonfirmasi. Terima kasih!';
            
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type)
                VALUES (?, 'return_approved', ?, ?, ?, 'loan')
            ");
            $stmt->execute([$loan['user_id'], $notifTitle, $notifMessage, $loan_id]);
            
            $pdo->commit();
            $success = 'Pengembalian berhasil disetujui. Stok telah dikembalikan.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
    
    elseif ($action === 'final_reject_return' && $loan_id) {
        $rejection_note = trim($_POST['rejection_note'] ?? '');
        
        // Get loan details for notification
        $stmt = $pdo->prepare('SELECT l.*, i.name AS inventory_name FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.id = ?');
        $stmt->execute([$loan_id]);
        $loan = $stmt->fetch();
        
        $stmt = $pdo->prepare('UPDATE loans SET return_stage = ?, return_rejection_note = ? WHERE id = ?');
        $stmt->execute(['return_rejected', $rejection_note, $loan_id]);
        
        // Create notification
        if ($loan) {
            $notifTitle = 'Pengembalian Ditolak';
            $notifMessage = 'Pengembalian Anda untuk "' . $loan['inventory_name'] . '" telah ditolak.';
            if ($rejection_note) {
                $notifMessage .= ' Alasan: ' . $rejection_note;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type)
                VALUES (?, 'return_rejected', ?, ?, ?, 'loan')
            ");
            $stmt->execute([$loan['user_id'], $notifTitle, $notifMessage, $loan_id]);
        }
        
        $success = 'Pengembalian ditolak.';
    }
}

// Fetch loans with return requests
$stmt = $pdo->query("
    SELECT l.*, u.name AS user_name, u.email AS user_email, i.name AS inventory_name, i.code AS inventory_code, i.image AS inventory_image
    FROM loans l
    JOIN users u ON u.id = l.user_id
    JOIN inventories i ON i.id = l.inventory_id
    WHERE l.return_stage IS NOT NULL AND l.return_stage != 'none'
    ORDER BY l.return_requested_at DESC
");
$returns = $stmt->fetchAll();

// Stats
$pendingCount = count(array_filter($returns, fn($r) => $r['return_stage'] === 'pending_return'));
$awaitingDocCount = count(array_filter($returns, fn($r) => $r['return_stage'] === 'awaiting_return_doc'));
$submittedCount = count(array_filter($returns, fn($r) => $r['return_stage'] === 'return_submitted'));
$approvedCount = count(array_filter($returns, fn($r) => $r['return_stage'] === 'return_approved'));

$returnStageLabels = [
    'pending_return' => ['Menunggu Validasi', 'warning', 'hourglass'],
    'awaiting_return_doc' => ['Menunggu Dokumen', 'info', 'file-earmark'],
    'return_submitted' => ['Validasi Final', 'primary', 'clock'],
    'return_approved' => ['Dikembalikan', 'success', 'check-circle'],
    'return_rejected' => ['Ditolak', 'danger', 'x-circle']
];
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">
            <i class="bi bi-box-arrow-in-left me-2"></i>Kelola Pengembalian
        </h1>
        <p class="text-muted mb-0">Kelola permintaan pengembalian barang dari karyawan</p>
    </div>
</div>

<!-- Alert Messages -->
<?php if($msg): ?>
<div class="alert alert-info alert-dismissible fade show">
    <i class="bi bi-info-circle me-2"></i><?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php foreach($errors as $e): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<?php if($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #f59e0b, #fbbf24); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="bi bi-hourglass-split" style="color: #fff; font-size: 20px;"></i>
                </div>
                <div>
                    <div style="font-size: 24px; font-weight: 700; color: var(--text-dark);"><?= $pendingCount ?></div>
                    <div style="font-size: 13px; color: var(--text-muted);">Pending Review</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #06b6d4, #22d3ee); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="bi bi-file-earmark" style="color: #fff; font-size: 20px;"></i>
                </div>
                <div>
                    <div style="font-size: 24px; font-weight: 700; color: var(--text-dark);"><?= $awaitingDocCount ?></div>
                    <div style="font-size: 13px; color: var(--text-muted);">Menunggu Dokumen</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--primary), var(--primary-light)); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="bi bi-clock" style="color: #fff; font-size: 20px;"></i>
                </div>
                <div>
                    <div style="font-size: 24px; font-weight: 700; color: var(--text-dark);"><?= $submittedCount ?></div>
                    <div style="font-size: 13px; color: var(--text-muted);">Validasi Final</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #10b981, #34d399); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="bi bi-check-circle" style="color: #fff; font-size: 20px;"></i>
                </div>
                <div>
                    <div style="font-size: 24px; font-weight: 700; color: var(--text-dark);"><?= $approvedCount ?></div>
                    <div style="font-size: 13px; color: var(--text-muted);">Selesai</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Returns Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            <i class="bi bi-list-ul me-2"></i>Daftar Pengembalian
        </h5>
        <div class="input-group" style="width: 250px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" placeholder="Cari..." id="searchReturns">
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($returns)): ?>
        <div class="text-center py-5">
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h5>Belum Ada Pengembalian</h5>
                <p class="text-muted">Belum ada permintaan pengembalian dari karyawan.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="returnsTable">
                <thead>
                    <tr>
                        <th width="60">ID</th>
                        <th>Peminjam</th>
                        <th>Barang</th>
                        <th width="70">Qty</th>
                        <th width="110">Tgl Pinjam</th>
                        <th width="110">Tgl Pengajuan</th>
                        <th width="140">Status</th>
                        <th width="180">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($returns as $l): 
                        $rs = $l['return_stage'] ?? 'none';
                        $stageInfo = $returnStageLabels[$rs] ?? ['Unknown', 'secondary', 'question'];
                    ?>
                    <tr>
                        <td><span class="badge bg-secondary">#<?= $l['id'] ?></span></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="avatar me-2">
                                    <?= strtoupper(substr($l['user_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($l['user_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($l['user_email']) ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <?php if ($l['inventory_image']): ?>
                                <img src="/public/assets/uploads/<?= htmlspecialchars($l['inventory_image']) ?>" 
                                     alt="" class="rounded me-2" 
                                     style="width: 40px; height: 40px; object-fit: cover;">
                                <?php else: ?>
                                <div class="rounded me-2 d-flex align-items-center justify-content-center" 
                                     style="width: 40px; height: 40px; background: var(--bg-tertiary);">
                                    <i class="bi bi-box-seam text-muted"></i>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($l['inventory_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($l['inventory_code']) ?></small>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge bg-primary"><?= $l['quantity'] ?></span></td>
                        <td>
                            <div><?= date('d M Y', strtotime($l['approved_at'] ?? $l['requested_at'])) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($l['approved_at'] ?? $l['requested_at'])) ?></small>
                        </td>
                        <td>
                            <?php if ($l['return_requested_at']): ?>
                            <div><?= date('d M Y', strtotime($l['return_requested_at'])) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($l['return_requested_at'])) ?></small>
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
                            <?php if ($rs === 'pending_return'): ?>
                            <div class="btn-group btn-group-sm">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Setujui pengembalian tahap 1 dan minta dokumen?');">
                                    <input type="hidden" name="action" value="approve_return_stage1">
                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                    <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Approve</button>
                                </form>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectReturnModal<?= $l['id'] ?>">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            
                            <?php elseif ($rs === 'awaiting_return_doc'): ?>
                            <span class="badge bg-info">
                                <i class="bi bi-hourglass me-1"></i>Menunggu Dokumen
                            </span>
                            
                            <?php elseif ($rs === 'return_submitted'): ?>
                            <div class="btn-group btn-group-sm">
                                <?php if ($l['return_document_path']): ?>
                                <a class="btn btn-outline-info" href="/public/<?= htmlspecialchars($l['return_document_path']) ?>" target="_blank" title="Download">
                                    <i class="bi bi-download"></i>
                                </a>
                                <?php endif; ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Setujui pengembalian final dan kembalikan stok?');">
                                    <input type="hidden" name="action" value="final_approve_return">
                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                    <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Final</button>
                                </form>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectFinalReturnModal<?= $l['id'] ?>">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            
                            <?php elseif ($rs === 'return_approved'): ?>
                            <span class="badge bg-success">
                                <i class="bi bi-check-circle me-1"></i>Selesai
                            </span>
                            <?php if ($l['returned_at']): ?>
                            <small class="d-block text-muted"><?= date('d M Y', strtotime($l['returned_at'])) ?></small>
                            <?php endif; ?>
                            
                            <?php elseif ($rs === 'return_rejected'): ?>
                            <span class="badge bg-danger">
                                <i class="bi bi-x-circle me-1"></i>Ditolak
                            </span>
                            
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.avatar {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, var(--primary), var(--primary-light));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 0.875rem;
}

.empty-state {
    padding: 2rem;
}

.empty-state i {
    font-size: 4rem;
    color: var(--text-muted);
    display: block;
    margin-bottom: 1rem;
}

.empty-state h5 {
    color: var(--text-primary);
}
</style>

<script>
document.getElementById('searchReturns')?.addEventListener('input', function(e) {
    const query = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#returnsTable tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(query) ? '' : 'none';
    });
});
</script>

<!-- Rejection Modals -->
<?php foreach($returns as $l): ?>
<?php if ($l['return_stage'] === 'pending_return'): ?>
<div class="modal fade" id="rejectReturnModal<?= $l['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-x-circle me-2" style="color: var(--danger);"></i>
                    Tolak Pengembalian
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reject_return">
                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                    
                    <div style="margin-bottom: 16px; padding: 16px; background: var(--bg-main); border-radius: var(--radius);">
                        <div class="d-flex align-items-center gap-3">
                            <?php if ($l['inventory_image']): ?>
                            <img src="/public/assets/uploads/<?= htmlspecialchars($l['inventory_image']) ?>" 
                                 style="width: 50px; height: 50px; object-fit: cover; border-radius: var(--radius);">
                            <?php endif; ?>
                            <div>
                                <strong><?= htmlspecialchars($l['inventory_name']) ?></strong>
                                <br><small style="color: var(--text-muted);">Diajukan oleh <?= htmlspecialchars($l['user_name']) ?> • <?= $l['quantity'] ?> unit</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" style="color: var(--text-dark); font-weight: 500;">
                            Alasan Penolakan <span class="text-danger">*</span>
                        </label>
                        <textarea name="rejection_note" class="form-control" rows="4" 
                                  placeholder="Berikan alasan mengapa pengembalian ini ditolak..." required></textarea>
                        <small style="color: var(--text-muted);">Catatan ini akan dikirimkan kepada karyawan.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-lg me-1"></i> Tolak Pengembalian
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($l['return_stage'] === 'return_submitted'): ?>
<div class="modal fade" id="rejectFinalReturnModal<?= $l['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-x-circle me-2" style="color: var(--danger);"></i>
                    Tolak Pengembalian Final
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="final_reject_return">
                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                    
                    <div style="margin-bottom: 16px; padding: 16px; background: var(--bg-main); border-radius: var(--radius);">
                        <div class="d-flex align-items-center gap-3">
                            <?php if ($l['inventory_image']): ?>
                            <img src="/public/assets/uploads/<?= htmlspecialchars($l['inventory_image']) ?>" 
                                 style="width: 50px; height: 50px; object-fit: cover; border-radius: var(--radius);">
                            <?php endif; ?>
                            <div>
                                <strong><?= htmlspecialchars($l['inventory_name']) ?></strong>
                                <br><small style="color: var(--text-muted);">Diajukan oleh <?= htmlspecialchars($l['user_name']) ?> • <?= $l['quantity'] ?> unit</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" style="color: var(--text-dark); font-weight: 500;">
                            Alasan Penolakan <span class="text-danger">*</span>
                        </label>
                        <textarea name="rejection_note" class="form-control" rows="4" 
                                  placeholder="Berikan alasan mengapa dokumen pengembalian ini ditolak..." required></textarea>
                        <small style="color: var(--text-muted);">Catatan ini akan dikirimkan kepada karyawan.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-lg me-1"></i> Tolak Pengembalian
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>
