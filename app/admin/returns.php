<?php
// app/admin/returns.php
// Admin page to manage item returns

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
    
    // Approve return stage 1 (request document)
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
    
    // Reject return
    elseif ($action === 'reject_return' && $loan_id) {
        $stmt = $pdo->prepare('UPDATE loans SET return_stage = ?, return_note = CONCAT(IFNULL(return_note,""), "\n[admin] ditolak pada ", NOW()) WHERE id = ?');
        $stmt->execute(['return_rejected', $loan_id]);
        $success = 'Pengajuan pengembalian ditolak.';
    }
    
    // Final approve return
    elseif ($action === 'final_approve_return' && $loan_id) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = ? FOR UPDATE');
            $stmt->execute([$loan_id]);
            $loan = $stmt->fetch();
            
            if (!$loan) throw new Exception('Peminjaman tidak ditemukan');
            if (($loan['return_stage'] ?? '') !== 'return_submitted') throw new Exception('Pengembalian tidak dalam tahap submitted');
            
            // Return stock to inventory
            $stmt = $pdo->prepare('UPDATE inventories SET stock_available = stock_available + ? WHERE id = ?');
            $stmt->execute([$loan['quantity'], $loan['inventory_id']]);
            
            // Update loan status
            $stmt = $pdo->prepare('UPDATE loans SET return_stage = ?, status = ?, returned_at = NOW() WHERE id = ?');
            $stmt->execute(['return_approved', 'returned', $loan_id]);
            
            $pdo->commit();
            $success = 'Pengembalian berhasil disetujui. Stok telah dikembalikan.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
    
    // Final reject return
    elseif ($action === 'final_reject_return' && $loan_id) {
        $stmt = $pdo->prepare('UPDATE loans SET return_stage = ?, return_note = CONCAT(IFNULL(return_note,""), "\n[admin] ditolak final pada ", NOW()) WHERE id = ?');
        $stmt->execute(['return_rejected', $loan_id]);
        $success = 'Pengembalian ditolak.';
    }
}

