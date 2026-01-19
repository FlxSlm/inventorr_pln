<?php
$pdo = require __DIR__ . '/../config/database.php';
$id = (int)($_GET['id'] ?? 0);

// check dependencies: loans or basts
$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM loans WHERE inventory_id = ? AND status IN ("pending","approved")');
$stmt->execute([$id]);
$c = $stmt->fetchColumn();
if ($c > 0) {
    echo "<div class='alert alert-danger'>Tidak dapat menghapus, ada peminjaman terkait.</div>";
    echo "<a href='/index.php?page=admin_inventory_list' class='btn btn-secondary'>Kembali</a>";
    exit;
}

// soft delete
$stmt = $pdo->prepare('UPDATE inventories SET deleted_at = NOW() WHERE id = ?');
$stmt->execute([$id]);
header('Location: /index.php?page=admin_inventory_list');
exit;
