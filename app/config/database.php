<?php
// app/config/database.php
return (function () {
    // config: ubah sesuai environment Anda
    $db_host = '127.0.0.1';
    $db_name = 'inventory_db';
    $db_user = 'root';
    $db_pass = '';

    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        // jika error koneksi, tampilkan pesan (development)
        die("Database connection failed: " . $e->getMessage());
    }
})();
