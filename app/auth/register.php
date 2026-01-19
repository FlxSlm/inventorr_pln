<?php
// app/auth/register.php
$pdo = require __DIR__ . '/../config/database.php';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (!$name || !$email || !$password) {
        $errors[] = 'Semua field wajib diisi.';
    }
    if ($password !== $confirm_password) {
        $errors[] = 'Password dan konfirmasi password tidak cocok.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password minimal 6 karakter.';
    }
    
    // check email
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) $errors[] = 'Email sudah terdaftar.';
    
    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)');
        $stmt->execute([$name, $email, $hash, 'karyawan']);
        header('Location: /index.php?page=login&msg=Registrasi berhasil! Silakan login.');
        exit;
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daftar - PLN Inventory System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="/public/assets/css/custom.css" rel="stylesheet">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">
<style>
body {
    min-height: 100vh;
    display: flex;
    align-items: center;
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
}
.register-card {
    background: rgba(30, 30, 50, 0.95);
    border-radius: 20px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.5);
    overflow: hidden;
    border-top: 4px solid #FDB913;
}
.register-left {
    background: linear-gradient(180deg, #0f75bc 0%, #0056a4 100%);
    padding: 3rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
}
.pln-logo {
    font-size: 5rem;
    margin-bottom: 1rem;
    filter: drop-shadow(0 0 20px rgba(253, 185, 19, 0.5));
}
.form-control {
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.2);
    color: #fff;
    padding: 12px 15px;
    border-radius: 10px;
}
.form-control:focus {
    background: rgba(255,255,255,0.15);
    border-color: #FDB913;
    color: #fff;
    box-shadow: 0 0 0 3px rgba(253, 185, 19, 0.2);
}
.form-control::placeholder {
    color: rgba(255,255,255,0.5);
}
.btn-register {
    background: linear-gradient(135deg, #FDB913 0%, #f59e0b 100%);
    border: none;
    color: #1a1a2e;
    font-weight: 700;
    padding: 12px;
    border-radius: 10px;
    transition: all 0.3s ease;
}
.btn-register:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(253, 185, 19, 0.4);
    color: #1a1a2e;
}
.feature-item {
    display: flex;
    align-items: center;
    margin-bottom: 1rem;
    text-align: left;
}
.feature-icon {
    width: 40px;
    height: 40px;
    background: rgba(253, 185, 19, 0.2);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    color: #FDB913;
}
</style>
</head>
<body>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10 col-xl-9">
            <div class="register-card">
                <div class="row g-0">
                    <div class="col-md-5 register-left d-none d-md-flex">
                        <div class="pln-logo">⚡</div>
                        <h2 class="text-white mb-3">PLN Inventory</h2>
                        <p class="text-white-50 mb-4">Bergabung dengan sistem manajemen inventaris terpadu</p>
                        
                        <div class="mt-4 w-100">
                            <div class="feature-item">
                                <div class="feature-icon">
                                    <i class="bi bi-box-seam"></i>
                                </div>
                                <div class="text-white-50 small">Akses katalog inventaris lengkap</div>
                            </div>
                            <div class="feature-item">
                                <div class="feature-icon">
                                    <i class="bi bi-clipboard-check"></i>
                                </div>
                                <div class="text-white-50 small">Ajukan peminjaman barang dengan mudah</div>
                            </div>
                            <div class="feature-item">
                                <div class="feature-icon">
                                    <i class="bi bi-clock-history"></i>
                                </div>
                                <div class="text-white-50 small">Pantau riwayat peminjaman Anda</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="p-4 p-lg-5">
                            <div class="text-center mb-4 d-md-none">
                                <div style="font-size: 3rem;">⚡</div>
                                <h4 class="text-pln-yellow">PLN Inventory</h4>
                            </div>
                            
                            <h3 class="text-white mb-1">Buat Akun Baru</h3>
                            <p class="text-secondary mb-4">Isi data di bawah untuk mendaftar</p>

                            <?php foreach($errors as $e): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                            <?php endforeach; ?>

                            <form method="POST" action="/index.php?page=register">
                                <div class="mb-3">
                                    <label class="form-label text-white">
                                        <i class="bi bi-person me-1"></i> Nama Lengkap
                                    </label>
                                    <input name="name" type="text" class="form-control" 
                                           placeholder="Masukkan nama lengkap" required
                                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label text-white">
                                        <i class="bi bi-envelope me-1"></i> Email
                                    </label>
                                    <input name="email" type="email" class="form-control" 
                                           placeholder="nama@email.com" required
                                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-white">
                                            <i class="bi bi-lock me-1"></i> Password
                                        </label>
                                        <input name="password" type="password" class="form-control" 
                                               placeholder="Min. 6 karakter" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label text-white">
                                            <i class="bi bi-lock-fill me-1"></i> Konfirmasi
                                        </label>
                                        <input name="confirm_password" type="password" class="form-control" 
                                               placeholder="Ulangi password" required>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="agreeTerms" required>
                                        <label class="form-check-label small text-secondary" for="agreeTerms">
                                            Saya setuju dengan <a href="#" class="text-pln-yellow">syarat dan ketentuan</a>
                                        </label>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-register w-100 py-2">
                                    <i class="bi bi-person-plus me-2"></i>Daftar Sekarang
                                </button>
                            </form>

                            <hr class="my-4" style="border-color: rgba(255,255,255,0.1);">
                            
                            <div class="text-center">
                                <span class="text-secondary">Sudah punya akun?</span>
                                <a href="/index.php?page=login" class="text-pln-yellow text-decoration-none fw-bold ms-1">
                                    Login
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>