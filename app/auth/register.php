<?php
// app/auth/register_new.php - Modern Register Page
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
    padding: 1rem;
}

body::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: 
        radial-gradient(circle at 20% 80%, rgba(45, 212, 191, 0.1) 0%, transparent 50%),
        radial-gradient(circle at 80% 20%, rgba(10, 107, 124, 0.15) 0%, transparent 50%);
    animation: pulse 8s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 0.5; }
    50% { opacity: 1; }
}

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
}

.shape-2 {
    width: 200px;
    height: 200px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    bottom: -50px;
    left: -50px;
    animation-delay: -5s;
}

@keyframes float {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    50% { transform: translate(20px, 20px) rotate(180deg); }
}

.register-container {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 1000px;
}

.register-card {
    background: rgba(30, 41, 59, 0.9);
    backdrop-filter: blur(20px);
    border-radius: 24px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.register-left {
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

.register-left::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    background: radial-gradient(circle, rgba(45, 212, 191, 0.2) 0%, transparent 70%);
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

/* Larger Danantara logo */
.brand-icon.danantara img {
    width: 10rem;
    height: 10rem;
}

.mobile-brand .brand-icon.danantara img {
    width: 6rem;
    height: 6rem;
}

@keyframes glow {
    0%, 100% { filter: drop-shadow(0 0 20px rgba(45, 212, 191, 0.5)); }
    50% { filter: drop-shadow(0 0 40px rgba(45, 212, 191, 0.8)); }
}

.register-left h2 {
    font-size: 1.75rem;
    font-weight: 700;
    color: white;
    margin-bottom: 0.5rem;
    position: relative;
    z-index: 1;
}

.register-left p {
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.95rem;
    position: relative;
    z-index: 1;
}

.features {
    margin-top: 2rem;
    text-align: left;
    position: relative;
    z-index: 1;
}

.feature-item {
    display: flex;
    align-items: center;
    margin-bottom: 1rem;
}

.feature-icon {
    width: 40px;
    height: 40px;
    background: rgba(45, 212, 191, 0.2);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 1rem;
    color: var(--accent);
    flex-shrink: 0;
}

.feature-text {
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.875rem;
}

.register-right {
    padding: 2.5rem 3rem;
}

.register-right h3 {
    font-size: 1.75rem;
    font-weight: 700;
    color: white;
    margin-bottom: 0.5rem;
}

.register-right .subtitle {
    color: #94a3b8;
    margin-bottom: 1.5rem;
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
    padding: 0.75rem 1rem;
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

.btn-register {
    background: linear-gradient(135deg, var(--accent) 0%, var(--primary-light) 100%);
    border: none;
    color: var(--bg-primary);
    font-weight: 600;
    padding: 0.875rem;
    border-radius: 12px;
    font-size: 1rem;
    transition: all 0.3s ease;
}

.btn-register:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(45, 212, 191, 0.3);
    color: var(--bg-primary);
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

.login-link {
    text-align: center;
}

.login-link span {
    color: #94a3b8;
}

.login-link a {
    color: var(--accent);
    text-decoration: none;
    font-weight: 600;
}

.login-link a:hover {
    color: var(--accent-light);
}

.alert {
    border: none;
    border-radius: 12px;
    padding: 0.875rem;
    margin-bottom: 1rem;
}

.alert-danger {
    background: rgba(239, 68, 68, 0.15);
    color: #fca5a5;
    border-left: 4px solid #ef4444;
}

.mobile-brand {
    text-align: center;
    margin-bottom: 1.5rem;
}

.mobile-brand .brand-icon {
    font-size: 3rem;
    margin-bottom: 0.5rem;
}

.mobile-brand h4 {
    color: var(--accent);
    font-weight: 700;
}

.password-strength {
    margin-top: 0.5rem;
}

.strength-bar {
    height: 4px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 2px;
    overflow: hidden;
}

.strength-fill {
    height: 100%;
    width: 0;
    transition: all 0.3s ease;
}

.strength-text {
    font-size: 0.75rem;
    margin-top: 0.25rem;
}

@media (max-width: 767.98px) {
    .register-left {
        display: none !important;
    }
    
    .register-right {
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
<div class="shape shape-1"></div>
<div class="shape shape-2"></div>

<div class="register-container">
    <div class="register-card">
        <div class="row g-0">
            <div class="col-md-5 register-left">
                <div class="brand-icons">
                    <div class="brand-icon"><img src="/public/assets/img/logopln.png" alt="PLN Logo"></div>
                    <div class="brand-icon danantara"><img src="/public/assets/img/danantara.png" alt="Danantara Logo"></div>
                </div>
                <h2>PLN Inventory</h2>
                <p>Bergabung dengan sistem manajemen inventaris terpadu</p>
                
                <div class="features">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <div class="feature-text">Akses katalog inventaris lengkap</div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <div class="feature-text">Ajukan peminjaman barang dengan mudah</div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="feature-text">Pantau riwayat peminjaman Anda</div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <div class="feature-text">Data aman & terenkripsi</div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-7 register-right">
                <div class="mobile-brand">
                    <div class="brand-icons">
                        <div class="brand-icon"><img src="/public/assets/img/logopln.png" alt="PLN Logo"></div>
                        <div class="brand-icon danantara"><img src="/public/assets/img/danantara.png" alt="Danantara Logo"></div>
                    </div>
                    <h4>PLN Inventory</h4>
                </div>
                
                <h3>Buat Akun Baru</h3>
                <p class="subtitle">Isi data di bawah untuk mendaftar</p>

                <?php foreach($errors as $e): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
                </div>
                <?php endforeach; ?>

                <form method="POST" action="/index.php?page=register">
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-person me-1"></i>Nama Lengkap
                        </label>
                        <input name="name" type="text" class="form-control" 
                               placeholder="Masukkan nama lengkap" required
                               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-envelope me-1"></i>Email
                        </label>
                        <input name="email" type="email" class="form-control" 
                               placeholder="nama@email.com" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-lock me-1"></i>Password
                            </label>
                            <div class="input-group">
                                <input name="password" type="password" class="form-control" 
                                       placeholder="Min. 6 karakter" required id="passwordInput">
                                <span class="input-icon" onclick="togglePassword('passwordInput', 'eyeIcon1')">
                                    <i class="bi bi-eye" id="eyeIcon1"></i>
                                </span>
                            </div>
                            <div class="password-strength" id="passwordStrength" style="display: none;">
                                <div class="strength-bar">
                                    <div class="strength-fill" id="strengthFill"></div>
                                </div>
                                <div class="strength-text" id="strengthText"></div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="bi bi-lock-fill me-1"></i>Konfirmasi
                            </label>
                            <div class="input-group">
                                <input name="confirm_password" type="password" class="form-control" 
                                       placeholder="Ulangi password" required id="confirmInput">
                                <span class="input-icon" onclick="togglePassword('confirmInput', 'eyeIcon2')">
                                    <i class="bi bi-eye" id="eyeIcon2"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-register w-100 mt-2">
                        <i class="bi bi-person-plus me-2"></i>Daftar Sekarang
                    </button>
                </form>

                <div class="divider">
                    <span>atau</span>
                </div>
                
                <div class="login-link">
                    <span>Sudah punya akun?</span>
                    <a href="/index.php?page=login">Masuk</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    
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

// Password strength checker
document.getElementById('passwordInput').addEventListener('input', function(e) {
    const password = e.target.value;
    const strengthDiv = document.getElementById('passwordStrength');
    const fill = document.getElementById('strengthFill');
    const text = document.getElementById('strengthText');
    
    if (password.length === 0) {
        strengthDiv.style.display = 'none';
        return;
    }
    
    strengthDiv.style.display = 'block';
    
    let strength = 0;
    if (password.length >= 6) strength++;
    if (password.length >= 8) strength++;
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
    if (/\d/.test(password)) strength++;
    if (/[^a-zA-Z0-9]/.test(password)) strength++;
    
    const colors = ['#ef4444', '#f59e0b', '#eab308', '#22c55e', '#10b981'];
    const labels = ['Sangat Lemah', 'Lemah', 'Cukup', 'Kuat', 'Sangat Kuat'];
    
    fill.style.width = ((strength / 5) * 100) + '%';
    fill.style.background = colors[strength - 1] || colors[0];
    text.textContent = labels[strength - 1] || labels[0];
    text.style.color = colors[strength - 1] || colors[0];
});
</script>
</body>
</html>
