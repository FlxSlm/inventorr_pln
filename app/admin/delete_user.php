<?php
// app/admin/delete_user.php
// Handle user deletion by admin

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';

$userId = (int)($_GET['id'] ?? 0);
$adminId = (int)$_SESSION['user']['id'];

// Prevent self-deletion
if ($userId === $adminId) {
    header('Location: /index.php?page=admin_users_list&msg=' . urlencode('Tidak dapat menghapus akun sendiri'));
    exit;
}

if ($userId > 0) {
    // Check if user exists
    $stmt = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Delete user's loans first (or you can soft-delete / keep them)
        // For now, just delete the user - loans remain as historical data
        $deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $deleteStmt->execute([$userId]);
        
        header('Location: /index.php?page=admin_users_list&msg=' . urlencode('User "' . $user['name'] . '" berhasil dihapus'));
        exit;
    }
}

header('Location: /index.php?page=admin_users_list&msg=' . urlencode('User tidak ditemukan'));
exit;
