<?php
// app/auth/forgot_password.php
// Halaman untuk meminta reset password dengan Email Verification

$pdo = require __DIR__ . '/../config/database.php';
$errors = [];
$success = '';
$showResetForm = false;
$validToken = null;

// Check if we have a reset token (user clicked link from email)
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];
    
    // Verify token
    $stmt = $pdo->prepare('SELECT * FROM password_reset_requests WHERE reset_token = ? AND token_expires_at > NOW() AND status = "pending" LIMIT 1');
    $stmt->execute([$token]);
    $resetRequest = $stmt->fetch();
    
    if ($resetRequest) {
        $showResetForm = true;
        $validToken = $token;
    } else {
        $errors[] = 'Link reset password tidak valid atau sudah kadaluarsa. Silakan ajukan permintaan baru.';
    }
}

// Handle password reset (user submitted new password)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $token = $_POST['token'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($newPassword) || strlen($newPassword) < 6) {
        $errors[] = 'Password baru minimal 6 karakter.';
    } elseif ($newPassword !== $confirmPassword) {
        $errors[] = 'Konfirmasi password tidak cocok.';
    } else {
        // Verify token again
        $stmt = $pdo->prepare('SELECT * FROM password_reset_requests WHERE reset_token = ? AND token_expires_at > NOW() AND status = "pending" LIMIT 1');
        $stmt->execute([$token]);
        $resetRequest = $stmt->fetch();
        
        if ($resetRequest) {
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->execute([$hashedPassword, $resetRequest['user_id']]);
            
            // Mark request as used
            $stmt = $pdo->prepare('UPDATE password_reset_requests SET status = "approved", processed_at = NOW() WHERE id = ?');
            $stmt->execute([$resetRequest['id']]);
            
            $success = 'Password berhasil diubah! Silakan login dengan password baru Anda.';
            $showResetForm = false;
        } else {
            $errors[] = 'Link reset password tidak valid atau sudah kadaluarsa.';
        }
    }
    
    if (!empty($errors)) {
        $showResetForm = true;
        $validToken = $token;
    }
}

// Handle forgot password request (user submitted email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
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
            // Generate reset token
            $resetToken = bin2hex(random_bytes(32));
            $tokenExpires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Delete any existing pending requests for this email
            $stmt = $pdo->prepare('DELETE FROM password_reset_requests WHERE email = ? AND status = "pending"');
            $stmt->execute([$email]);
            
            // Create new reset request with token
            $stmt = $pdo->prepare('INSERT INTO password_reset_requests (user_id, email, status, reset_token, token_expires_at, requested_at) VALUES (?, ?, "pending", ?, ?, NOW())');
            $stmt->execute([$user['id'], $email, $resetToken, $tokenExpires]);
            
            // Try to send email
            $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/index.php?page=forgot_password&token=' . $resetToken;
            
            $emailSent = sendResetEmail($email, $user['name'], $resetLink);
            
            if ($emailSent) {
                $success = 'Link reset password telah dikirim ke email Anda. Silakan cek inbox atau folder spam Anda. Link berlaku selama 1 jam.';
            } else {
                // Fallback: Show the link directly (for development/testing)
                $success = 'Email tidak dapat dikirim. Untuk sementara, gunakan link berikut untuk reset password (berlaku 1 jam):<br><br>
                <a href="' . htmlspecialchars($resetLink) . '" class="btn btn-sm btn-primary" target="_blank">
                    <i class="bi bi-link-45deg me-1"></i>Reset Password
                </a><br><br>
                <small class="text-warning">Catatan: Untuk mengaktifkan pengiriman email, harap konfigurasi SMTP di settings.</small>';
            }
        }
    }
}

/**
 * Send reset password email
 * Uses PHP mail() function or PHPMailer if available
 */
function sendResetEmail($toEmail, $userName, $resetLink) {
    // Check if PHPMailer is available
    $phpMailerPath = __DIR__ . '/../../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    
    if (file_exists($phpMailerPath)) {
        // Use PHPMailer
        require_once $phpMailerPath;
        require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/SMTP.php';
        require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/Exception.php';
        
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Get SMTP settings from database or config
            global $pdo;
            $settings = [];
            try {
                $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
                while ($row = $stmt->fetch()) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                // Settings table may not exist
            }
            
            if (!empty($settings['smtp_host'])) {
                $mail->isSMTP();
                $mail->Host = $settings['smtp_host'];
                $mail->SMTPAuth = true;
                $mail->Username = $settings['smtp_username'] ?? '';
                $mail->Password = $settings['smtp_password'] ?? '';
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = $settings['smtp_port'] ?? 587;
            }
            
            $mail->setFrom($settings['smtp_from_email'] ?? 'noreply@pln.co.id', $settings['smtp_from_name'] ?? 'PLN Inventory System');
            $mail->addAddress($toEmail, $userName);
            
            $mail->isHTML(true);
            $mail->Subject = 'Reset Password - PLN Inventory System';
            $mail->Body = getEmailTemplate($userName, $resetLink);
            $mail->AltBody = "Halo $userName,\n\nAnda telah meminta reset password. Klik link berikut untuk mengatur password baru:\n$resetLink\n\nLink ini akan kadaluarsa dalam 1 jam.\n\nJika Anda tidak meminta reset password, abaikan email ini.";
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('PHPMailer Error: ' . $e->getMessage());
            return false;
        }
    }
    
    // Fallback to PHP mail()
    $subject = 'Reset Password - PLN Inventory System';
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: PLN Inventory <noreply@pln.co.id>\r\n";
    
    $body = getEmailTemplate($userName, $resetLink);
    
    return @mail($toEmail, $subject, $body, $headers);
}

