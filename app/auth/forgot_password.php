<?php
// app/auth/forgot_password.php
// Halaman untuk meminta reset password

$pdo = require __DIR__ . '/../config/database.php';
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $errors[] = 'Email wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    } else {
        // Check if email exists
        $stmt = $pdo->prepare('SELECT id, name, role FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $errors[] = 'Email tidak terdaftar dalam sistem.';
        } else {
            // Check if there's already a pending request
            $stmt = $pdo->prepare('SELECT id FROM password_reset_requests WHERE email = ? AND status = "pending"');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'Permintaan reset password sudah ada dan masih menunggu persetujuan admin.';
            } else {
                // Create password reset request
                $stmt = $pdo->prepare('INSERT INTO password_reset_requests (user_id, email, status, requested_at) VALUES (?, ?, "pending", NOW())');
                $stmt->execute([$user['id'], $email]);
                
                // Create notification for admin
                $stmt = $pdo->prepare('INSERT INTO notifications (user_id, type, message, link, created_at) 
                    SELECT id, "password_reset", ?, "/index.php?page=admin_users_list", NOW() 
                    FROM users WHERE role = "admin"');
                $message = 'Permintaan reset password dari ' . htmlspecialchars($user['name']) . ' (' . $email . ')';
                $stmt->execute([$message]);
                
                $success = 'Permintaan reset password telah dikirim. Silakan tunggu persetujuan admin.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lupa Password - PLN Inventory System</title>
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

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(135deg, var(--bg-primary) 0%, #1a2744 50%, var(--primary-dark) 100%);
    position: relative;
}

body::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: 
        radial-gradient(circle at 20% 80%, rgba(45, 212, 191, 0.1) 0%, transparent 50%),
        radial-gradient(circle at 80% 20%, rgba(10, 107, 124, 0.15) 0%, transparent 50%);
    animation: pulse 8s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 0.5; }
    50% { opacity: 1; }
}

.forgot-container {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 480px;
    padding: 1rem;
}

.forgot-card {
    background: rgba(30, 41, 59, 0.9);
    backdrop-filter: blur(20px);
    border-radius: 24px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    border: 1px solid rgba(255, 255, 255, 0.1);
    padding: 2.5rem;
}

.brand-section {
    text-align: center;
    margin-bottom: 2rem;
}

.brand-section img {
    width: 80px;
    height: 80px;
    margin-bottom: 1rem;
}

.brand-section h2 {
    color: var(--accent);
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.brand-section p {
    color: #94a3b8;
    font-size: 0.9rem;
}

h3 {
    color: white;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.subtitle {
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

.form-control::placeholder { color: #64748b; }

.btn-submit {
    background: linear-gradient(135deg, var(--accent) 0%, var(--primary-light) 100%);
    border: none;
    color: var(--bg-primary);
    font-weight: 600;
    padding: 0.875rem;
    border-radius: 12px;
    font-size: 1rem;
    transition: all 0.3s ease;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(45, 212, 191, 0.3);
    color: var(--bg-primary);
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

.back-link {
    text-align: center;
    margin-top: 1.5rem;
}

.back-link a {
    color: var(--accent);
    text-decoration: none;
}

.back-link a:hover {
    text-decoration: underline;
}

.info-box {
    background: rgba(45, 212, 191, 0.1);
    border: 1px solid rgba(45, 212, 191, 0.2);
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.info-box i {
    color: var(--accent);
}

.info-box p {
    color: #94a3b8;
    font-size: 0.875rem;
    margin: 0;
}
</style>
</head>
<body>
<div class="forgot-container">
    <div class="forgot-card">
        <div class="brand-section">
            <img src="/public/assets/img/logopln.png" alt="PLN Logo">
            <h2>PLN Inventory</h2>
            <p>Sistem Manajemen Inventaris</p>
        </div>
        
        <h3><i class="bi bi-key me-2"></i>Lupa Password</h3>
        <p class="subtitle">Masukkan email Anda untuk meminta reset password</p>
        
        <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>
        
        <?php foreach($errors as $e): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
        </div>
        <?php endforeach; ?>
        
        <div class="info-box">
            <p><i class="bi bi-info-circle me-2"></i>Setelah mengajukan permintaan, admin akan mereview dan mengirimkan password baru ke email Anda.</p>
        </div>
        
        <form method="POST" action="/index.php?page=forgot_password">
            <div class="mb-4">
                <label class="form-label">
                    <i class="bi bi-envelope me-1"></i>Email
                </label>
                <input name="email" type="email" class="form-control" 
                       placeholder="nama@email.com" required
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            
            <button type="submit" class="btn btn-submit w-100">
                <i class="bi bi-send me-2"></i>Kirim Permintaan
            </button>
        </form>
        
        <div class="back-link">
            <a href="/index.php?page=login"><i class="bi bi-arrow-left me-1"></i>Kembali ke Login</a>
        </div>
    </div>
</div>
</body>
</html>
