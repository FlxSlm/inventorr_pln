<?php
// app/admin/settings.php
// Admin settings management page

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

$pageTitle = 'Pengaturan';
$pdo = require __DIR__ . '/../config/database.php';

$success = '';
$errors = [];

// Ensure settings table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NOT NULL,
        description VARCHAR(255),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    // Insert default if not exists
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value, description) 
                VALUES ('low_stock_threshold', '5', 'Batas minimum stok untuk dikategorikan sebagai stok menipis')");
} catch (Exception $e) {
    // Ignore if already exists
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lowStockThreshold = (int)($_POST['low_stock_threshold'] ?? 5);
    
    if ($lowStockThreshold < 1) {
        $errors[] = 'Batas stok minimum harus minimal 1';
    }
    
    if (empty($errors)) {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, description) 
                               VALUES ('low_stock_threshold', ?, 'Batas minimum stok untuk dikategorikan sebagai stok menipis')
                               ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$lowStockThreshold, $lowStockThreshold]);
        $success = 'Pengaturan berhasil disimpan';
    }
}

// Get current settings
$lowStockThreshold = 5;
try {
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'low_stock_threshold'");
    $result = $stmt->fetch();
    if ($result) {
        $lowStockThreshold = (int)$result['setting_value'];
    }
} catch (Exception $e) {
    // Use default
}

// Count current low stock items
$lowStockCount = $pdo->query("SELECT COUNT(*) FROM inventories WHERE stock_available <= $lowStockThreshold AND deleted_at IS NULL")->fetchColumn();
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-gear-fill"></i> Pengaturan Sistem</h3>
        <p>Kelola konfigurasi sistem inventaris</p>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="modern-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-sliders me-2" style="color: var(--primary-light);"></i>Pengaturan Inventaris
                </h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-exclamation-triangle me-1 text-warning"></i>Batas Stok Menipis
                        </label>
                        <div class="input-group" style="max-width: 300px;">
                            <input type="number" name="low_stock_threshold" class="form-control form-control-lg" 
                                   value="<?= $lowStockThreshold ?>" min="1" required>
                            <span class="input-group-text">unit</span>
                        </div>
                        <small class="text-muted d-block mt-2">
                            <i class="bi bi-info-circle me-1"></i>
                            Barang dengan stok tersedia kurang dari atau sama dengan nilai ini akan ditandai sebagai "Stok Menipis"
                        </small>
                        <div class="mt-2">
                            <span class="badge bg-warning text-dark">
                                <i class="bi bi-box-seam me-1"></i>
                                Saat ini: <?= $lowStockCount ?> barang dengan stok menipis
                            </span>
                        </div>
                    </div>
                    
                    <hr class="my-4">
                    
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-lg me-2"></i>Simpan Pengaturan
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="modern-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-info-circle me-2" style="color: var(--primary-light);"></i>Informasi
                </h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <h6 class="text-muted mb-2">Tentang Batas Stok Menipis</h6>
                    <p style="font-size: 14px; color: var(--text-muted);">
                        Pengaturan ini menentukan kapan suatu barang dianggap memiliki stok yang menipis. 
                        Barang dengan stok di bawah batas ini akan:
                    </p>
                    <ul style="font-size: 14px; color: var(--text-muted);">
                        <li>Ditampilkan di dashboard sebagai "Stok Menipis"</li>
                        <li>Ditandai dengan badge peringatan</li>
                        <li>Diprioritaskan untuk restock</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
