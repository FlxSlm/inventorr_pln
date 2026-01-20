<?php
// app/user/history.php
// Shows loan history for the logged-in user with upload, validation, and return functionality

if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$userId = (int)$_SESSION['user']['id'];
$isAdmin = $_SESSION['user']['role'] === 'admin';

$errors = [];
$success = '';
$msg = $_GET['msg'] ?? '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $loan_id = (int)($_POST['loan_id'] ?? 0);
    
    // === UPLOAD DOCUMENT FOR LOAN ===
    if ($action === 'upload_document' && $loan_id) {
        $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = ? AND user_id = ?');
        $stmt->execute([$loan_id, $userId]);
        $loan = $stmt->fetch();
        
        if (!$loan) {
            $errors[] = 'Peminjaman tidak ditemukan.';
        } elseif ($loan['stage'] !== 'awaiting_document') {
            $errors[] = 'Peminjaman ini tidak menunggu dokumen.';
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
    
    // === ADMIN FINAL APPROVE LOAN ===
    elseif ($action === 'final_approve' && $isAdmin && $loan_id) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = ? FOR UPDATE');
            $stmt->execute([$loan_id]);
            $loan = $stmt->fetch();
            
            if (!$loan) throw new Exception('Peminjaman tidak ditemukan');
            if ($loan['stage'] !== 'submitted') throw new Exception('Peminjaman tidak dalam tahap submitted');
            
            $stmt = $pdo->prepare('SELECT * FROM inventories WHERE id = ? FOR UPDATE');
            $stmt->execute([$loan['inventory_id']]);
            $inv = $stmt->fetch();
            
            if (!$inv) throw new Exception('Inventaris tidak ditemukan');
            if ($inv['stock_available'] < $loan['quantity']) {
                throw new Exception('Stok tidak mencukupi');
            }
            
            $stmt = $pdo->prepare('UPDATE inventories SET stock_available = stock_available - ? WHERE id = ?');
            $stmt->execute([$loan['quantity'], $inv['id']]);
            
            $stmt = $pdo->prepare('UPDATE loans SET stage = ?, status = ?, approved_at = NOW(), return_stage = ? WHERE id = ?');
            $stmt->execute(['approved', 'approved', 'none', $loan_id]);
            
            $pdo->commit();
            $success = 'Peminjaman berhasil disetujui final.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
    
    // === ADMIN FINAL REJECT LOAN ===
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
    
    // === ADMIN FINAL REJECT RETURN ===
    elseif ($action === 'final_reject_return' && $isAdmin && $loan_id) {
        $stmt = $pdo->prepare('UPDATE loans SET return_stage = ?, return_note = CONCAT(IFNULL(return_note,""), "\n[admin] ditolak final pada ", NOW()) WHERE id = ?');
        $stmt->execute(['return_rejected', $loan_id]);
        $success = 'Pengembalian ditolak.';
    }
}

// Fetch user's loans
$stmt = $pdo->prepare("
  SELECT l.*, i.name AS inventory_name, i.code AS inventory_code
  FROM loans l
  JOIN inventories i ON i.id = l.inventory_id
  WHERE l.user_id = ?
  ORDER BY l.requested_at DESC
");
$stmt->execute([$userId]);
$loans = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4><i class="bi bi-clock-history me-2"></i>Riwayat Peminjaman Saya</h4>
  <a class="btn btn-outline-light" href="/index.php">Kembali</a>
</div>

<?php if ($msg): ?>
  <div class="alert alert-info alert-dismissible fade show">
    <?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php foreach($errors as $e): ?>
  <div class="alert alert-danger alert-dismissible fade show">
    <?= htmlspecialchars($e) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endforeach; ?>

<?php if($success): ?>
  <div class="alert alert-success alert-dismissible fade show">
    <?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>#</th>
            <th>Barang</th>
            <th>Qty</th>
            <th>Tanggal</th>
            <th>Status Peminjaman</th>
            <th>Status Pengembalian</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($loans)): ?>
            <tr><td colspan="7" class="text-center py-5 text-muted">
              <i class="bi bi-inbox d-block" style="font-size: 3rem;"></i>
              Anda belum memiliki riwayat peminjaman.
            </td></tr>
          <?php else: ?>
            <?php foreach($loans as $l): 
              $stageLabels = [
                'pending' => ['Menunggu Validasi 1', 'warning'],
                'awaiting_document' => ['Menunggu Dokumen', 'info'],
                'submitted' => ['Menunggu Validasi 2', 'primary'],
                'approved' => ['Disetujui', 'success'],
                'rejected' => ['Ditolak', 'danger']
              ];
              $returnStageLabels = [
                'none' => ['Belum Dikembalikan', 'secondary'],
                'pending_return' => ['Menunggu Validasi 1', 'warning'],
                'awaiting_return_doc' => ['Menunggu Dokumen', 'info'],
                'return_submitted' => ['Menunggu Validasi 2', 'primary'],
                'return_approved' => ['Dikembalikan', 'success'],
                'return_rejected' => ['Ditolak', 'danger']
              ];
              $stageInfo = $stageLabels[$l['stage']] ?? [$l['stage'], 'secondary'];
              $returnInfo = $returnStageLabels[$l['return_stage'] ?? 'none'] ?? ['N/A', 'secondary'];
            ?>
              <tr>
                <td><span class="badge bg-secondary">#<?= $l['id'] ?></span></td>
                <td>
                  <strong><?= htmlspecialchars($l['inventory_name']) ?></strong>
                  <div class="small text-muted"><?= htmlspecialchars($l['inventory_code'] ?? '') ?></div>
                </td>
                <td><span class="fw-bold text-pln-yellow"><?= (int)$l['quantity'] ?></span></td>
                <td>
                  <div><?= date('d M Y', strtotime($l['requested_at'])) ?></div>
                  <small class="text-muted"><?= date('H:i', strtotime($l['requested_at'])) ?></small>
                </td>
                <td>
                  <span class="badge bg-<?= $stageInfo[1] ?>"><?= $stageInfo[0] ?></span>
                  <?php if ($l['note']): ?>
                    <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" title="<?= htmlspecialchars($l['note']) ?>"></i>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($l['stage'] === 'approved'): ?>
                    <span class="badge bg-<?= $returnInfo[1] ?>"><?= $returnInfo[0] ?></span>
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
                        <a class="btn btn-info" href="/public/<?= htmlspecialchars($l['document_path']) ?>" target="_blank">
                          <i class="bi bi-download"></i>
                        </a>
                      <?php endif; ?>
                      <form method="POST" class="d-inline" onsubmit="return confirm('Setujui peminjaman ini?');">
                        <input type="hidden" name="action" value="final_approve">
                        <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                        <button class="btn btn-success"><i class="bi bi-check-lg"></i></button>
                      </form>
                      <form method="POST" class="d-inline" onsubmit="return confirm('Tolak peminjaman ini?');">
                        <input type="hidden" name="action" value="final_reject">
                        <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                        <button class="btn btn-danger"><i class="bi bi-x-lg"></i></button>
                      </form>
                    </div>
                    
                  <?php elseif ($l['stage'] === 'submitted'): ?>
                    <span class="badge bg-info">Menunggu Review</span>
                    
                  <?php 
                  // === RETURN ACTIONS ===
                  elseif ($l['stage'] === 'approved'): 
                    $rs = $l['return_stage'] ?? 'none';
                    
                    // User can request return
                    if ($rs === 'none'): ?>
                      <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#returnModal<?= $l['id'] ?>">
                        <i class="bi bi-box-arrow-left me-1"></i>Ajukan Pengembalian
                      </button>
                      
                    <?php // Admin approve/reject return stage 1
                    elseif ($rs === 'pending_return' && $isAdmin): ?>
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
                      <span class="badge bg-warning text-dark">Menunggu Validasi</span>
                      
                    <?php // User upload return document
                    elseif ($rs === 'awaiting_return_doc'): ?>
                      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#returnDocModal<?= $l['id'] ?>">
                        <i class="bi bi-upload me-1"></i>Upload Dokumen
                      </button>
                      
                    <?php // Admin final approve/reject return
                    elseif ($rs === 'return_submitted' && $isAdmin): ?>
                      <div class="btn-group btn-group-sm">
                        <?php if ($l['return_document_path']): ?>
                          <a class="btn btn-info" href="/public/<?= htmlspecialchars($l['return_document_path']) ?>" target="_blank">
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
                      <span class="badge bg-info">Menunggu Review</span>
                      
                    <?php elseif ($rs === 'return_approved'): ?>
                      <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Selesai</span>
                      
                    <?php elseif ($rs === 'return_rejected'): ?>
                      <span class="badge bg-danger">Pengembalian Ditolak</span>
                    <?php endif; ?>
                    
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
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
          <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Upload Dokumen Peminjaman #<?= $l['id'] ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
          <div class="modal-body">
            <input type="hidden" name="action" value="upload_document">
            <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              <strong>Template:</strong> 
              <a href="/public/assets/templates/BA STM ULTG GORONTALO.xlsx" class="alert-link" download>Download Template Excel</a>
            </div>
            <div class="mb-3">
              <label class="form-label">Pilih File Excel (.xlsx, .xls)</label>
              <input type="file" name="document" accept=".xlsx,.xls" class="form-control" required>
              <small class="text-muted">Maksimal 5 MB</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload</button>
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
          <h5 class="modal-title"><i class="bi bi-box-arrow-left me-2"></i>Ajukan Pengembalian #<?= $l['id'] ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST">
          <div class="modal-body">
            <input type="hidden" name="action" value="request_return">
            <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
            <div class="mb-3">
              <label class="form-label">Barang</label>
              <input type="text" class="form-control" value="<?= htmlspecialchars($l['inventory_name']) ?> (<?= $l['quantity'] ?> unit)" disabled>
            </div>
            <div class="mb-3">
              <label class="form-label">Catatan Pengembalian (opsional)</label>
              <textarea name="return_note" class="form-control" rows="3" placeholder="Tambahkan catatan jika diperlukan..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-warning"><i class="bi bi-send me-1"></i>Ajukan Pengembalian</button>
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
          <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Upload Dokumen Pengembalian #<?= $l['id'] ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
          <div class="modal-body">
            <input type="hidden" name="action" value="upload_return_document">
            <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
            <div class="alert" style="background: rgba(15, 117, 188, 0.2); border: 1px solid #0f75bc;">
              <i class="bi bi-info-circle me-2 text-white"></i>
              <strong class="text-white">Template Pengembalian:</strong> 
              <a href="/public/assets/templates/PENGEMBALIAN.xlsx" style="color: #FDB913; font-weight: bold;" download>Download Template Excel</a>
            </div>
            <div class="mb-3">
              <label class="form-label">Pilih File Excel (.xlsx, .xls)</label>
              <input type="file" name="return_document" accept=".xlsx,.xls" class="form-control" required>
              <small class="text-muted">Maksimal 5 MB</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
<?php endforeach; ?>

<script>
// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>
