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
    echo '<div class="alert alert-danger">Data peminjaman tidak ditemukan atau tidak dalam tahap upload dokumen pengembalian.</div>';
    return;
}

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['return_document']) && $_FILES['return_document']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['return_document'];
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($file['type'], $allowedTypes)) {
            $error = 'Tipe file tidak diizinkan. Gunakan PDF, JPG, atau PNG.';
        } elseif ($file['size'] > $maxSize) {
            $error = 'Ukuran file terlalu besar. Maksimal 5MB.';
        } else {
            // Create uploads directory if not exists
            $uploadDir = __DIR__ . '/../../public/assets/uploads/documents/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
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
    <a href="/index.php?page=user_dashboard" class="btn btn-outline-light me-3">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h2 class="mb-0 text-pln-yellow">
        <i class="bi bi-file-earmark-arrow-up me-2"></i>Upload Dokumen Pengembalian
    </h2>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-2"></i><?= $success ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <div class="text-center mt-4">
        <a href="/index.php?page=user_history" class="btn btn-primary">
            <i class="bi bi-clock-history me-2"></i>Lihat Riwayat
        </a>
        <a href="/index.php?page=user_dashboard" class="btn btn-outline-light ms-2">
            <i class="bi bi-house me-2"></i>Kembali ke Dashboard
        </a>
    </div>
<?php else: ?>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>Informasi Barang
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <td class="text-secondary" style="width: 40%;">Nama Barang</td>
                        <td class="text-white"><?= htmlspecialchars($loan['item_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-secondary">Kode</td>
                        <td class="text-white"><?= htmlspecialchars($loan['item_code']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-secondary">Jumlah</td>
                        <td class="text-white"><?= $loan['quantity'] ?> unit</td>
                    </tr>
                    <tr>
                        <td class="text-secondary">Tgl Pinjam</td>
                        <td class="text-white"><?= date('d M Y', strtotime($loan['loan_date'])) ?></td>
                    </tr>
                    <tr>
                        <td class="text-secondary">Tgl Kembali</td>
                        <td class="text-white"><?= date('d M Y', strtotime($loan['return_date'])) ?></td>
                    </tr>
                    <tr>
                        <td class="text-secondary">Status Pengembalian</td>
                        <td>
                            <span class="badge bg-info">Menunggu Dokumen</span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-upload me-2"></i>Upload Dokumen
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
                    </div>
                <?php endif; ?>
                
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle me-2"></i>
                    Upload dokumen bukti pengembalian barang seperti:
                    <ul class="mb-0 mt-2">
                        <li>Foto barang yang dikembalikan</li>
                        <li>Berita acara serah terima</li>
                        <li>Bukti kondisi barang</li>
                    </ul>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label text-white">Pilih File Dokumen</label>
                        <input type="file" name="return_document" class="form-control bg-dark text-white border-secondary" 
                               accept=".pdf,.jpg,.jpeg,.png" required>
                        <small class="text-secondary">Format: PDF, JPG, PNG. Maksimal 5MB</small>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-info">
                            <i class="bi bi-cloud-upload me-2"></i>Upload Dokumen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>
