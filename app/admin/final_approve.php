<?php
// app/admin/final_approve.php
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}
$pdo = require __DIR__ . '/../config/database.php';
$loan_id = (int)($_POST['loan_id'] ?? 0);
if (!$loan_id) header('Location: /index.php?page=admin_loans');

$pdo->beginTransaction();
try {
    // lock loan row
    $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = ? FOR UPDATE');
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch();
    if (!$loan) throw new Exception('Loan not found');
    if ($loan['stage'] !== 'submitted') throw new Exception('Loan not in submitted stage');

    // check inventory
    $stmt = $pdo->prepare('SELECT * FROM inventories WHERE id = ? FOR UPDATE');
    $stmt->execute([$loan['inventory_id']]);
    $inv = $stmt->fetch();
    if (!$inv) throw new Exception('Inventory not found');

    if ($inv['stock_available'] < $loan['quantity']) {
        throw new Exception('Insufficient stock');
    }

    // decrease stock
    $stmt = $pdo->prepare('UPDATE inventories SET stock_available = stock_available - ? WHERE id = ?');
    $stmt->execute([$loan['quantity'], $inv['id']]);

    // set loan approved
    $stmt = $pdo->prepare('UPDATE loans SET stage = ?, approved_at = NOW(), approved_by = ? WHERE id = ?');
    $stmt->execute(['approved', $_SESSION['user']['id'], $loan_id]);

    $pdo->commit();
    header('Location: /index.php?page=admin_loans&msg=' . urlencode('Loan finally approved'));
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: /index.php?page=admin_loans&msg=' . urlencode('Error: ' . $e->getMessage()));
    exit;
}
