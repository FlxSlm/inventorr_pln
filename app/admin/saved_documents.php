<?php
// app/admin/saved_documents.php
// Halaman untuk melihat dan mengelola dokumen yang sudah digenerate dan disimpan

$pdo = require __DIR__ . '/../config/database.php';

// Handle delete action - Must be before any output
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM generated_documents WHERE id = ?");
    $stmt->execute([$deleteId]);
    header('Location: /index.php?page=admin_saved_documents&deleted=1');
    exit;
}

// Handle download action - Must be before any output
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $downloadId = (int)$_GET['download'];
    $stmt = $pdo->prepare("SELECT * FROM generated_documents WHERE id = ?");
    $stmt->execute([$downloadId]);
    $doc = $stmt->fetch();
    
    if ($doc && $doc['file_path'] && file_exists($doc['file_path'])) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($doc['file_path']) . '"');
        readfile($doc['file_path']);
        exit;
    }
}

// Filter parameters
$filterType = $_GET['type'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$searchQuery = $_GET['search'] ?? '';

// Build query with filters - Fixed collation issue by using COLLATE
$sql = "SELECT gd.*, 
        CASE 
            WHEN gd.document_type = 'loan' THEN (SELECT u.name FROM users u JOIN loans l ON l.user_id = u.id WHERE (l.group_id COLLATE utf8mb4_unicode_ci = gd.reference_id COLLATE utf8mb4_unicode_ci OR CONCAT('single_', l.id) COLLATE utf8mb4_unicode_ci = gd.reference_id COLLATE utf8mb4_unicode_ci) LIMIT 1)
            WHEN gd.document_type = 'request' THEN (SELECT u.name FROM users u JOIN requests r ON r.user_id = u.id WHERE (r.group_id COLLATE utf8mb4_unicode_ci = gd.reference_id COLLATE utf8mb4_unicode_ci OR CONCAT('single_', r.id) COLLATE utf8mb4_unicode_ci = gd.reference_id COLLATE utf8mb4_unicode_ci) LIMIT 1)
            WHEN gd.document_type = 'return' THEN (SELECT u.name FROM users u JOIN loans l ON l.user_id = u.id WHERE (l.group_id COLLATE utf8mb4_unicode_ci = gd.reference_id COLLATE utf8mb4_unicode_ci OR CONCAT('single_', l.id) COLLATE utf8mb4_unicode_ci = gd.reference_id COLLATE utf8mb4_unicode_ci) LIMIT 1)
        END as user_name
        FROM generated_documents gd
        WHERE gd.status IN ('saved', 'uploaded', 'sent')";

$params = [];

if ($filterType) {
    $sql .= " AND gd.document_type = ?";
    $params[] = $filterType;
}

if ($filterStatus) {
    $sql .= " AND gd.status = ?";
    $params[] = $filterStatus;
}

if ($searchQuery) {
    $sql .= " AND (gd.document_number LIKE ? OR gd.reference_id LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

$sql .= " ORDER BY gd.generated_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

// Document type labels
$typeLabels = [
    'loan' => ['text' => 'Peminjaman', 'class' => 'bg-info'],
    'request' => ['text' => 'Permintaan', 'class' => 'bg-primary'],
    'return' => ['text' => 'Pengembalian', 'class' => 'bg-warning']
];

// Status labels - simplified to just "Tersimpan" and "Terkirim"
$statusLabels = [
    'saved' => ['text' => 'Tersimpan', 'class' => 'bg-secondary'],
    'uploaded' => ['text' => 'Terkirim', 'class' => 'bg-success'],
    'sent' => ['text' => 'Terkirim', 'class' => 'bg-success']
];
?>

<div class="container-fluid">
    <div class="page-header">
        <h4 class="mb-0"><i class="bi bi-file-earmark-check me-2"></i>Dokumen Tersimpan</h4>
        <p class="text-muted mb-0">Kelola dokumen Berita Acara yang sudah digenerate dan disimpan</p>
    </div>

    <?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i>Dokumen berhasil dihapus.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="">
                <input type="hidden" name="page" value="admin_saved_documents">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Tipe Dokumen</label>
                        <select class="form-select" name="type">
                            <option value="">Semua Tipe</option>
                            <option value="loan" <?= $filterType === 'loan' ? 'selected' : '' ?>>Peminjaman</option>
                            <option value="request" <?= $filterType === 'request' ? 'selected' : '' ?>>Permintaan</option>
                            <option value="return" <?= $filterType === 'return' ? 'selected' : '' ?>>Pengembalian</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">Semua Status</option>
                            <option value="saved" <?= $filterStatus === 'saved' ? 'selected' : '' ?>>Tersimpan</option>
                            <option value="sent" <?= ($filterStatus === 'sent' || $filterStatus === 'uploaded') ? 'selected' : '' ?>>Terkirim</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Pencarian</label>
                        <input type="text" class="form-control" name="search" placeholder="Cari nomor surat..." value="<?= htmlspecialchars($searchQuery) ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i>Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Documents Table -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($documents)): ?>
            <div class="text-center py-5">
                <i class="bi bi-file-earmark-x text-muted" style="font-size: 4rem;"></i>
                <h5 class="mt-3 text-muted">Belum ada dokumen tersimpan</h5>
                <p class="text-muted">Dokumen yang sudah digenerate dan disimpan akan muncul di sini.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th style="width: 50px;">No</th>
                            <th>Nomor Surat</th>
                            <th>Tipe</th>
                            <th>Nama Karyawan</th>
                            <th>Status</th>
                            <th>Tanggal Generate</th>
                            <th style="width: 200px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $idx => $doc): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td>
                                <strong><?= htmlspecialchars($doc['document_number']) ?></strong>
                            </td>
                            <td>
                                <span class="badge <?= $typeLabels[$doc['document_type']]['class'] ?? 'bg-secondary' ?>">
                                    <?= $typeLabels[$doc['document_type']]['text'] ?? ucfirst($doc['document_type']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($doc['user_name'] ?? 'N/A') ?></td>
                            <td>
                                <span class="badge <?= $statusLabels[$doc['status']]['class'] ?? 'bg-secondary' ?>">
                                    <?= $statusLabels[$doc['status']]['text'] ?? ucfirst($doc['status']) ?>
                                </span>
                            </td>
                            <td><?= date('d M Y H:i', strtotime($doc['generated_at'])) ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="/index.php?page=admin_generate_document&type=<?= $doc['document_type'] ?>&ref=<?= urlencode($doc['reference_id']) ?>" 
                                       class="btn btn-outline-primary" title="Lihat/Edit">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($doc['file_path'] && file_exists($doc['file_path'])): ?>
                                    <a href="/index.php?page=admin_saved_documents&download=<?= $doc['id'] ?>" 
                                       class="btn btn-outline-success" title="Download BAST">
                                        <i class="bi bi-download"></i>
                                    </a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-danger" title="Hapus"
                                            onclick="confirmDelete(<?= $doc['id'] ?>, '<?= htmlspecialchars(addslashes($doc['document_number']), ENT_QUOTES) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Konfirmasi Hapus</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Apakah Anda yakin ingin menghapus dokumen ini?</p>
                <p class="mb-0"><strong>Nomor: <span id="deleteDocNumber"></span></strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <a href="#" id="deleteLink" class="btn btn-danger">
                    <i class="bi bi-trash me-1"></i>Hapus
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, docNumber) {
    document.getElementById('deleteDocNumber').textContent = docNumber;
    document.getElementById('deleteLink').href = '/index.php?page=admin_saved_documents&delete=' + id;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
