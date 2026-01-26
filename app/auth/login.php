<?php
// app/auth/login_new.php - Modern Login Page
$pdo = require __DIR__ . '/../config/database.php';
$errors = [];
$msg = $_GET['msg'] ?? '';

// Handle session expired message
$sessionExpiredMsg = '';
if ($msg === 'session_expired') {
    $sessionExpiredMsg = 'Sesi Anda telah berakhir karena tidak aktif selama 30 menit. Silakan login kembali.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'is_blacklisted' => (int)$user['is_blacklisted']
                ];
                $_SESSION['last_activity'] = time(); // Initialize session timeout tracker
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
<link rel="icon" href="/public/assets/img/logopln.png">
<style>
:root {
    --primary-dark: #0d4f5c;
    --primary: #0a6b7c;
    --primary-light: #1a9aaa;
    --accent: #2dd4bf;
    --accent-light: #5eead4;
    --bg-primary: #0f172a;
    --bg-secondary: #1e293b;
    --bg-tertiary: #334155;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(135deg, var(--bg-primary) 0%, #1a2744 50%, var(--primary-dark) 100%);
    overflow: hidden;
    position: relative;
}

/* Animated Background */
body::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: 
        radial-gradient(circle at 20% 80%, rgba(45, 212, 191, 0.1) 0%, transparent 50%),
        radial-gradient(circle at 80% 20%, rgba(10, 107, 124, 0.15) 0%, transparent 50%),
        radial-gradient(circle at 40% 40%, rgba(26, 154, 170, 0.1) 0%, transparent 40%);
    animation: pulse 8s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 0.5; }
    50% { opacity: 1; }
}

/* Floating shapes */
.shape {
    position: absolute;
    border-radius: 50%;
    opacity: 0.1;
    animation: float 20s infinite;
}

.shape-1 {
    width: 300px;
    height: 300px;
    background: linear-gradient(135deg, var(--accent), var(--primary-light));
    top: -100px;
    right: -100px;
    animation-delay: 0s;
}

.shape-2 {
    width: 200px;
    height: 200px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    bottom: -50px;
    left: -50px;
    animation-delay: -5s;
}

.shape-3 {
    width: 150px;
    height: 150px;
    background: linear-gradient(135deg, var(--accent-light), var(--primary-light));
    top: 50%;
    left: 10%;
    animation-delay: -10s;
}

@keyframes float {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    25% { transform: translate(30px, -30px) rotate(90deg); }
    50% { transform: translate(0, 30px) rotate(180deg); }
    75% { transform: translate(-30px, -20px) rotate(270deg); }
}

.login-container {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 1000px;
    padding: 1rem;
}

.login-card {
    background: rgba(30, 41, 59, 0.9);
    backdrop-filter: blur(20px);
    border-radius: 24px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.login-left {
    background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
    padding: 3rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.login-left::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    background: radial-gradient(circle, rgba(45, 212, 191, 0.2) 0%, transparent 70%);
}

.login-left::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -30%;
    width: 80%;
    height: 80%;
    background: radial-gradient(circle, rgba(94, 234, 212, 0.15) 0%, transparent 70%);
}

.brand-icon {
    font-size: 6rem;
    margin-bottom: 1.5rem;
    position: relative;
    z-index: 1;
    animation: glow 2s ease-in-out infinite;
}

.brand-icon img {
    width: 6rem;
    height: 6rem;
    object-fit: contain;
}

.mobile-brand .brand-icon img {
    width: 4rem;
    height: 4rem;
}

.brand-icons {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.brand-icons .brand-icon img {
    width: 6rem;
    height: 6rem;
}

.mobile-brand .brand-icons .brand-icon img {
    width: 4rem;
    height: 4rem;
}

/* Make Danantara logo larger */
.brand-icon.danantara img {
    width: 8rem;
    height: 8rem;
}

.mobile-brand .brand-icon.danantara img {
    width: 6rem;
    height: 6rem;
}

@keyframes glow {
    0%, 100% { filter: drop-shadow(0 0 20px rgba(45, 212, 191, 0.5)); }
    50% { filter: drop-shadow(0 0 40px rgba(45, 212, 191, 0.8)); }
}

.login-left h2 {
    font-size: 1.75rem;
    font-weight: 700;
    color: white;
    margin-bottom: 0.5rem;
    position: relative;
    z-index: 1;
}

.login-left p {
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.95rem;
    position: relative;
    z-index: 1;
}

.login-features {
    margin-top: 2rem;
    text-align: left;
    position: relative;
    z-index: 1;
}

.feature-item {
    display: flex;
    align-items: center;
    margin-bottom: 1rem;
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.875rem;
}

.feature-item i {
    color: var(--accent);
    margin-right: 0.75rem;
    font-size: 1rem;
}

.login-right {
    padding: 3rem;
}

.login-right h3 {
    font-size: 1.75rem;
    font-weight: 700;
    color: white;
    margin-bottom: 0.5rem;
}

.login-right .subtitle {
    color: #94a3b8;
    margin-bottom: 2rem;
}

.form-label {
    font-weight: 500;
    color: #e2e8f0;
    margin-bottom: 0.5rem;
    font-size: 0.875rem;
}

.form-control {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #fff;
    padding: 0.875rem 1rem;
    border-radius: 12px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
}

.form-control:focus {
    background: rgba(255, 255, 255, 0.08);
    border-color: var(--accent);
    color: #fff;
    box-shadow: 0 0 0 4px rgba(45, 212, 191, 0.15);
}

.form-control::placeholder {
    color: #64748b;
}

.input-group {
    position: relative;
}

.input-icon {
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    cursor: pointer;
    z-index: 5;
}

.btn-login {
    background: linear-gradient(135deg, var(--accent) 0%, var(--primary-light) 100%);
    border: none;
    color: var(--bg-primary);
    font-weight: 600;
    padding: 0.875rem;
    border-radius: 12px;
    font-size: 1rem;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.btn-login::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.5s ease;
}

.btn-login:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(45, 212, 191, 0.3);
    color: var(--bg-primary);
}

