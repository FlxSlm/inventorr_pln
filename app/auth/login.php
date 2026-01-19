<?php
// app/auth/login.php
// NOTE: session already started in public/index.php before requiring this file.
$pdo = require __DIR__ . '/../config/database.php';
$errors = [];

// show optional message (e.g. registered, logout)
$msg = $_GET['msg'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // sanitize
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errors[] = 'Email dan password wajib diisi.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if (!empty($user['is_blacklisted'])) {
                $errors[] = 'Akun Anda diblokir. Hubungi admin.';
            } else {
                // login success: store necessary user info in session
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'is_blacklisted' => (int)$user['is_blacklisted']
                ];
                // redirect to dashboard (home)
                header('Location: /index.php');
                exit;
            }
        } else {
            $errors[] = 'Email atau password salah';
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - PLN Inventory System</title>
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
.login-card {
    background: rgba(30, 30, 50, 0.95);
    border-radius: 20px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.5);
    overflow: hidden;
    border-top: 4px solid #FDB913;
}
.login-left {
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
.btn-login {
    background: linear-gradient(135deg, #FDB913 0%, #f59e0b 100%);
    border: none;
    color: #1a1a2e;
    font-weight: 700;
    padding: 12px;
    border-radius: 10px;
    transition: all 0.3s ease;
}
.btn-login:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(253, 185, 19, 0.4);
    color: #1a1a2e;
}
</style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-10 col-xl-9">
            <div class="login-card">
                <div class="row g-0">
                    <div class="col-md-5 login-left d-none d-md-flex">
                        <div class="pln-logo">⚡</div>
                        <h2 class="text-white mb-3">PLN Inventory</h2>
                        <p class="text-white-50 mb-4">Sistem Manajemen Inventaris Terpadu</p>
                        <div class="mt-auto">
                            <small class="text-white-50">
                                <i class="bi bi-shield-check me-1"></i>
                                Akses aman & terenkripsi
                            </small>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="p-4 p-lg-5">
                            <div class="text-center mb-4 d-md-none">
                                <div style="font-size: 3rem;">⚡</div>
                                <h4 class="text-pln-yellow">PLN Inventory</h4>
                            </div>
                            
                            <h3 class="text-white mb-1">Selamat Datang!</h3>
                            <p class="text-secondary mb-4">Masuk ke akun Anda untuk melanjutkan</p>

                            <?php if($msg): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                            <?php endif; ?>

                            <?php foreach($errors as $e): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                            <?php endforeach; ?>

                            <form method="POST" action="/index.php?page=login">
                                <div class="mb-3">
                                    <label class="form-label text-white">
                                        <i class="bi bi-envelope me-1"></i> Email
                                    </label>
                                    <input name="email" type="email" class="form-control" 
                                           placeholder="nama@email.com" required
                                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                </div>
                                <div class="mb-4">
                                    <label class="form-label text-white">
                                        <i class="bi bi-lock me-1"></i> Password
                                    </label>
                                    <input name="password" type="password" class="form-control" 
                                           placeholder="Masukkan password" required>
                                </div>
                                <button type="submit" class="btn btn-login w-100 py-2">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>Masuk
                                </button>
                            </form>

                            <hr class="my-4" style="border-color: rgba(255,255,255,0.1);">
                            
                            <div class="text-center">
                                <span class="text-secondary">Belum punya akun?</span>
                                <a href="/index.php?page=register" class="text-pln-yellow text-decoration-none fw-bold ms-1">
                                    Daftar Sekarang
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
