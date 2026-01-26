<?php
// app/user/request_history.php - User's request history
if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$userId = $_SESSION['user']['id'];
$msg = $_GET['msg'] ?? '';

// Fetch user's requests
$stmt = $pdo->prepare("
    SELECT r.*, i.name as inventory_name, i.code as inventory_code, i.image as inventory_image
    FROM requests r
    JOIN inventories i ON i.id = r.inventory_id
    WHERE r.user_id = ?
    ORDER BY r.requested_at DESC
");
$stmt->execute([$userId]);
$requests = $stmt->fetchAll();

$stageLabels = [
    'pending' => ['Menunggu Validasi', 'warning', 'hourglass-split'],
    'awaiting_document' => ['Upload Dokumen', 'info', 'file-earmark-arrow-up'],
    'submitted' => ['Menunggu Verifikasi', 'primary', 'clock'],
    'approved' => ['Disetujui', 'success', 'check-circle'],
    'rejected' => ['Ditolak', 'danger', 'x-circle']
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">
            <i class="bi bi-clock-history me-2"></i>Riwayat Permintaan
        </h1>
        <p class="text-muted mb-0">Daftar permintaan barang Anda</p>
    </div>
    <a href="/index.php?page=user_request_item" class="btn btn-primary">
        <i class="bi bi-plus-lg me-2"></i>Ajukan Permintaan
    </a>
</div>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="modern-card">
    <div class="card-body p-0">
        <?php if (empty($requests)): ?>
        <div class="text-center py-5">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="bi bi-inbox"></i>
                </div>
                <h5 class="empty-state-title">Belum Ada Permintaan</h5>
                <p class="empty-state-text">Anda belum mengajukan permintaan barang.</p>
                <a href="/index.php?page=user_request_item" class="btn btn-primary mt-3">
                    <i class="bi bi-plus-lg me-1"></i>Ajukan Permintaan
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Barang</th>
                        <th>Jumlah</th>
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
                                <?php if ($r['inventory_image']): ?>
                                <img src="/public/assets/uploads/<?= htmlspecialchars($r['inventory_image']) ?>" 
                                     alt="" class="rounded me-2" style="width: 40px; height: 40px; object-fit: cover;">
                                <?php else: ?>
                                <div class="rounded me-2 d-flex align-items-center justify-content-center" 
                                     style="width: 40px; height: 40px; background: var(--bg-main);">
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
                            <?php if ($stage === 'awaiting_document'): ?>
                            <a href="/index.php?page=upload_request_document&request_id=<?= $r['id'] ?>" class="btn btn-sm btn-primary">
                                <i class="bi bi-upload me-1"></i>Upload
                            </a>
                            <?php elseif ($stage === 'rejected' && $r['rejection_note']): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="tooltip" title="<?= htmlspecialchars($r['rejection_note']) ?>">
                                <i class="bi bi-info-circle"></i> Alasan
                            </button>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (el) { return new bootstrap.Tooltip(el); });
});
</script>
