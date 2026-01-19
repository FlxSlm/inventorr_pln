<?php
// test_db.php - Test database connection
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $pdo = require __DIR__ . '/../app/config/database.php';
    echo "Koneksi database berhasil!<br>";
    
    // Test query
    $stmt = $pdo->query('SELECT COUNT(*) FROM users');
    $count = $stmt->fetchColumn();
    echo "Jumlah user: $count<br>";
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM inventories');
    $count = $stmt->fetchColumn();
    echo "Jumlah inventaris: $count<br>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>