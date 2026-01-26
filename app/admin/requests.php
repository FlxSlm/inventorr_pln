<?php
// app/admin/requests.php - Admin Requests Management
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
    $request_id = (int)($_POST['request_id'] ?? 0);
    
    if ($action === 'approve_stage1' && $request_id) {
        $stmt = $pdo->prepare('SELECT * FROM requests WHERE id = ?');
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        
        if ($request && $request['stage'] === 'pending') {
            $stmt = $pdo->prepare('UPDATE requests SET stage = "awaiting_document", status = "approved", approved_at = NOW() WHERE id = ?');
            $stmt->execute([$request_id]);
            
            // Create notification
            $stmt = $pdo->prepare('SELECT i.name FROM inventories i WHERE id = ?');
            $stmt->execute([$request['inventory_id']]);
            $inv = $stmt->fetch();
            
            $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type) VALUES (?, 'request_approved', ?, ?, ?, 'request')");
            $notifStmt->execute([$request['user_id'], 'Permintaan Disetujui', 'Permintaan Anda untuk "' . $inv['name'] . '" telah disetujui. Silakan upload dokumen serah terima.', $request_id]);
            
            $success = 'Permintaan disetujui. Menunggu upload dokumen dari karyawan.';
        }
    }
    
    elseif ($action === 'reject' && $request_id) {
        $rejection_note = trim($_POST['rejection_note'] ?? '');
        
        $stmt = $pdo->prepare('SELECT r.*, i.name as inventory_name FROM requests r JOIN inventories i ON i.id = r.inventory_id WHERE r.id = ?');
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        
        if ($request) {
            $stmt = $pdo->prepare('UPDATE requests SET stage = "rejected", status = "rejected", rejection_note = ? WHERE id = ?');
            $stmt->execute([$rejection_note, $request_id]);
            
            $notifMsg = 'Permintaan Anda untuk "' . $request['inventory_name'] . '" telah ditolak.';
            if ($rejection_note) $notifMsg .= ' Alasan: ' . $rejection_note;
            
            $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type) VALUES (?, 'request_rejected', ?, ?, ?, 'request')");
            $notifStmt->execute([$request['user_id'], 'Permintaan Ditolak', $notifMsg, $request_id]);
            
            $success = 'Permintaan ditolak.';
        }
    }
    
    elseif ($action === 'final_approve' && $request_id) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT r.*, i.name as inventory_name FROM requests r JOIN inventories i ON i.id = r.inventory_id WHERE r.id = ? FOR UPDATE');
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            
            if (!$request || $request['stage'] !== 'submitted') {
                throw new Exception('Permintaan tidak valid');
            }
            
            // Reduce stock_total and stock_available
            $stmt = $pdo->prepare('UPDATE inventories SET stock_total = stock_total - ?, stock_available = stock_available - ? WHERE id = ? AND stock_total >= ? AND stock_available >= ?');
            $stmt->execute([$request['quantity'], $request['quantity'], $request['inventory_id'], $request['quantity'], $request['quantity']]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Stok tidak mencukupi');
            }
            
            $stmt = $pdo->prepare('UPDATE requests SET stage = "approved", status = "completed", completed_at = NOW() WHERE id = ?');
            $stmt->execute([$request_id]);
            
            // Notification
            $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type) VALUES (?, 'request_completed', ?, ?, ?, 'request')");
            $notifStmt->execute([$request['user_id'], 'Permintaan Selesai', 'Permintaan Anda untuk "' . $request['inventory_name'] . '" telah selesai. Barang dapat diambil.', $request_id]);
            
            $pdo->commit();
            $success = 'Permintaan disetujui final. Stok telah dikurangi.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
    
    elseif ($action === 'final_reject' && $request_id) {
        $rejection_note = trim($_POST['rejection_note'] ?? '');
        
        $stmt = $pdo->prepare('SELECT r.*, i.name as inventory_name FROM requests r JOIN inventories i ON i.id = r.inventory_id WHERE r.id = ?');
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        
        if ($request) {
            $stmt = $pdo->prepare('UPDATE requests SET stage = "rejected", status = "rejected", rejection_note = ? WHERE id = ?');
            $stmt->execute([$rejection_note, $request_id]);
            
            $notifMsg = 'Permintaan Anda untuk "' . $request['inventory_name'] . '" ditolak pada tahap final.';
            if ($rejection_note) $notifMsg .= ' Alasan: ' . $rejection_note;
            
            $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type) VALUES (?, 'request_rejected', ?, ?, ?, 'request')");
            $notifStmt->execute([$request['user_id'], 'Permintaan Ditolak', $notifMsg, $request_id]);
            
            $success = 'Permintaan ditolak.';
        }
    }
}

