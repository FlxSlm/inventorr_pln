<?php
// app/admin/upload_generated_document.php
// Handle upload of final document and send to employee

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=admin_dashboard');
    exit;
}

$documentType = $_POST['document_type'] ?? '';
$referenceId = $_POST['reference_id'] ?? '';
$documentNumber = $_POST['document_number'] ?? '';
$sendNotification = isset($_POST['send_notification']);

$errors = [];
$success = '';

try {
    if (empty($documentType) || empty($referenceId)) {
        throw new Exception('Data tidak lengkap.');
    }
    
    if (!isset($_FILES['final_document']) || $_FILES['final_document']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Gagal upload file.');
    }
    
    $file = $_FILES['final_document'];
    $allowedExt = ['pdf', 'xlsx', 'xls', 'doc', 'docx'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowedExt)) {
        throw new Exception('Format file tidak valid. Hanya PDF, Excel, Word yang diperbolehkan.');
    }
    
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('Ukuran file maksimal 10MB.');
    }
    
    // Create upload directory
    $uploadDir = __DIR__ . '/../../public/assets/uploads/documents/bast/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate filename
    $safeRef = preg_replace('/[^a-zA-Z0-9_-]/', '', $referenceId);
    $filename = 'bast_' . $documentType . '_' . $safeRef . '_' . time() . '.' . $ext;
    $targetPath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Gagal menyimpan file.');
    }
    
    $relativePath = 'uploads/documents/bast/' . $filename;
    
    $pdo->beginTransaction();
    
    // Update generated_documents table
    $stmt = $pdo->prepare("UPDATE generated_documents SET file_path = ?, status = 'uploaded', uploaded_at = NOW() WHERE document_type = ? AND reference_id = ?");
    $stmt->execute([$relativePath, $documentType, $referenceId]);
    
    // Also update the respective transaction table
    if ($documentType === 'loan') {
        if (strpos($referenceId, 'single_') === 0) {
            $loanId = (int)str_replace('single_', '', $referenceId);
            $stmt = $pdo->prepare("UPDATE loans SET admin_document_path = ? WHERE id = ?");
            $stmt->execute([$relativePath, $loanId]);
            
            // Get user ID for notification
            $stmt = $pdo->prepare("SELECT user_id FROM loans WHERE id = ?");
            $stmt->execute([$loanId]);
        } else {
            $stmt = $pdo->prepare("UPDATE loans SET admin_document_path = ? WHERE group_id = ?");
            $stmt->execute([$relativePath, $referenceId]);
            
            // Get user ID for notification
            $stmt = $pdo->prepare("SELECT user_id FROM loans WHERE group_id = ? LIMIT 1");
            $stmt->execute([$referenceId]);
        }
        $userId = $stmt->fetchColumn();
        
    } elseif ($documentType === 'return') {
        if (strpos($referenceId, 'single_') === 0) {
            $loanId = (int)str_replace('single_', '', $referenceId);
            $stmt = $pdo->prepare("UPDATE loans SET return_admin_document_path = ? WHERE id = ?");
            $stmt->execute([$relativePath, $loanId]);
            
            $stmt = $pdo->prepare("SELECT user_id FROM loans WHERE id = ?");
            $stmt->execute([$loanId]);
        } else {
            $stmt = $pdo->prepare("UPDATE loans SET return_admin_document_path = ? WHERE group_id = ?");
            $stmt->execute([$relativePath, $referenceId]);
            
            $stmt = $pdo->prepare("SELECT user_id FROM loans WHERE group_id = ? LIMIT 1");
            $stmt->execute([$referenceId]);
        }
        $userId = $stmt->fetchColumn();
        
    } elseif ($documentType === 'request') {
        if (strpos($referenceId, 'single_') === 0) {
            $requestId = (int)str_replace('single_', '', $referenceId);
            $stmt = $pdo->prepare("UPDATE requests SET admin_document_path = ? WHERE id = ?");
            $stmt->execute([$relativePath, $requestId]);
            
            $stmt = $pdo->prepare("SELECT user_id FROM requests WHERE id = ?");
            $stmt->execute([$requestId]);
        } else {
            $stmt = $pdo->prepare("UPDATE requests SET admin_document_path = ? WHERE group_id = ?");
            $stmt->execute([$relativePath, $referenceId]);
            
            $stmt = $pdo->prepare("SELECT user_id FROM requests WHERE group_id = ? LIMIT 1");
            $stmt->execute([$referenceId]);
        }
        $userId = $stmt->fetchColumn();
    }
    
    // Send notification if requested
    if ($sendNotification && !empty($userId)) {
        $typeLabels = [
            'loan' => 'Peminjaman',
            'return' => 'Pengembalian',
            'request' => 'Permintaan'
        ];
        $typeLabel = $typeLabels[$documentType] ?? 'Transaksi';
        
        $notifTitle = 'Dokumen BAST ' . $typeLabel . ' Tersedia';
        $notifMessage = 'Dokumen Berita Acara Serah Terima untuk ' . strtolower($typeLabel) . ' Anda telah tersedia. Silakan download dokumen melalui halaman riwayat.';
        
        $notifType = $documentType === 'loan' ? 'loan_approved' : ($documentType === 'return' ? 'return_approved' : 'general');
        
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $notifType, $notifTitle, $notifMessage, $referenceId, $documentType]);
        
        // Update document status to 'sent'
        $stmt = $pdo->prepare("UPDATE generated_documents SET status = 'sent', sent_at = NOW() WHERE document_type = ? AND reference_id = ?");
        $stmt->execute([$documentType, $referenceId]);
    }
    
    $pdo->commit();
    
    // Redirect back with success message
    $redirectPage = [
        'loan' => 'admin_loans',
        'return' => 'admin_returns',
        'request' => 'admin_requests'
    ];
    
    header('Location: /index.php?page=' . ($redirectPage[$documentType] ?? 'admin_dashboard') . '&msg=' . urlencode('Dokumen berhasil diupload dan dikirim ke karyawan.'));
    exit;
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Redirect back with error
    header('Location: /index.php?page=admin_generate_document&type=' . urlencode($documentType) . '&ref=' . urlencode($referenceId) . '&error=' . urlencode($e->getMessage()));
    exit;
}
