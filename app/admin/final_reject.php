<?php
// app/admin/final_reject.php
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}
$pdo = require __DIR__ . '/../config/database.php';
$loan_id = (int)($_POST['loan_id'] ?? 0);
if ($loan_id) {
    $stmt = $pdo->prepare('UPDATE loans SET stage = ?, note = CONCAT(IFNULL(note,""), "\n[admin] rejected at ", NOW()) WHERE id = ?');
    $stmt->execute(['rejected', $loan_id]);
}
header('Location: /index.php?page=admin_loans&msg=' . urlencode('Peminjaman ditolak'));
exit;