// Fetch all requests
$stmt = $pdo->query("
    SELECT r.*, u.name AS user_name, u.email AS user_email, i.name AS inventory_name, i.code AS inventory_code, i.image AS inventory_image
    FROM requests r
    JOIN users u ON u.id = r.user_id
    JOIN inventories i ON i.id = r.inventory_id
    ORDER BY r.requested_at DESC
");
$requests = $stmt->fetchAll();

// Stats
$pendingCount = count(array_filter($requests, fn($r) => $r['stage'] === 'pending'));
$awaitingDocCount = count(array_filter($requests, fn($r) => $r['stage'] === 'awaiting_document'));
$submittedCount = count(array_filter($requests, fn($r) => $r['stage'] === 'submitted'));
$completedCount = count(array_filter($requests, fn($r) => $r['stage'] === 'approved'));

$stageLabels = [
    'pending' => ['Menunggu Validasi', 'warning', 'hourglass'],
    'awaiting_document' => ['Menunggu Dokumen', 'info', 'file-earmark'],
    'submitted' => ['Validasi Final', 'primary', 'clock'],
    'approved' => ['Selesai', 'success', 'check-circle'],
    'rejected' => ['Ditolak', 'danger', 'x-circle']
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">
            <i class="bi bi-cart-check me-2"></i>Kelola Permintaan
        </h1>
        <p class="text-muted mb-0">Kelola permintaan barang dari karyawan</p>
    </div>
</div>

<?php if($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php foreach($errors as $e): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

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
                    <div style="font-size: 13px; color: var(--text-muted);">Pending</div>
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
                    <div style="font-size: 24px; font-weight: 700; color: var(--text-dark);"><?= $completedCount ?></div>
                    <div style="font-size: 13px; color: var(--text-muted);">Selesai</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Requests Table -->
<div class="modern-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            <i class="bi bi-list-ul me-2" style="color: var(--primary-light);"></i>Daftar Permintaan
        </h5>
        <div class="input-group" style="width: 250px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" placeholder="Cari..." id="searchRequests">
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($requests)): ?>
        <div class="text-center py-5">
            <div class="empty-state">
                <div class="empty-state-icon"><i class="bi bi-inbox"></i></div>
                <h5 class="empty-state-title">Belum Ada Permintaan</h5>
                <p class="empty-state-text">Belum ada permintaan dari karyawan.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="requestsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Pemohon</th>
                        <th>Barang</th>
                        <th>Qty</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($requests as $r): 
                        $stage = $r['stage'] ?? 'pending';
                        $stageInfo = $stageLabels[$stage] ?? ['Unknown', 'secondary', 'question'];
                    ?>
                    <tr>
                        <td><span class="badge bg-secondary">#<?= $r['id'] ?></span></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="avatar me-2"><?= strtoupper(substr($r['user_name'], 0, 1)) ?></div>
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($r['user_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($r['user_email']) ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <?php if ($r['inventory_image']): ?>
                                <img src="/public/assets/uploads/<?= htmlspecialchars($r['inventory_image']) ?>" alt="" class="rounded me-2" style="width: 40px; height: 40px; object-fit: cover;">
                                <?php else: ?>
                                <div class="rounded me-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; background: var(--bg-main);">
                                    <i class="bi bi-box-seam text-muted"></i>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($r['inventory_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($r['inventory_code']) ?></small>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge bg-primary"><?= $r['quantity'] ?></span></td>
                        <td>
                            <div><?= date('d M Y', strtotime($r['requested_at'])) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($r['requested_at'])) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-<?= $stageInfo[1] ?>">
                                <i class="bi bi-<?= $stageInfo[2] ?> me-1"></i><?= $stageInfo[0] ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($stage === 'pending'): ?>
                            <div class="btn-group btn-group-sm">
                                <?php if (!empty($r['notes'])): ?>
                                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#viewNoteModal<?= $r['id'] ?>" title="Lihat Catatan">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <?php endif; ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Setujui permintaan ini?');">
                                    <input type="hidden" name="action" value="approve_stage1">
                                    <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Approve</button>
                                </form>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $r['id'] ?>">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            
                            <?php elseif ($stage === 'awaiting_document'): ?>
                            <div class="d-flex gap-1">
                                <?php if (!empty($r['notes'])): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#viewNoteModal<?= $r['id'] ?>" title="Lihat Catatan">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <?php endif; ?>
                                <span class="badge bg-info"><i class="bi bi-hourglass me-1"></i>Menunggu Dokumen</span>
                            </div>
                            
                            <?php elseif ($stage === 'submitted'): ?>
                            <div class="btn-group btn-group-sm">
                                <?php if (!empty($r['notes'])): ?>
                                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#viewNoteModal<?= $r['id'] ?>" title="Lihat Catatan">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($r['document_path']): ?>
                                <a class="btn-download-doc btn-sm" href="/public/assets/<?= htmlspecialchars($r['document_path']) ?>" target="_blank">
                                    <i class="bi bi-file-earmark-arrow-down"></i> Dokumen
                                </a>
                                <?php endif; ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Setujui final dan kurangi stok?');">
                                    <input type="hidden" name="action" value="final_approve">
                                    <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Final</button>
                                </form>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectFinalModal<?= $r['id'] ?>">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            
                            <?php else: ?>
                            <?php if (!empty($r['notes'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#viewNoteModal<?= $r['id'] ?>" title="Lihat Catatan">
                                <i class="bi bi-eye me-1"></i>Lihat Catatan
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <!-- Reject Modal -->
                    <div class="modal fade" id="rejectModal<?= $r['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Tolak Permintaan #<?= $r['id'] ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                        <div class="mb-3">
                                            <label class="form-label">Alasan Penolakan</label>
                                            <textarea name="rejection_note" class="form-control" rows="3" placeholder="Masukkan alasan penolakan..."></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                        <button type="submit" class="btn btn-danger">Tolak Permintaan</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- View Note Modal -->
                    <div class="modal fade" id="viewNoteModal<?= $r['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title"><i class="bi bi-chat-left-text me-2"></i>Catatan Permintaan #<?= $r['id'] ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Pemohon</label>
                                        <p class="mb-0"><?= htmlspecialchars($r['user_name']) ?> (<?= htmlspecialchars($r['user_email']) ?>)</p>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Barang</label>
                                        <p class="mb-0"><?= htmlspecialchars($r['inventory_name']) ?> (<?= htmlspecialchars($r['inventory_code']) ?>)</p>
                                    </div>
                                    <div class="mb-0">
                                        <label class="form-label fw-semibold">Catatan dari Pemohon</label>
                                        <div class="p-3 rounded" style="background: var(--bg-main); border: 1px solid var(--border-color);">
                                            <?= !empty($r['notes']) ? nl2br(htmlspecialchars($r['notes'])) : '<span class="text-muted">Tidak ada catatan</span>' ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Final Reject Modal -->
                    <div class="modal fade" id="rejectFinalModal<?= $r['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Tolak Permintaan Final #<?= $r['id'] ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="action" value="final_reject">
                                        <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                        <div class="mb-3">
                                            <label class="form-label">Alasan Penolakan</label>
                                            <textarea name="rejection_note" class="form-control" rows="3" placeholder="Masukkan alasan penolakan..."></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                        <button type="submit" class="btn btn-danger">Tolak Permintaan</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.avatar { width: 36px; height: 36px; background: var(--primary-light); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; }
</style>

<script>
document.getElementById('searchRequests')?.addEventListener('input', function() {
    const term = this.value.toLowerCase();
    document.querySelectorAll('#requestsTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
    });
});
</script>
