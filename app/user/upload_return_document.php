<?php
// app/user/upload_return_document.php
$pdo = require __DIR__ . '/../config/database.php';
$userId = $_SESSION['user']['id'] ?? 0;

$loanId = $_GET['loan_id'] ?? 0;

// Get loan info
$stmt = $pdo->prepare('SELECT l.*, i.name as item_name, i.code as item_code 
                       FROM loans l 
                       JOIN inventories i ON i.id = l.inventory_id 
                       WHERE l.id = ? AND l.user_id = ? AND l.return_stage = "awaiting_return_doc"');
$stmt->execute([$loanId, $userId]);
$loan = $stmt->fetch();

if (!$loan) {
    echo '<div class="alert alert-danger" style="border-radius: var(--radius);"><i class="bi bi-exclamation-triangle me-2"></i>Data peminjaman tidak ditemukan atau tidak dalam tahap upload dokumen pengembalian.</div>';
    return;
}

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['return_document']) && $_FILES['return_document']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['return_document'];
        // Only allow Excel and PDF
        $allowedTypes = [
            'application/pdf',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
        $allowedExtensions = ['pdf', 'xls', 'xlsx'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file['type'], $allowedTypes) && !in_array($ext, $allowedExtensions)) {
            $error = 'Tipe file tidak diizinkan. Hanya file Excel (.xlsx, .xls) atau PDF yang diperbolehkan.';
        } elseif ($file['size'] > $maxSize) {
            $error = 'Ukuran file terlalu besar. Maksimal 5MB.';
        } else {
            // Create uploads directory if not exists
            $uploadDir = __DIR__ . '/../../public/assets/uploads/documents/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $filename = 'return_doc_' . $loanId . '_' . time() . '.' . $ext;
            $filepath = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                // Update loan with document path and change stage
                $updateStmt = $pdo->prepare('UPDATE loans SET return_document_path = ?, return_stage = "awaiting_final_return" WHERE id = ?');
                $updateStmt->execute(['documents/' . $filename, $loanId]);
                
                $success = 'Dokumen pengembalian berhasil diupload! Menunggu verifikasi akhir dari admin.';
                
                // Refresh loan data
                $stmt->execute([$loanId, $userId]);
                $loan = $stmt->fetch();
            } else {
                $error = 'Gagal mengupload file. Silakan coba lagi.';
            }
        }
    } else {
        $error = 'Silakan pilih file untuk diupload.';
    }
}
?>

<div class="d-flex align-items-center mb-4">
    <a href="/index.php?page=history" class="btn btn-outline-secondary me-3">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h2 class="mb-0" style="color: var(--text-dark);">
        <i class="bi bi-file-earmark-arrow-up me-2" style="color: var(--primary-light);"></i>Upload Dokumen Pengembalian
    </h2>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" style="border-radius: var(--radius);">
        <i class="bi bi-check-circle me-2"></i><?= $success ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <div class="text-center mt-4">
        <a href="/index.php?page=history" class="btn btn-primary">
            <i class="bi bi-clock-history me-2"></i>Lihat Riwayat
        </a>
        <a href="/index.php" class="btn btn-outline-secondary ms-2">
            <i class="bi bi-house me-2"></i>Kembali ke Dashboard
        </a>
    </div>
<?php else: ?>

<div class="row g-4">
    <div class="col-md-6">
        <div class="modern-card">
            <div class="card-header" style="padding: 16px 20px; border-bottom: 1px solid var(--border-color);">
                <h5 class="card-title mb-0">
                    <i class="bi bi-info-circle me-2" style="color: var(--primary-light);"></i>Informasi Barang
                </h5>
            </div>
            <div class="card-body" style="padding: 20px;">
                <table class="table table-borderless mb-0">
                    <tr>
                        <td style="color: var(--text-muted); width: 40%;">Nama Barang</td>
                        <td style="font-weight: 600; color: var(--text-dark);"><?= htmlspecialchars($loan['item_name']) ?></td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Kode</td>
                        <td style="color: var(--text-dark);"><?= htmlspecialchars($loan['item_code']) ?></td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Jumlah</td>
                        <td style="color: var(--text-dark);"><?= $loan['quantity'] ?> unit</td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Tgl Pinjam</td>
                        <td style="color: var(--text-dark);"><?= $loan['loan_date'] ? date('d M Y', strtotime($loan['loan_date'])) : '-' ?></td>
                    </tr>
                    <tr>
                        <td style="color: var(--text-muted);">Tgl Kembali</td>
                        <td style="color: var(--text-dark);"><?= $loan['return_date'] ? date('d M Y', strtotime($loan['return_date'])) : '-' ?></td>
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
                <?php if ($error): ?>
                    <div class="alert alert-danger" style="border-radius: var(--radius);">
                        <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
                    </div>
                <?php endif; ?>
                
                <div class="mb-3" style="background: linear-gradient(135deg, var(--primary-light) 0%, var(--accent) 100%); padding: 16px; border-radius: var(--radius); color: #fff;">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-download me-3" style="font-size: 24px;"></i>
                        <div>
                            <strong>Template Pengembalian:</strong><br>
                            <a href="/public/assets/templates/PENGEMBALIAN.xlsx" style="color: #fff; font-weight: bold; text-decoration: underline;" download>
                                <i class="bi bi-file-earmark-excel me-1"></i>Download Template Excel
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3" style="background: var(--bg-main); padding: 16px; border-radius: var(--radius); border-left: 4px solid var(--primary-light);">
                    <div class="d-flex">
                        <i class="bi bi-info-circle me-2" style="color: var(--primary-light);"></i>
                        <div>
                            <strong style="color: var(--text-dark);">Upload dokumen bukti pengembalian seperti:</strong>
                            <ul class="mb-0 mt-2" style="color: var(--text-muted);">
                                <li>Berita Acara Serah Terima yang sudah diisi</li>
                                <li>Bukti kondisi barang</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label" style="color: var(--text-dark); font-weight: 500;">Pilih File Dokumen</label>
                        <input type="file" name="return_document" class="form-control" 
                               accept=".xlsx,.xls,.pdf" required>
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
