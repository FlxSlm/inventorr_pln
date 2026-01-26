<?php
// app/user/upload_request_document.php
if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$userId = $_SESSION['user']['id'];
$requestId = (int)($_GET['request_id'] ?? 0);

// Get request info
$stmt = $pdo->prepare('SELECT r.*, i.name as item_name, i.code as item_code 
                       FROM requests r 
                       JOIN inventories i ON i.id = r.inventory_id 
                       WHERE r.id = ? AND r.user_id = ? AND r.stage = "awaiting_document"');
$stmt->execute([$requestId, $userId]);
$request = $stmt->fetch();

if (!$request) {
    echo '<div class="alert alert-danger" style="border-radius: var(--radius);"><i class="bi bi-exclamation-triangle me-2"></i>Permintaan tidak ditemukan atau tidak dalam tahap upload dokumen.</div>';
    return;
}

// Get template
$templateStmt = $pdo->query("SELECT * FROM document_templates WHERE template_type = 'request' AND is_active = 1 LIMIT 1");
$template = $templateStmt->fetch();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['document'];
        $allowedTypes = ['application/pdf', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        $allowedExtensions = ['pdf', 'xls', 'xlsx'];
        $maxSize = 5 * 1024 * 1024;
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowedExtensions)) {
            $error = 'Format file tidak diizinkan. Gunakan Excel (.xlsx, .xls) atau PDF.';
        } elseif ($file['size'] > $maxSize) {
            $error = 'Ukuran file terlalu besar. Maksimal 5MB.';
        } else {
            $uploadDir = __DIR__ . '/../../public/assets/uploads/documents/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $filename = 'request_doc_' . $requestId . '_' . time() . '.' . $ext;
            $filepath = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $updateStmt = $pdo->prepare('UPDATE requests SET document_path = ?, document_submitted_at = NOW(), stage = "submitted" WHERE id = ?');
                $updateStmt->execute(['documents/' . $filename, $requestId]);
                
                $success = 'Dokumen berhasil diupload! Menunggu verifikasi admin.';
            } else {
                $error = 'Gagal mengupload file.';
            }
        }
    } else {
        $error = 'Silakan pilih file untuk diupload.';
    }
}
?>

<div class="d-flex align-items-center mb-4">
    <a href="/index.php?page=user_request_history" class="btn btn-outline-secondary me-3">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h2 class="mb-0" style="color: var(--text-dark);">
        <i class="bi bi-file-earmark-arrow-up me-2" style="color: var(--primary-light);"></i>Upload Dokumen Permintaan
    </h2>
</div>

<?php if ($success): ?>
<div class="alert alert-success" style="border-radius: var(--radius);">
    <i class="bi bi-check-circle me-2"></i><?= $success ?>
</div>
<div class="text-center mt-4">
    <a href="/index.php?page=user_request_history" class="btn btn-primary">
        <i class="bi bi-clock-history me-2"></i>Lihat Riwayat
    </a>
</div>
<?php else: ?>

<div class="row g-4">
    <div class="col-md-6">
        <div class="modern-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-info-circle me-2" style="color: var(--primary-light);"></i>Informasi Permintaan
                </h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <td style="color: var(--text-muted); width: 40%;">ID Permintaan</td>
                        <td style="font-weight: 600; color: var(--text-dark);">#<?= $request['id'] ?></td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Nama Barang</td>
                        <td style="font-weight: 600; color: var(--text-dark);"><?= htmlspecialchars($request['item_name']) ?></td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Kode</td>
                        <td style="color: var(--text-dark);"><?= htmlspecialchars($request['item_code']) ?></td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Jumlah</td>
                        <td style="color: var(--text-dark);"><?= $request['quantity'] ?> unit</td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Tgl Pengajuan</td>
                        <td style="color: var(--text-dark);"><?= date('d M Y H:i', strtotime($request['requested_at'])) ?></td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Status</td>
                        <td><span class="status-badge info">Menunggu Dokumen</span></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="modern-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-upload me-2" style="color: var(--primary-light);"></i>Upload Dokumen
                </h5>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                <div class="alert alert-danger" style="border-radius: var(--radius);">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
                </div>
                <?php endif; ?>
                
                <?php if ($template): ?>
                <div class="mb-3" style="background: linear-gradient(135deg, var(--primary-light) 0%, var(--accent) 100%); padding: 16px; border-radius: var(--radius); color: #fff;">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-download me-3" style="font-size: 24px;"></i>
                        <div>
                            <strong>Template Permintaan:</strong><br>
                            <a href="/public/assets/<?= htmlspecialchars($template['file_path']) ?>" style="color: #fff; font-weight: bold; text-decoration: underline;" download>
                                <i class="bi bi-file-earmark-excel me-1"></i>Download Template
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="mb-3" style="background: var(--bg-main); padding: 16px; border-radius: var(--radius); border-left: 4px solid var(--primary-light);">
                    <strong style="color: var(--text-dark);">Petunjuk:</strong>
                    <ul class="mb-0 mt-2" style="color: var(--text-muted); font-size: 14px;">
                        <li>Download template di atas</li>
                        <li>Isi dokumen serah terima</li>
                        <li>Upload kembali dalam format Excel atau PDF</li>
                    </ul>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label" style="color: var(--text-dark); font-weight: 500;">Pilih File Dokumen</label>
                        <input type="file" name="document" class="form-control" accept=".xlsx,.xls,.pdf" required>
                        <small style="color: var(--text-muted);">Format: Excel (.xlsx, .xls) atau PDF. Maksimal 5MB</small>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-cloud-upload me-2"></i>Upload Dokumen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
