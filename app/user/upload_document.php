<?php
// app/user/upload_document.php
// Page where user uploads filled Excel for their loan with stage awaiting_document
if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}
$pdo = require __DIR__ . '/../config/database.php';
$userId = (int)$_SESSION['user']['id'];

$loan_id = (int)($_GET['loan_id'] ?? 0);
if (!$loan_id) {
    echo "<div class='alert alert-danger' style='border-radius: var(--radius);'><i class='bi bi-exclamation-triangle me-2'></i>Loan ID tidak ditemukan.</div>";
    exit;
}

// fetch loan and check ownership and stage
$stmt = $pdo->prepare('SELECT l.*, i.name as inventory_name, i.code as inventory_code FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.id = ? AND l.user_id = ?');
$stmt->execute([$loan_id, $userId]);
$loan = $stmt->fetch();
if (!$loan) {
    echo "<div class='alert alert-danger' style='border-radius: var(--radius);'><i class='bi bi-exclamation-triangle me-2'></i>Peminjaman tidak ditemukan.</div>";
    exit;
}
if ($loan['stage'] !== 'awaiting_document') {
    echo "<div class='alert alert-info' style='border-radius: var(--radius);'><i class='bi bi-info-circle me-2'></i>Peminjaman ini tidak dalam tahap upload dokumen. Status saat ini: " . htmlspecialchars($loan['stage']) . "</div>";
    exit;
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // validate upload
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Silakan pilih file untuk diupload.';
    } else {
        $allowedExt = ['xlsx','xls','pdf'];
        $maxBytes = 5 * 1024 * 1024; // 5 MB

        $file = $_FILES['document'];
        $tmp = $file['tmp_name'];
        $name = $file['name'];
        $size = $file['size'];

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) {
            $errors[] = 'Hanya file Excel (.xlsx, .xls) dan PDF yang diperbolehkan.';
        } elseif ($size > $maxBytes) {
            $errors[] = 'Ukuran file terlalu besar (maksimal 5 MB).';
        } else {
            // ensure uploads folder exists
            $destDir = __DIR__ . '/../../public/assets/uploads/documents/';
            if (!is_dir($destDir)) mkdir($destDir, 0775, true);

            // create safe name
            $safe = 'loan_'.$loan_id.'_user_'.$userId.'_'.time().'.'.$ext;
            $dest = $destDir . $safe;

            if (move_uploaded_file($tmp, $dest)) {
                // store relative path (from public)
                $relpath = 'assets/uploads/documents/' . $safe;
                $stmt = $pdo->prepare('UPDATE loans SET document_path = ?, document_submitted_at = NOW(), stage = ? WHERE id = ?');
                $stmt->execute([$relpath, 'submitted', $loan_id]);

                $success = 'Dokumen berhasil diupload! Menunggu verifikasi admin.';
                // Redirect
                echo '<script>window.location.href = "/index.php?page=history&msg=' . urlencode($success) . '";</script>';
                exit;
            } else {
                $errors[] = 'Gagal mengupload file. Silakan coba lagi.';
            }
        }
    }
}
?>

<div class="d-flex align-items-center mb-4">
    <a href="/index.php?page=history" class="btn btn-outline-secondary me-3">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h2 class="mb-0" style="color: var(--text-dark);">
        <i class="bi bi-file-earmark-arrow-up me-2" style="color: var(--primary-light);"></i>Upload Dokumen Peminjaman
    </h2>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" style="border-radius: var(--radius);">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php foreach($errors as $e): ?>
<div class="alert alert-danger alert-dismissible fade show" style="border-radius: var(--radius);">
    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<div class="row g-4">
    <div class="col-md-6">
        <div class="modern-card">
            <div class="card-header" style="padding: 16px 20px; border-bottom: 1px solid var(--border-color);">
                <h5 class="card-title mb-0">
                    <i class="bi bi-info-circle me-2" style="color: var(--primary-light);"></i>Informasi Peminjaman
                </h5>
            </div>
            <div class="card-body" style="padding: 20px;">
                <table class="table table-borderless mb-0">
                    <tr>
                        <td style="color: var(--text-muted); width: 40%;">ID Peminjaman</td>
                        <td style="font-weight: 600; color: var(--text-dark);">#<?= $loan['id'] ?></td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Nama Barang</td>
                        <td style="font-weight: 600; color: var(--text-dark);"><?= htmlspecialchars($loan['inventory_name']) ?></td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Kode</td>
                        <td style="color: var(--text-dark);"><?= htmlspecialchars($loan['inventory_code']) ?></td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Jumlah</td>
                        <td style="color: var(--text-dark);"><?= $loan['quantity'] ?> unit</td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Tanggal Pengajuan</td>
                        <td style="color: var(--text-dark);"><?= date('d M Y H:i', strtotime($loan['requested_at'])) ?></td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Status</td>
                        <td>
                            <span class="status-badge info">Menunggu Dokumen</span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="modern-card">
            <div class="card-header" style="padding: 16px 20px; border-bottom: 1px solid var(--border-color);">
                <h5 class="card-title mb-0">
                    <i class="bi bi-upload me-2" style="color: var(--primary-light);"></i>Upload Dokumen
                </h5>
            </div>
            <div class="card-body" style="padding: 20px;">
                <div class="mb-3" style="background: linear-gradient(135deg, var(--primary-light) 0%, var(--accent) 100%); padding: 16px; border-radius: var(--radius); color: #fff;">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-download me-3" style="font-size: 24px;"></i>
                        <div>
                            <strong>Template:</strong>
                            <a href="/public/assets/templates/BA STM ULTG GORONTALO.xlsx" style="color: #fff; font-weight: bold; text-decoration: underline; margin-left: 8px;" download>
                                <i class="bi bi-file-earmark-excel me-1"></i>Download Template Excel
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3" style="background: var(--bg-main); padding: 16px; border-radius: var(--radius); border-left: 4px solid var(--primary-light);">
                    <div class="d-flex">
                        <i class="bi bi-info-circle me-2" style="color: var(--primary-light);"></i>
                        <div>
                            <strong style="color: var(--text-dark);">Petunjuk Upload:</strong>
                            <ul class="mb-0 mt-2" style="color: var(--text-muted); font-size: 14px;">
                                <li>Download template di atas dan isi dengan lengkap</li>
                                <li>Pastikan semua data sudah benar</li>
                                <li>Format file: Excel (.xlsx, .xls) atau PDF</li>
                                <li>Ukuran maksimal: 5MB</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label" style="color: var(--text-dark); font-weight: 500;">Pilih File Dokumen</label>
                        <input type="file" name="document" class="form-control" accept=".xlsx,.xls,.pdf" required>
                        <small style="color: var(--text-muted);">Format: Excel (.xlsx, .xls) atau PDF. Maksimal 5MB</small>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-cloud-upload me-2"></i>Upload & Submit
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
