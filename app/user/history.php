<?php
// app/user/history_new.php - Modern History Page
if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$userId = $_SESSION['user']['id'];
$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';

$msg = $_GET['msg'] ?? '';
$errors = [];
$success = '';

// === HANDLE POST ACTIONS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $loan_id = (int)($_POST['loan_id'] ?? 0);

    // === USER UPLOAD LOAN DOCUMENT ===
    if ($action === 'upload_document' && $loan_id) {
        $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = ? AND user_id = ?');
        $stmt->execute([$loan_id, $userId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            $errors[] = 'Peminjaman tidak ditemukan.';
        } elseif ($loan['stage'] !== 'awaiting_document') {
            $errors[] = 'Peminjaman ini tidak dalam tahap menunggu dokumen.';
        } else {
            if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Silakan pilih file untuk diupload.';
            } else {
                $allowedExt = ['xlsx', 'xls'];
                $maxBytes = 5 * 1024 * 1024;
                $file = $_FILES['document'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, $allowedExt)) {
                    $errors[] = 'Hanya file Excel (.xlsx, .xls) yang diperbolehkan.';
                } elseif ($file['size'] > $maxBytes) {
                    $errors[] = 'File terlalu besar (maksimal 5 MB).';
                } else {
                    $destDir = __DIR__ . '/../../public/assets/uploads/documents/';
                    if (!is_dir($destDir)) mkdir($destDir, 0775, true);
                    $safe = 'loan_' . $loan_id . '_user_' . $userId . '_' . time() . '.' . $ext;

                    if (move_uploaded_file($file['tmp_name'], $destDir . $safe)) {
                        $relpath = 'assets/uploads/documents/' . $safe;
                        $stmt = $pdo->prepare('UPDATE loans SET document_path = ?, document_submitted_at = NOW(), stage = ? WHERE id = ?');
                        $stmt->execute([$relpath, 'submitted', $loan_id]);
                        $success = 'Dokumen berhasil diupload. Menunggu persetujuan admin.';
                    } else {
                        $errors[] = 'Gagal mengupload file.';
                    }
                }
            }
        }
    }

    // === ADMIN FINAL APPROVE (stage submitted → approved) ===
    elseif ($action === 'final_approve' && $isAdmin && $loan_id) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT l.*, i.stock_available FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.id = ? FOR UPDATE');
            $stmt->execute([$loan_id]);
            $loan = $stmt->fetch();

            if (!$loan) throw new Exception('Peminjaman tidak ditemukan');
            if ($loan['stage'] !== 'submitted') throw new Exception('Tidak dalam tahap submitted');
            if ($loan['stock_available'] < $loan['quantity']) throw new Exception('Stok tidak cukup');

            $stmt = $pdo->prepare('UPDATE inventories SET stock_available = stock_available - ? WHERE id = ?');
            $stmt->execute([$loan['quantity'], $loan['inventory_id']]);

            $stmt = $pdo->prepare('UPDATE loans SET stage = ?, status = ?, approved_at = NOW() WHERE id = ?');
            $stmt->execute(['approved', 'approved', $loan_id]);

            $pdo->commit();
            $success = 'Peminjaman berhasil disetujui & stok dikurangi.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }

    // === ADMIN FINAL REJECT ===
    elseif ($action === 'final_reject' && $isAdmin && $loan_id) {
        $stmt = $pdo->prepare('UPDATE loans SET stage = ?, status = ?, note = CONCAT(IFNULL(note,""), "\n[admin] ditolak pada ", NOW()) WHERE id = ?');
        $stmt->execute(['rejected', 'rejected', $loan_id]);
        $success = 'Peminjaman ditolak.';
    }

    // === USER REQUEST RETURN ===
    elseif ($action === 'request_return' && $loan_id) {
        $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = ? AND user_id = ?');
        $stmt->execute([$loan_id, $userId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            $errors[] = 'Peminjaman tidak ditemukan.';
        } elseif ($loan['stage'] !== 'approved' || ($loan['return_stage'] ?? 'none') !== 'none') {
            $errors[] = 'Barang ini tidak dapat diajukan pengembalian.';
        } else {
            $note = trim($_POST['return_note'] ?? '');
            $stmt = $pdo->prepare('UPDATE loans SET return_stage = ?, return_requested_at = NOW(), return_note = ? WHERE id = ?');
            $stmt->execute(['pending_return', $note, $loan_id]);
            $success = 'Pengajuan pengembalian berhasil. Menunggu persetujuan admin.';
        }
    }

    // === ADMIN APPROVE RETURN STAGE 1 (Request Doc) ===
    elseif ($action === 'approve_return_stage1' && $isAdmin && $loan_id) {
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

    // === ADMIN REJECT RETURN ===
    elseif ($action === 'reject_return' && $isAdmin && $loan_id) {
        $stmt = $pdo->prepare('UPDATE loans SET return_stage = ?, return_note = CONCAT(IFNULL(return_note,""), "\n[admin] ditolak pada ", NOW()) WHERE id = ?');
        $stmt->execute(['return_rejected', $loan_id]);
        $success = 'Pengajuan pengembalian ditolak.';
    }

    // === USER UPLOAD RETURN DOCUMENT ===
    elseif ($action === 'upload_return_document' && $loan_id) {
        $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = ? AND user_id = ?');
        $stmt->execute([$loan_id, $userId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            $errors[] = 'Peminjaman tidak ditemukan.';
        } elseif (($loan['return_stage'] ?? '') !== 'awaiting_return_doc') {
            $errors[] = 'Pengembalian ini tidak menunggu dokumen.';
        } else {
            if (!isset($_FILES['return_document']) || $_FILES['return_document']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Silakan pilih file untuk diupload.';
            } else {
                $allowedExt = ['xlsx', 'xls'];
                $maxBytes = 5 * 1024 * 1024;
                $file = $_FILES['return_document'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, $allowedExt)) {
                    $errors[] = 'Hanya file Excel (.xlsx, .xls) yang diperbolehkan.';
                } elseif ($file['size'] > $maxBytes) {
                    $errors[] = 'File terlalu besar (maksimal 5 MB).';
                } else {
                    $destDir = __DIR__ . '/../../public/assets/uploads/documents/';
                    if (!is_dir($destDir)) mkdir($destDir, 0775, true);
                    $safe = 'return_' . $loan_id . '_user_' . $userId . '_' . time() . '.' . $ext;

                    if (move_uploaded_file($file['tmp_name'], $destDir . $safe)) {
                        $relpath = 'assets/uploads/documents/' . $safe;
                        $stmt = $pdo->prepare('UPDATE loans SET return_document_path = ?, return_document_submitted_at = NOW(), return_stage = ? WHERE id = ?');
                        $stmt->execute([$relpath, 'return_submitted', $loan_id]);
                        $success = 'Dokumen pengembalian berhasil diupload. Menunggu persetujuan admin.';
                    } else {
                        $errors[] = 'Gagal mengupload file.';
                    }
                }
            }
        }
    }

    // === ADMIN FINAL APPROVE RETURN ===
    elseif ($action === 'final_approve_return' && $isAdmin && $loan_id) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = ? FOR UPDATE');
            $stmt->execute([$loan_id]);
            $loan = $stmt->fetch();

            if (!$loan) throw new Exception('Peminjaman tidak ditemukan');
            if (($loan['return_stage'] ?? '') !== 'return_submitted') throw new Exception('Pengembalian tidak dalam tahap submitted');

            $stmt = $pdo->prepare('UPDATE inventories SET stock_available = stock_available + ? WHERE id = ?');
            $stmt->execute([$loan['quantity'], $loan['inventory_id']]);

            $stmt = $pdo->prepare('UPDATE loans SET return_stage = ?, status = ?, returned_at = NOW() WHERE id = ?');
            $stmt->execute(['return_approved', 'returned', $loan_id]);

            $pdo->commit();
            $success = 'Pengembalian berhasil disetujui. Stok telah dikembalikan.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }

    // === ADMIN FINAL REJECT RETURN ===
    elseif ($action === 'final_reject_return' && $isAdmin && $loan_id) {
        $stmt = $pdo->prepare('UPDATE loans SET return_stage = ?, return_note = CONCAT(IFNULL(return_note,""), "\n[admin] ditolak final pada ", NOW()) WHERE id = ?');
        $stmt->execute(['return_rejected', $loan_id]);
        $success = 'Pengembalian ditolak.';
    }
}

// Fetch user's loans
$stmt = $pdo->prepare("
  SELECT l.*, i.name AS inventory_name, i.code AS inventory_code, i.image AS inventory_image
  FROM loans l
  JOIN inventories i ON i.id = l.inventory_id
  WHERE l.user_id = ?
  ORDER BY l.requested_at DESC
");
$stmt->execute([$userId]);
$loans = $stmt->fetchAll();

// Calculate stats
$totalLoans = count($loans);
$activeLoans = count(array_filter($loans, fn($l) => $l['stage'] === 'approved' && ($l['return_stage'] ?? 'none') !== 'return_approved'));
$pendingLoans = count(array_filter($loans, fn($l) => in_array($l['stage'], ['pending', 'awaiting_document', 'submitted'])));
$completedLoans = count(array_filter($loans, fn($l) => ($l['return_stage'] ?? 'none') === 'return_approved'));
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">
            <i class="bi bi-clock-history me-2"></i>Riwayat Peminjaman
        </h1>
        <p class="text-muted mb-0">Kelola dan pantau status peminjaman barang Anda</p>
    </div>
    <a href="/index.php?page=catalog" class="btn btn-primary">
        <i class="bi bi-plus-lg me-2"></i>Pinjam Baru
    </a>
</div>

<!-- Alert Messages -->
<?php if ($msg): ?>
<div class="alert alert-info alert-dismissible fade show" role="alert">
    <i class="bi bi-info-circle me-2"></i><?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php foreach($errors as $e): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<?php if($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

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
        <ul class="nav nav-pills" id="historyTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-filter="all">
                    <i class="bi bi-grid me-1"></i>Semua
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-filter="pending">
                    <i class="bi bi-hourglass me-1"></i>Menunggu
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-filter="active">
                    <i class="bi bi-box-seam me-1"></i>Aktif
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-filter="completed">
                    <i class="bi bi-check-circle me-1"></i>Selesai
                </button>
            </li>
        </ul>
    </div>
</div>

<!-- Loans Table -->
<div class="card">
    <div class="card-body p-0">
        <?php if(empty($loans)): ?>
        <div class="text-center py-5">
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h5>Belum Ada Peminjaman</h5>
                <p class="text-muted">Anda belum memiliki riwayat peminjaman barang.</p>
                <a href="/index.php?page=catalog" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>Pinjam Sekarang
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="loansTable">
                <thead>
                    <tr>
                        <th width="50">#</th>
                        <th>Barang</th>
                        <th width="80">Qty</th>
                        <th width="120">Tanggal</th>
                        <th width="160">Status Peminjaman</th>
                        <th width="160">Status Pengembalian</th>
                        <th width="180">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($loans as $l): 
                        $stageLabels = [
                            'pending' => ['Menunggu Validasi 1', 'warning', 'hourglass'],
                            'awaiting_document' => ['Menunggu Dokumen', 'info', 'file-earmark'],
                            'submitted' => ['Menunggu Validasi 2', 'primary', 'clock'],
                            'approved' => ['Disetujui', 'success', 'check-circle'],
                            'rejected' => ['Ditolak', 'danger', 'x-circle']
                        ];
                        $returnStageLabels = [
                            'none' => ['Belum Dikembalikan', 'secondary', 'dash'],
                            'pending_return' => ['Menunggu Validasi', 'warning', 'hourglass'],
                            'awaiting_return_doc' => ['Menunggu Dokumen', 'info', 'file-earmark'],
                            'return_submitted' => ['Menunggu Validasi', 'primary', 'clock'],
                            'return_approved' => ['Dikembalikan', 'success', 'check-circle'],
                            'return_rejected' => ['Ditolak', 'danger', 'x-circle']
                        ];
                        $stageInfo = $stageLabels[$l['stage']] ?? [$l['stage'], 'secondary', 'question'];
                        $returnInfo = $returnStageLabels[$l['return_stage'] ?? 'none'] ?? ['N/A', 'secondary', 'question'];
                        
                        // Determine filter class
                        $filterClass = 'all';
                        if (in_array($l['stage'], ['pending', 'awaiting_document', 'submitted'])) {
                            $filterClass .= ' pending';
                        } elseif ($l['stage'] === 'approved' && ($l['return_stage'] ?? 'none') !== 'return_approved') {
                            $filterClass .= ' active';
                        } elseif (($l['return_stage'] ?? 'none') === 'return_approved') {
                            $filterClass .= ' completed';
                        }
                    ?>
                    <tr class="loan-row <?= $filterClass ?>">
                        <td>
                            <span class="badge bg-secondary">#<?= $l['id'] ?></span>
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <?php if($l['inventory_image']): ?>
                                <img src="/public/assets/uploads/<?= htmlspecialchars($l['inventory_image']) ?>" 
                                     class="rounded me-2" style="width: 40px; height: 40px; object-fit: cover;">
                                <?php else: ?>
                                <div class="rounded me-2 d-flex align-items-center justify-content-center" 
                                     style="width: 40px; height: 40px; background: var(--bg-tertiary);">
                                    <i class="bi bi-box-seam text-muted"></i>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($l['inventory_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($l['inventory_code'] ?? '') ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-primary"><?= (int)$l['quantity'] ?> unit</span>
                        </td>
                        <td>
                            <div><?= date('d M Y', strtotime($l['requested_at'])) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($l['requested_at'])) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-<?= $stageInfo[1] ?>">
                                <i class="bi bi-<?= $stageInfo[2] ?> me-1"></i><?= $stageInfo[0] ?>
                            </span>
                            <?php if ($l['stage'] === 'rejected' && !empty($l['rejection_note'])): ?>
                            <button type="button" class="btn-alasan ms-1" data-bs-toggle="modal" data-bs-target="#rejectionModal<?= $l['id'] ?>">
                                <i class="bi bi-exclamation-circle"></i> Alasan
                            </button>
                            <?php elseif ($l['note']): ?>
                            <i class="bi bi-info-circle ms-1 text-muted" 
                               data-bs-toggle="tooltip" 
                               title="<?= htmlspecialchars($l['note']) ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($l['stage'] === 'approved'): ?>
                            <span class="badge bg-<?= $returnInfo[1] ?>">
                                <i class="bi bi-<?= $returnInfo[2] ?> me-1"></i><?= $returnInfo[0] ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            // === LOAN DOCUMENT ACTIONS ===
                            if ($l['stage'] === 'awaiting_document'): ?>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal<?= $l['id'] ?>">
                                <i class="bi bi-upload me-1"></i>Upload Dokumen
                            </button>
                            
                            <?php elseif ($l['stage'] === 'submitted' && $isAdmin): ?>
                            <div class="btn-group btn-group-sm">
                                <?php if ($l['document_path']): ?>
                                <a class="btn-download-doc btn-sm" href="/public/<?= htmlspecialchars($l['document_path']) ?>" target="_blank">
                                    <i class="bi bi-file-earmark-arrow-down"></i>
                                </a>
                                <?php endif; ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Setujui peminjaman ini?');">
                                    <input type="hidden" name="action" value="final_approve">
                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                    <button class="btn btn-success" title="Approve"><i class="bi bi-check-lg"></i></button>
                                </form>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Tolak peminjaman ini?');">
                                    <input type="hidden" name="action" value="final_reject">
                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                    <button class="btn btn-danger" title="Reject"><i class="bi bi-x-lg"></i></button>
                                </form>
                            </div>
                            
                            <?php elseif ($l['stage'] === 'submitted'): ?>
                            <span class="badge bg-info"><i class="bi bi-hourglass me-1"></i>Menunggu Review</span>
                            
                            <?php 
                            // === RETURN ACTIONS ===
                            elseif ($l['stage'] === 'approved'): 
                                $rs = $l['return_stage'] ?? 'none';
                                
                                if ($rs === 'none'): ?>
                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#returnModal<?= $l['id'] ?>">
                                <i class="bi bi-box-arrow-left me-1"></i>Ajukan Pengembalian
                            </button>
                            
                            <?php elseif ($rs === 'pending_return' && $isAdmin): ?>
                            <div class="btn-group btn-group-sm">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Setujui pengembalian tahap 1?');">
                                    <input type="hidden" name="action" value="approve_return_stage1">
                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                    <button class="btn btn-success" title="Approve & Request Doc"><i class="bi bi-check-lg"></i></button>
                                </form>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Tolak pengembalian?');">
                                    <input type="hidden" name="action" value="reject_return">
                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                    <button class="btn btn-danger"><i class="bi bi-x-lg"></i></button>
                                </form>
                            </div>
                            
                            <?php elseif ($rs === 'pending_return'): ?>
                            <span class="badge bg-warning"><i class="bi bi-hourglass me-1"></i>Menunggu Validasi</span>
                            
                            <?php elseif ($rs === 'awaiting_return_doc'): ?>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#returnDocModal<?= $l['id'] ?>">
                                <i class="bi bi-upload me-1"></i>Upload Dokumen
                            </button>
                            
                            <?php elseif ($rs === 'return_submitted' && $isAdmin): ?>
                            <div class="btn-group btn-group-sm">
                                <?php if ($l['return_document_path']): ?>
                                <a class="btn btn-outline-info" href="/public/<?= htmlspecialchars($l['return_document_path']) ?>" target="_blank">
                                    <i class="bi bi-download"></i>
                                </a>
                                <?php endif; ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Setujui pengembalian final?');">
                                    <input type="hidden" name="action" value="final_approve_return">
                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                    <button class="btn btn-success"><i class="bi bi-check-lg"></i></button>
                                </form>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Tolak pengembalian?');">
                                    <input type="hidden" name="action" value="final_reject_return">
                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                    <button class="btn btn-danger"><i class="bi bi-x-lg"></i></button>
                                </form>
                            </div>
                            
                            <?php elseif ($rs === 'return_submitted'): ?>
                            <span class="badge bg-info"><i class="bi bi-hourglass me-1"></i>Menunggu Review</span>
                            
                            <?php elseif ($rs === 'return_approved'): ?>
                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Selesai</span>
                            
                            <?php elseif ($rs === 'return_rejected'): ?>
                            <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Ditolak</span>
                            <?php endif; ?>
                            
                            <?php else: ?>
                            <span class="text-muted">-</span>
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

<!-- MODALS -->
<?php foreach($loans as $l): ?>
  <!-- Upload Loan Document Modal -->
  <?php if ($l['stage'] === 'awaiting_document'): ?>
  <div class="modal fade" id="uploadModal<?= $l['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-upload me-2"></i>Upload Dokumen Peminjaman #<?= $l['id'] ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
          <div class="modal-body">
            <input type="hidden" name="action" value="upload_document">
            <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
            
            <div class="alert alert-info d-flex align-items-center">
              <i class="bi bi-info-circle me-2 fs-5"></i>
              <div>
                <strong>Template Dokumen:</strong><br>
                <a href="/public/assets/templates/BA STM ULTG GORONTALO.xlsx" class="alert-link" download>
                  <i class="bi bi-download me-1"></i>Download Template Excel
                </a>
              </div>
            </div>
            
            <div class="mb-3">
              <label class="form-label fw-semibold">Pilih File Excel (.xlsx, .xls)</label>
              <input type="file" name="document" accept=".xlsx,.xls" class="form-control" required>
              <small class="text-muted">Maksimal 5 MB</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-upload me-1"></i>Upload
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
  
  <!-- Request Return Modal -->
  <?php if ($l['stage'] === 'approved' && ($l['return_stage'] ?? 'none') === 'none'): ?>
  <div class="modal fade" id="returnModal<?= $l['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-box-arrow-left me-2"></i>Ajukan Pengembalian #<?= $l['id'] ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST">
          <div class="modal-body">
            <input type="hidden" name="action" value="request_return">
            <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
            
            <div class="mb-3">
              <label class="form-label fw-semibold">Barang</label>
              <div class="d-flex align-items-center p-3 rounded" style="background: var(--bg-tertiary);">
                <?php if($l['inventory_image']): ?>
                <img src="/public/assets/uploads/<?= htmlspecialchars($l['inventory_image']) ?>" 
                     class="rounded me-3" style="width: 60px; height: 60px; object-fit: cover;">
                <?php else: ?>
                <div class="rounded me-3 d-flex align-items-center justify-content-center" 
                     style="width: 60px; height: 60px; background: var(--bg-secondary);">
                    <i class="bi bi-box-seam text-muted fs-4"></i>
                </div>
                <?php endif; ?>
                <div>
                    <div class="fw-semibold"><?= htmlspecialchars($l['inventory_name']) ?></div>
                    <div class="text-muted"><?= $l['quantity'] ?> unit</div>
                </div>
              </div>
            </div>
            
            <div class="mb-3">
              <label class="form-label fw-semibold">Catatan Pengembalian (opsional)</label>
              <textarea name="return_note" class="form-control" rows="3" 
                        placeholder="Tambahkan catatan jika diperlukan..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-warning">
              <i class="bi bi-send me-1"></i>Ajukan Pengembalian
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
  
  <!-- Upload Return Document Modal -->
  <?php if ($l['stage'] === 'approved' && ($l['return_stage'] ?? 'none') === 'awaiting_return_doc'): ?>
  <div class="modal fade" id="returnDocModal<?= $l['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-upload me-2"></i>Upload Dokumen Pengembalian #<?= $l['id'] ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
          <div class="modal-body">
            <input type="hidden" name="action" value="upload_return_document">
            <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
            
            <div class="alert alert-info d-flex align-items-center">
              <i class="bi bi-info-circle me-2 fs-5"></i>
              <div>
                <strong>Template Pengembalian:</strong><br>
                <a href="/public/assets/templates/PENGEMBALIAN.xlsx" class="alert-link" download>
                  <i class="bi bi-download me-1"></i>Download Template Excel
                </a>
              </div>
            </div>
            
            <div class="mb-3">
              <label class="form-label fw-semibold">Pilih File Excel (.xlsx, .xls)</label>
              <input type="file" name="return_document" accept=".xlsx,.xls" class="form-control" required>
              <small class="text-muted">Maksimal 5 MB</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-upload me-1"></i>Upload
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
  
  <!-- Rejection Reason Modal -->
  <?php if ($l['stage'] === 'rejected' && !empty($l['rejection_note'])): ?>
  <div class="modal fade" id="rejectionModal<?= $l['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header border-0">
          <h5 class="modal-title">
            <i class="bi bi-x-circle text-danger me-2"></i>Peminjaman Ditolak
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex align-items-center mb-3 p-3 rounded" style="background: var(--bg-main);">
            <?php if($l['inventory_image']): ?>
            <img src="/public/assets/uploads/<?= htmlspecialchars($l['inventory_image']) ?>" 
                 class="rounded me-3" style="width: 50px; height: 50px; object-fit: cover;">
            <?php else: ?>
            <div class="rounded me-3 d-flex align-items-center justify-content-center" 
                 style="width: 50px; height: 50px; background: var(--bg-tertiary);">
                <i class="bi bi-box-seam text-muted"></i>
            </div>
            <?php endif; ?>
            <div>
                <div class="fw-semibold"><?= htmlspecialchars($l['inventory_name']) ?></div>
                <small class="text-muted"><?= (int)$l['quantity'] ?> unit</small>
            </div>
          </div>
          
          <div class="rejection-reason-box">
            <div class="reason-label"><i class="bi bi-exclamation-triangle me-1"></i>Alasan Penolakan</div>
            <p class="reason-text"><?= nl2br(htmlspecialchars($l['rejection_note'])) ?></p>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
          <a href="/index.php?page=user_request_loan" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Ajukan Baru
          </a>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Filter tabs functionality
    const filterButtons = document.querySelectorAll('[data-filter]');
    const loanRows = document.querySelectorAll('.loan-row');
    
    filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const filter = this.dataset.filter;
            
            // Update active state
            filterButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            // Filter rows
            loanRows.forEach(row => {
                if (filter === 'all' || row.classList.contains(filter)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    });
});
</script>

<style>
.empty-state {
    padding: 3rem;
}
.empty-state i {
    font-size: 4rem;
    color: var(--text-muted);
    margin-bottom: 1rem;
}
.empty-state h5 {
    color: var(--text-primary);
    margin-bottom: 0.5rem;
}

.nav-pills .nav-link {
    color: var(--text-secondary);
    border-radius: 8px;
    padding: 0.5rem 1rem;
    margin-right: 0.5rem;
}
.nav-pills .nav-link:hover {
    background: var(--bg-tertiary);
}
.nav-pills .nav-link.active {
    background: var(--primary);
    color: white;
}

.loan-row td {
    vertical-align: middle;
}
</style>
