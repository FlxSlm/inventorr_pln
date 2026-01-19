<?php
// insert_dummy.php - Insert dummy data for testing
error_reporting(E_ALL);
ini_set('display_errors', 1);

$pdo = require __DIR__ . '/../app/config/database.php';

try {
    // Insert dummy users (password: password123 hashed) - use INSERT IGNORE to skip duplicates
    $passwordHash = password_hash('password123', PASSWORD_DEFAULT);
    $pdo->exec("INSERT IGNORE INTO users (name, email, password, role) VALUES 
        ('Admin User', 'admin@example.com', 'admin123', 'admin'),
        ('John Doe', 'john@example.com', '$passwordHash', 'karyawan'),
        ('Jane Smith', 'jane@example.com', '$passwordHash', 'karyawan')
    ");
    
    // Insert dummy inventories - use INSERT IGNORE
    $pdo->exec("INSERT IGNORE INTO inventories (name, code, description, stock_total, stock_available, unit) VALUES 
        ('Laptop', 'LPT001', 'Laptop untuk kerja', 10, 10, 'unit'),
        ('Printer', 'PRT001', 'Printer laser', 5, 5, 'unit'),
        ('Mouse', 'MSE001', 'Mouse wireless', 20, 20, 'unit')
    ");
    
    echo "Data dummy berhasil dimasukkan (skip jika duplikat)!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>