/**
 * Get email HTML template
 */
function getEmailTemplate($userName, $resetLink) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #0d4f5c, #1a9aaa); padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
            .header img { width: 60px; height: 60px; }
            .header h1 { color: white; margin: 10px 0 0; font-size: 24px; }
            .content { background: #f8f9fa; padding: 30px; border: 1px solid #dee2e6; }
            .btn { display: inline-block; background: #1a9aaa; color: white !important; padding: 12px 30px; text-decoration: none; border-radius: 6px; margin: 20px 0; }
            .footer { background: #f1f3f4; padding: 20px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 8px 8px; }
            .warning { background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 4px; margin-top: 20px; font-size: 13px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <img src="cid:logo" alt="PLN">
                <h1>PLN Inventory System</h1>
            </div>
            <div class="content">
                <p>Halo <strong>' . htmlspecialchars($userName) . '</strong>,</p>
                <p>Anda telah meminta untuk mereset password akun PLN Inventory Anda.</p>
                <p>Klik tombol di bawah ini untuk mengatur password baru:</p>
                <p style="text-align: center;">
                    <a href="' . htmlspecialchars($resetLink) . '" class="btn">Reset Password</a>
                </p>
                <p>Atau salin link berikut ke browser Anda:</p>
                <p style="word-break: break-all; background: #e9ecef; padding: 10px; border-radius: 4px; font-size: 13px;">' . htmlspecialchars($resetLink) . '</p>
                <div class="warning">
                    <strong>⚠️ Perhatian:</strong> Link ini akan kadaluarsa dalam 1 jam. Jika Anda tidak meminta reset password, abaikan email ini.
                </div>
            </div>
            <div class="footer">
                <p>Email ini dikirim secara otomatis oleh sistem. Mohon tidak membalas email ini.</p>
                <p>© ' . date('Y') . ' PLN Inventory System</p>
            </div>
        </div>
    </body>
    </html>';
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
        
        <?php if ($showResetForm): ?>
        <!-- Reset Password Form -->
        <h3><i class="bi bi-key me-2"></i>Reset Password</h3>
        <p class="subtitle">Masukkan password baru Anda</p>
        
        <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i><?= $success ?>
        </div>
        <?php endif; ?>
        
        <?php foreach($errors as $e): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
        </div>
        <?php endforeach; ?>
        
        <form method="POST" action="/index.php?page=forgot_password">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="token" value="<?= htmlspecialchars($validToken) ?>">
            
            <div class="mb-3">
                <label class="form-label">
                    <i class="bi bi-lock me-1"></i>Password Baru
                </label>
                <input name="new_password" type="password" class="form-control" 
                       placeholder="Minimal 6 karakter" required minlength="6">
            </div>
            
            <div class="mb-4">
                <label class="form-label">
                    <i class="bi bi-lock-fill me-1"></i>Konfirmasi Password
                </label>
                <input name="confirm_password" type="password" class="form-control" 
                       placeholder="Ulangi password baru" required minlength="6">
            </div>
            
            <button type="submit" class="btn btn-submit w-100">
                <i class="bi bi-check-lg me-2"></i>Simpan Password Baru
            </button>
        </form>
        
        <?php else: ?>
        <!-- Request Reset Form -->
        <h3><i class="bi bi-key me-2"></i>Lupa Password</h3>
        <p class="subtitle">Masukkan email Anda untuk menerima link reset password</p>
        
        <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i><?= $success ?>
        </div>
        <?php endif; ?>
        
        <?php foreach($errors as $e): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
        </div>
        <?php endforeach; ?>
        
        <?php if (!$success): ?>
        <div class="info-box">
            <p><i class="bi bi-info-circle me-2"></i>Link reset password akan dikirim ke email Anda dan berlaku selama 1 jam.</p>
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
                <i class="bi bi-send me-2"></i>Kirim Link Reset
            </button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
        
        <div class="back-link">
            <a href="/index.php?page=login"><i class="bi bi-arrow-left me-1"></i>Kembali ke Login</a>
        </div>
    </div>
</div>
</body>
</html>
