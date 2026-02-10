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
        // Check if user has active loans (approved but not returned)
        $activeLoanCheck = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE user_id = ? AND stage = 'approved' AND (return_stage IS NULL OR return_stage NOT IN ('return_approved'))");
        $activeLoanCheck->execute([$userId]);
        $activeLoans = $activeLoanCheck->fetchColumn();
        
        if ($activeLoans > 0) {
            header('Location: /index.php?page=admin_users_list&error=' . urlencode('Tidak dapat menghapus user "' . $user['name'] . '" karena masih memiliki ' . $activeLoans . ' peminjaman aktif yang belum dikembalikan. Harap selesaikan semua peminjaman terlebih dahulu.'));
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Delete related records first to avoid FK constraint errors
            $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM material_suggestions WHERE user_id = ?')->execute([$userId]);
            
            // Delete related requests
            $pdo->prepare('DELETE FROM requests WHERE user_id = ?')->execute([$userId]);
            
            // Delete related loans (only non-active ones should remain at this point)
            $pdo->prepare('DELETE FROM loans WHERE user_id = ?')->execute([$userId]);
            
            // Delete the user
            $deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $deleteStmt->execute([$userId]);
            
            $pdo->commit();
            
            header('Location: /index.php?page=admin_users_list&msg=' . urlencode('User "' . $user['name'] . '" berhasil dihapus'));
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            header('Location: /index.php?page=admin_users_list&error=' . urlencode('Gagal menghapus user: ' . $e->getMessage()));
            exit;
        }
    }
}

header('Location: /index.php?page=admin_users_list&msg=' . urlencode('User tidak ditemukan'));
exit;
