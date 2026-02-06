<?php
$pdo = require __DIR__ . '/../config/database.php';
$id = (int)($_GET['id'] ?? 0);

// check dependencies: loans or basts
$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM loans WHERE inventory_id = ? AND status IN ("pending","approved")');
$stmt->execute([$id]);
$c = $stmt->fetchColumn();
if ($c > 0) {
    // If AJAX request, return JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Tidak dapat menghapus barang ini karena masih ada peminjaman yang aktif.']);
        exit;
    }
    // Fallback: redirect with error message
    header('Location: /index.php?page=admin_inventory_list&msg=delete_failed');
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

// If AJAX request, return JSON success
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Barang berhasil dihapus.']);
    exit;
}
header('Location: /index.php?page=admin_inventory_list&msg=deleted');
exit;