// Fetch loans with return requests (excluding 'none' and 'return_approved')
$stmt = $pdo->query("
    SELECT l.*, u.name AS user_name, u.email AS user_email, i.name AS inventory_name, i.code AS inventory_code, i.image AS inventory_image
    FROM loans l
    JOIN users u ON u.id = l.user_id
    JOIN inventories i ON i.id = l.inventory_id
    WHERE l.return_stage IS NOT NULL AND l.return_stage != 'none'
    ORDER BY l.return_requested_at DESC
");
$returns = $stmt->fetchAll();

$returnStageLabels = [
    'pending_return' => ['Menunggu Validasi 1', 'warning', 'text-dark'],
    'awaiting_return_doc' => ['Menunggu Dokumen', 'info', ''],
    'return_submitted' => ['Menunggu Validasi 2', 'primary', ''],
    'return_approved' => ['Dikembalikan', 'success', ''],
    'return_rejected' => ['Ditolak', 'danger', '']
];
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-0"><i class="bi bi-box-arrow-in-left me-2"></i>Kelola Pengembalian</h4>
        </div>
        <?php if($msg): ?>
            <div class="alert alert-info mb-0 py-1 px-3"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>
    </div>
    
    <?php foreach($errors as $e): ?>
        <div class="alert alert-danger m-3 mb-0"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    
    <?php if($success): ?>
        <div class="alert alert-success m-3 mb-0"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <div class="card-body p-0">
        <?php if (empty($returns)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox text-secondary" style="font-size: 4rem;"></i>
                <h5 class="mt-3 text-secondary">Belum Ada Pengembalian</h5>
                <p class="text-secondary">Belum ada permintaan pengembalian dari karyawan.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Peminjam</th>
                            <th>Barang</th>
                            <th>Qty</th>
                            <th>Tgl Pinjam</th>
                            <th>Tgl Ajukan Kembali</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($returns as $l): 
                            $rs = $l['return_stage'] ?? 'none';
                            $stageInfo = $returnStageLabels[$rs] ?? ['Unknown', 'secondary', ''];
                        ?>
                            <tr>
                                <td><span class="badge bg-secondary">#<?= $l['id'] ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="me-2" style="width: 35px; height: 35px; background: linear-gradient(135deg, #0F75BC, #1E88E5); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                                            <?= strtoupper(substr($l['user_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($l['user_name']) ?></div>
                                            <small class="text-secondary"><?= htmlspecialchars($l['user_email']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if ($l['inventory_image']): ?>
                                            <img src="/public/assets/uploads/<?= htmlspecialchars($l['inventory_image']) ?>" 
                                                 alt="" class="me-2" 
                                                 style="width: 40px; height: 40px; object-fit: cover; border-radius: 8px;">
                                        <?php else: ?>
                                            <div class="me-2" style="width: 40px; height: 40px; background: rgba(255,255,255,0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                <i class="bi bi-box-seam text-secondary"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div><?= htmlspecialchars($l['inventory_name']) ?></div>
                                            <small class="text-secondary"><?= htmlspecialchars($l['inventory_code']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="fw-bold text-pln-yellow"><?= $l['quantity'] ?></span></td>
                                <td>
                                    <small>
                                        <?= date('d M Y', strtotime($l['approved_at'] ?? $l['requested_at'])) ?><br>
                                        <span class="text-secondary"><?= date('H:i', strtotime($l['approved_at'] ?? $l['requested_at'])) ?></span>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($l['return_requested_at']): ?>
                                        <small>
                                            <?= date('d M Y', strtotime($l['return_requested_at'])) ?><br>
                                            <span class="text-secondary"><?= date('H:i', strtotime($l['return_requested_at'])) ?></span>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $stageInfo[1] ?> <?= $stageInfo[2] ?>"><?= $stageInfo[0] ?></span>
                                </td>
                                <td>
                                    <?php if ($rs === 'pending_return'): ?>
                                        <div class="btn-group btn-group-sm">
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Setujui pengembalian tahap 1 dan minta dokumen?');">
                                                <input type="hidden" name="action" value="approve_return_stage1">
                                                <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                                <button class="btn btn-success" title="Approve & Request Doc">
                                                    <i class="bi bi-check-lg"></i> Approve
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Tolak pengembalian ini?');">
                                                <input type="hidden" name="action" value="reject_return">
                                                <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                                <button class="btn btn-danger"><i class="bi bi-x-lg"></i></button>
                                            </form>
                                        </div>
                                        
                                    <?php elseif ($rs === 'awaiting_return_doc'): ?>
                                        <span class="text-muted small">Menunggu upload dokumen dari user</span>
                                        
                                    <?php elseif ($rs === 'return_submitted'): ?>
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($l['return_document_path']): ?>
                                                <a class="btn btn-info" href="/public/<?= htmlspecialchars($l['return_document_path']) ?>" target="_blank" title="Download Dokumen">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            <?php endif; ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Setujui pengembalian final dan kembalikan stok?');">
                                                <input type="hidden" name="action" value="final_approve_return">
                                                <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                                <button class="btn btn-success"><i class="bi bi-check-lg"></i> Final</button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Tolak pengembalian ini?');">
                                                <input type="hidden" name="action" value="final_reject_return">
                                                <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                                <button class="btn btn-danger"><i class="bi bi-x-lg"></i></button>
                                            </form>
                                        </div>
                                        
                                    <?php elseif ($rs === 'return_approved'): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Selesai</span>
                                        <?php if ($l['returned_at']): ?>
                                            <small class="d-block text-muted"><?= date('d M Y', strtotime($l['returned_at'])) ?></small>
                                        <?php endif; ?>
                                        
                                    <?php elseif ($rs === 'return_rejected'): ?>
                                        <span class="text-danger"><i class="bi bi-x-circle me-1"></i>Ditolak</span>
                                        
                                    <?php else: ?>
                                        <span class="text-secondary">—</span>
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
