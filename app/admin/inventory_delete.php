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

// Get item info to delete image file
$stmt = $pdo->prepare('SELECT image FROM inventories WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();

// Delete image file if exists
if ($item && $item['image']) {
    $imagePath = 'C:/XAMPP/htdocs/inventory_pln/public/assets/uploads/' . $item['image'];
    if (file_exists($imagePath)) {
        @unlink($imagePath);
    }
}

// Delete from inventory_categories first (foreign key)
$stmt = $pdo->prepare('DELETE FROM inventory_categories WHERE inventory_id = ?');
$stmt->execute([$id]);

// Permanently delete from database
$stmt = $pdo->prepare('DELETE FROM inventories WHERE id = ?');
$stmt->execute([$id]);

header('Location: /index.php?page=admin_inventory_list');
exit;
