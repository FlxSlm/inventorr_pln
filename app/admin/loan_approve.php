<?php
// app/admin/loan_approve.php
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}
$pdo = require __DIR__ . '/../config/database.php';

$loan_id = (int)($_POST['loan_id'] ?? 0);
if (!$loan_id) {
    header('Location: /index.php?page=admin_loans');
    exit;
}

try {
    // Get loan details for notification
    $stmt = $pdo->prepare('SELECT l.*, i.name AS inventory_name FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.id = ?');
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch();
    
    if (!$loan) {
        header('Location: /index.php?page=admin_loans&msg=' . urlencode('Loan tidak ditemukan'));
        exit;
    }
    
    // mark loan as awaiting document (employee must upload filled template)
    $stmt = $pdo->prepare('UPDATE loans SET stage = ?, note = CONCAT(IFNULL(note,""), "\n[admin] initial approved at ", NOW()) WHERE id = ?');
    $stmt->execute(['awaiting_document', $loan_id]);
    
    // Create notification for user
    $notifTitle = 'Peminjaman Disetujui - Upload Dokumen';
    $notifMessage = 'Peminjaman Anda untuk "' . $loan['inventory_name'] . '" telah disetujui. Silakan upload dokumen BAST untuk melanjutkan proses.';
    
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type)
        VALUES (?, 'document_requested', ?, ?, ?, 'loan')
    ");
    $stmt->execute([$loan['user_id'], $notifTitle, $notifMessage, $loan_id]);

    header('Location: /index.php?page=admin_loans&msg=' . urlencode('Disetujui — menunggu dokumen dari karyawan'));
    exit;
} catch (Exception $e) {
    header('Location: /index.php?page=admin_loans&msg=' . urlencode('Error: ' . $e->getMessage()));
    exit;
}
