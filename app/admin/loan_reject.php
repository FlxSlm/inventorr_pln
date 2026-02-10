<?php
// app/admin/loan_reject.php
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}
$pdo = require __DIR__ . '/../config/database.php';

$loan_id = (int)($_POST['loan_id'] ?? 0);
$rejection_note = trim($_POST['rejection_note'] ?? '');

if ($loan_id) {
    // Get loan details for notification
    $stmt = $pdo->prepare('SELECT l.*, i.name AS inventory_name FROM loans l JOIN inventories i ON i.id = l.inventory_id WHERE l.id = ?');
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch();
    
    if ($loan) {
        // Update loan with rejection note
        $stmt = $pdo->prepare('UPDATE loans SET status = "rejected", stage = "rejected", rejection_note = ?, rejected_by = ? WHERE id = ?');
        $stmt->execute([$rejection_note, $_SESSION['user']['id'], $loan_id]);
        
        // Create notification for user
        $notifTitle = 'Peminjaman Ditolak';
        $notifMessage = 'Peminjaman Anda untuk "' . $loan['inventory_name'] . '" telah ditolak.';
        if ($rejection_note) {
            $notifMessage .= ' Alasan: ' . $rejection_note;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type)
            VALUES (?, 'loan_rejected', ?, ?, ?, 'loan')
        ");
        $stmt->execute([$loan['user_id'], $notifTitle, $notifMessage, $loan_id]);
    }
}

header('Location: /index.php?page=admin_loans&msg=Peminjaman+berhasil+ditolak');
exit;
