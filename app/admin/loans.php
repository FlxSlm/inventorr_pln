<?php
// app/admin/loans.php - Modern Style
// Admin page: show list of loans and allow approve/reject
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

$pageTitle = 'Kelola Peminjaman';
$pdo = require __DIR__ . '/../config/database.php';

// Handle messages
$msg = $_GET['msg'] ?? '';

// Fetch loans (latest first)
$stmt = $pdo->query("
    SELECT l.*, u.name AS user_name, u.email AS user_email, i.name AS inventory_name, i.code AS inventory_code, i.stock_available, i.image AS inventory_image
    FROM loans l
    JOIN users u ON u.id = l.user_id
    JOIN inventories i ON i.id = l.inventory_id
    ORDER BY l.requested_at DESC
");
$loans = $stmt->fetchAll();

// Count by status
$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;
foreach ($loans as $l) {
    if ($l['stage'] === 'pending' || $l['stage'] === 'awaiting_document' || $l['stage'] === 'submitted') $pendingCount++;
    elseif ($l['stage'] === 'approved') $approvedCount++;
    elseif ($l['stage'] === 'rejected') $rejectedCount++;
}
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-clipboard-check-fill"></i> Kelola Peminjaman</h3>
        <p>Kelola dan proses permintaan peminjaman barang inventaris</p>
    </div>
    <?php if($msg): ?>
    <div class="alert alert-success mb-0 py-2 px-3" style="border: none;">
        <i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>
</div>

<!-- Stats Summary -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card warning" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Perlu Diproses</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $pendingCount ?></p>
            </div>
            <div class="stat-card-icon warning">
                <i class="bi bi-hourglass-split"></i>
            </div>
        </div>
    </div>
    <div class="stat-card success" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Disetujui</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $approvedCount ?></p>
            </div>
            <div class="stat-card-icon success">
                <i class="bi bi-check-circle"></i>
            </div>
        </div>
    </div>
    <div class="stat-card danger" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Ditolak</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $rejectedCount ?></p>
            </div>
            <div class="stat-card-icon danger">
                <i class="bi bi-x-circle"></i>
            </div>
        </div>
    </div>
    <div class="stat-card" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Total Peminjaman</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= count($loans) ?></p>
            </div>
            <div class="stat-card-icon primary">
                <i class="bi bi-clipboard-data"></i>
            </div>
        </div>
    </div>
</div>

<!-- Loans Table -->
<div class="table-card">
    <div class="card-header" style="padding: 20px 24px; border-bottom: 1px solid var(--border-color);">
        <h3 class="card-title" style="margin: 0;">
            <i class="bi bi-list-ul"></i> Daftar Peminjaman
        </h3>
        <div class="card-actions">
            <div class="table-filters" style="padding: 0;">
                <button class="table-filter-btn active" data-filter="all">Semua</button>
                <button class="table-filter-btn" data-filter="pending">Pending</button>
                <button class="table-filter-btn" data-filter="approved">Disetujui</button>
            </div>
        </div>
    </div>
    
    <?php if (empty($loans)): ?>
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="bi bi-inbox"></i>
            </div>
            <h5 class="empty-state-title">Belum Ada Peminjaman</h5>
            <p class="empty-state-text">Belum ada permintaan peminjaman dari karyawan.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Peminjam</th>
                    <th>Barang</th>
                    <th>Qty</th>
                    <th class="hide-mobile">Catatan</th>
                    <th class="hide-mobile">Tanggal</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="loansTableBody">
                <?php foreach($loans as $l): 
                    $rowStatus = 'all';
                    if ($l['stage'] === 'pending' || $l['stage'] === 'awaiting_document' || $l['stage'] === 'submitted') {
                        $rowStatus = 'pending';
                    } elseif ($l['stage'] === 'approved') {
                        $rowStatus = 'approved';
                    } elseif ($l['stage'] === 'rejected') {
                        $rowStatus = 'rejected';
                    }
                ?>
                <tr data-status="<?= $rowStatus ?>">
                    <td><span class="badge bg-secondary">#<?= $l['id'] ?></span></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="topbar-avatar" style="width: 38px; height: 38px; font-size: 14px;">
                                <?= strtoupper(substr($l['user_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div style="font-weight: 600;"><?= htmlspecialchars($l['user_name']) ?></div>
                                <small style="color: var(--text-muted);"><?= htmlspecialchars($l['user_email']) ?></small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($l['inventory_image']): ?>
                            <img src="/public/assets/uploads/<?= htmlspecialchars($l['inventory_image']) ?>" 
                                 alt="" 
                                 style="width: 42px; height: 42px; object-fit: cover; border-radius: var(--radius);">
                            <?php else: ?>
                            <div style="width: 42px; height: 42px; background: var(--bg-main); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-box-seam" style="color: var(--text-muted);"></i>
                            </div>
                            <?php endif; ?>
                            <div>
                                <div style="font-weight: 500;"><?= htmlspecialchars($l['inventory_name']) ?></div>
                                <small style="color: var(--text-muted);"><?= htmlspecialchars($l['inventory_code']) ?></small>
                            </div>
                        </div>
                    </td>
                    <td><span style="font-weight: 700; color: var(--primary-light);"><?= $l['quantity'] ?></span></td>
                    <td class="hide-mobile">
                        <?php if ($l['note']): ?>
                        <button type="button" class="btn btn-secondary btn-sm" 
                                data-bs-toggle="modal" 
                                data-bs-target="#noteModal<?= $l['id'] ?>">
                            <i class="bi bi-chat-text me-1"></i> Lihat
                        </button>
                        <?php else: ?>
                        <span style="color: var(--text-light);">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="hide-mobile">
                        <div style="font-size: 13px;">
                            <?= date('d M Y', strtotime($l['requested_at'])) ?>
                            <br>
                            <small style="color: var(--text-muted);"><?= date('H:i', strtotime($l['requested_at'])) ?></small>
                        </div>
                    </td>
                    <td>
                        <?php if($l['stage'] === 'pending'): ?>
                            <span class="status-badge warning">Pending</span>
                        <?php elseif($l['stage'] === 'awaiting_document'): ?>
                            <span class="status-badge info">Menunggu Dokumen</span>
                        <?php elseif($l['stage'] === 'submitted'): ?>
                            <span class="status-badge info">Dokumen Submitted</span>
                        <?php elseif($l['stage'] === 'approved'): ?>
                            <span class="status-badge success">Disetujui</span>
                            <?php 
                            $rs = $l['return_stage'] ?? 'none';
                            if ($rs !== 'none' && $rs !== ''): 
                                $returnLabels = [
                                    'pending_return' => ['Pengajuan Kembali', 'warning'],
                                    'awaiting_return_doc' => ['Tunggu Dok Kembali', 'info'],
                                    'return_submitted' => ['Dok Kembali Submitted', 'info'],
                                    'return_approved' => ['Dikembalikan', 'success'],
                                    'return_rejected' => ['Pengembalian Ditolak', 'danger']
                                ];
                                $rInfo = $returnLabels[$rs] ?? ['Unknown', 'secondary'];
                            ?>
                            <br><span class="status-badge <?= $rInfo[1] ?>" style="margin-top: 4px;"><?= $rInfo[0] ?></span>
                            <?php endif; ?>
                        <?php elseif($l['stage'] === 'rejected'): ?>
                            <span class="status-badge danger">Ditolak</span>
                        <?php else: ?>
                            <span class="status-badge secondary"><?= htmlspecialchars($l['stage'] ?? $l['status']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($l['stage'] === 'pending'): ?>
                        <div class="d-flex gap-1">
                            <form method="POST" action="/index.php?page=loan_approve" style="display:inline-block">
                                <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                <button class="btn btn-success btn-sm" onclick="return confirm('Set initial approval dan request dokumen dari karyawan?')">
                                    <i class="bi bi-check-lg me-1"></i> Approve
                                </button>
                            </form>
                            <button type="button" class="btn btn-danger btn-sm" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#rejectModal<?= $l['id'] ?>">
                                <i class="bi bi-x-lg me-1"></i> Reject
                            </button>
                        </div>
                        <?php elseif($l['stage'] === 'awaiting_document'): ?>
                        <small style="color: var(--text-muted);">Menunggu upload user</small>
                        <?php elseif($l['stage'] === 'submitted'): ?>
                        <div class="d-flex gap-1 flex-wrap">
                            <?php if(!empty($l['document_path'])): ?>
                            <a class="btn btn-secondary btn-sm" href="/public/<?= htmlspecialchars($l['document_path']) ?>" target="_blank">
                                <i class="bi bi-download"></i>
                            </a>
                            <?php endif; ?>
                            <form method="POST" action="/index.php?page=final_approve" style="display:inline;">
                                <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                <button class="btn btn-success btn-sm" onclick="return confirm('Final approve dan kurangi stok?')">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </form>
                            <form method="POST" action="/index.php?page=final_reject" style="display:inline;">
                                <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                <button class="btn btn-danger btn-sm" onclick="return confirm('Tolak peminjaman ini?')">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        </div>
                        <?php else: ?>
                        <span style="color: var(--text-light);">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Note Modals -->
<?php foreach($loans as $l): ?>
<?php if ($l['note']): ?>
<div class="modal fade" id="noteModal<?= $l['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-chat-text me-2" style="color: var(--primary-light);"></i>
                    Catatan dari <?= htmlspecialchars($l['user_name']) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 16px;">
                    <small style="color: var(--text-muted);">Barang yang diminta:</small>
                    <div style="font-weight: 600;"><?= htmlspecialchars($l['inventory_name']) ?> (<?= $l['quantity'] ?> unit)</div>
                </div>
                <div style="background: var(--bg-main); padding: 16px; border-radius: var(--radius); border-left: 4px solid var(--primary-light);">
                    <small style="color: var(--text-muted); display: block; margin-bottom: 8px;">Catatan:</small>
                    <p style="margin: 0;"><?= nl2br(htmlspecialchars($l['note'])) ?></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Reject Modal with Note -->
<?php if ($l['stage'] === 'pending'): ?>
<div class="modal fade" id="rejectModal<?= $l['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-x-circle me-2" style="color: var(--danger);"></i>
                    Tolak Peminjaman
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/index.php?page=loan_reject">
                <div class="modal-body">
                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                    
                    <div style="margin-bottom: 16px; padding: 16px; background: var(--bg-main); border-radius: var(--radius);">
                        <div class="d-flex align-items-center gap-3">
                            <?php if ($l['inventory_image']): ?>
                            <img src="/public/assets/uploads/<?= htmlspecialchars($l['inventory_image']) ?>" 
                                 style="width: 50px; height: 50px; object-fit: cover; border-radius: var(--radius);">
                            <?php endif; ?>
                            <div>
                                <strong><?= htmlspecialchars($l['inventory_name']) ?></strong>
                                <br><small style="color: var(--text-muted);">Diminta oleh <?= htmlspecialchars($l['user_name']) ?> • <?= $l['quantity'] ?> unit</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" style="color: var(--text-dark); font-weight: 500;">
                            Alasan Penolakan <span class="text-danger">*</span>
                        </label>
                        <textarea name="rejection_note" class="form-control" rows="4" 
                                  placeholder="Berikan alasan mengapa peminjaman ini ditolak..." required></textarea>
                        <small style="color: var(--text-muted);">Catatan ini akan dikirimkan kepada peminjam.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-lg me-1"></i> Tolak Peminjaman
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<script>
// Filter buttons functionality
document.querySelectorAll('.table-filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.table-filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        const filter = this.dataset.filter;
        const rows = document.querySelectorAll('#loansTableBody tr');
        
        rows.forEach(row => {
            if (filter === 'all' || row.dataset.status === filter) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
});
</script>
