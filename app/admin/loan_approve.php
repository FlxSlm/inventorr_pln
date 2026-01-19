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
    // mark loan as awaiting document (employee must upload filled template)
    $stmt = $pdo->prepare('UPDATE loans SET stage = ?, note = CONCAT(IFNULL(note,""), "\n[admin] initial approved at ", NOW()) WHERE id = ?');
    $stmt->execute(['awaiting_document', $loan_id]);

    header('Location: /index.php?page=admin_loans&msg=' . urlencode('Initial approved — waiting for employee document'));
    exit;
} catch (Exception $e) {
    header('Location: /index.php?page=admin_loans&msg=' . urlencode('Error: ' . $e->getMessage()));
    exit;
}
