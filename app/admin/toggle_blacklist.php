<?php
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}
$pdo = require __DIR__ . '/../config/database.php';
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    // toggle
    $stmt = $pdo->prepare('UPDATE users SET is_blacklisted = 1 - is_blacklisted WHERE id = ?');
    $stmt->execute([$id]);
}
header('Location: /index.php?page=admin_users_list'); // create that view to list users
exit;