.btn-login:hover::before {
    left: 100%;
}

.divider {
    display: flex;
    align-items: center;
    margin: 1.5rem 0;
}

.divider::before,
.divider::after {
    content: '';
    flex: 1;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.divider span {
    padding: 0 1rem;
    color: #64748b;
    font-size: 0.875rem;
}

.register-link {
    text-align: center;
}

.register-link span {
    color: #94a3b8;
}

.register-link a {
    color: var(--accent);
    text-decoration: none;
    font-weight: 600;
    transition: color 0.3s ease;
}

.register-link a:hover {
    color: var(--accent-light);
}

.alert {
    border: none;
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.alert-danger {
    background: rgba(239, 68, 68, 0.15);
    color: #fca5a5;
    border-left: 4px solid #ef4444;
}

.alert-success {
    background: rgba(34, 197, 94, 0.15);
    color: #86efac;
    border-left: 4px solid #22c55e;
}

.mobile-brand {
    text-align: center;
    margin-bottom: 2rem;
}

.mobile-brand .brand-icon {
    font-size: 3rem;
    margin-bottom: 0.5rem;
}

.mobile-brand h4 {
    color: var(--accent);
    font-weight: 700;
}

@media (max-width: 767.98px) {
    .login-left {
        display: none !important;
    }
    
    .login-right {
        padding: 2rem;
    }
    
    .mobile-brand {
        display: block;
    }
}

@media (min-width: 768px) {
    .mobile-brand {
        display: none;
    }
}
</style>
</head>
<body>
<!-- Floating Shapes -->
<div class="shape shape-1"></div>
<div class="shape shape-2"></div>
<div class="shape shape-3"></div>

<div class="login-container">
    <div class="login-card">
        <div class="row g-0">
            <div class="col-md-5 login-left">
                <div class="brand-icons">
                    <div class="brand-icon"><img src="/public/assets/img/logopln.png" alt="PLN Logo"></div>
                    <div class="brand-icon danantara"><img src="/public/assets/img/danantara.png" alt="Danantara Logo"></div>
                </div>
                <h2>PLN Inventory</h2>
                <p>Sistem Manajemen Inventaris Terpadu</p>
                
                <div class="login-features">
                    <div class="feature-item">
                        <i class="bi bi-shield-check"></i>
                        <span>Akses aman & terenkripsi</span>
                    </div>
                    <div class="feature-item">
                        <i class="bi bi-box-seam"></i>
                        <span>Manajemen inventaris real-time</span>
                    </div>
                    <div class="feature-item">
                        <i class="bi bi-graph-up"></i>
                        <span>Laporan & analitik lengkap</span>
                    </div>
                    <div class="feature-item">
                        <i class="bi bi-clock-history"></i>
                        <span>Tracking peminjaman terintegrasi</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-7 login-right">
                <div class="mobile-brand">
                    <div class="brand-icons">
                        <div class="brand-icon"><img src="/public/assets/img/logopln.png" alt="PLN Logo"></div>
                        <div class="brand-icon danantara"><img src="/public/assets/img/danantara.png" alt="Danantara Logo"></div>
                    </div>
                    <h4>PLN Inventory</h4>
                </div>
                
                <h3>Selamat Datang!</h3>
                <p class="subtitle">Masuk ke akun Anda untuk melanjutkan</p>

                <?php if($sessionExpiredMsg): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-clock-history me-2"></i><?= htmlspecialchars($sessionExpiredMsg) ?>
                </div>
                <?php endif; ?>

                <?php if($msg && $msg !== 'session_expired'): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
                </div>
                <?php endif; ?>

                <?php foreach($errors as $e): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
                </div>
                <?php endforeach; ?>

                <form method="POST" action="/index.php?page=login">
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-envelope me-1"></i>Email
                        </label>
                        <input name="email" type="email" class="form-control" 
                               placeholder="nama@email.com" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">
                            <i class="bi bi-lock me-1"></i>Password
                        </label>
                        <div class="input-group">
                            <input name="password" type="password" class="form-control" 
                                   placeholder="Masukkan password" required id="passwordInput">
                            <span class="input-icon" onclick="togglePassword()">
                                <i class="bi bi-eye" id="eyeIcon"></i>
                            </span>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-login w-100">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Masuk
                    </button>
                </form>

                <div class="divider">
                    <span>atau</span>
                </div>
                
                <div class="register-link">
                    <span>Belum punya akun?</span>
                    <a href="/index.php?page=register">Daftar Sekarang</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword() {
    const input = document.getElementById('passwordInput');
    const icon = document.getElementById('eyeIcon');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}
</script>
</body>
</html>
