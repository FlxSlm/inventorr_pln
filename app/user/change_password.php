<?php
// app/user/change_password.php
// Change Password Page - Available for all logged in users (admin and karyawan)

if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}

$pageTitle = 'Ganti Password';
$pdo = require __DIR__ . '/../config/database.php';

$errors = [];
$success = '';
$userId = (int)$_SESSION['user']['id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($currentPassword)) {
        $errors[] = 'Password saat ini wajib diisi.';
    }
    if (empty($newPassword)) {
        $errors[] = 'Password baru wajib diisi.';
    }
    if (strlen($newPassword) < 6) {
        $errors[] = 'Password baru minimal 6 karakter.';
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'Konfirmasi password tidak cocok.';
    }
    
    // Verify current password
    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($currentPassword, $user['password'])) {
            $errors[] = 'Password saat ini tidak valid.';
        }
    }
    
    // Update password if no errors
    if (empty($errors)) {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([$hashedPassword, $userId]);
        
        $success = 'Password berhasil diubah!';
    }
}
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-shield-lock-fill"></i> Ganti Password</h3>
        <p>Ubah password akun Anda untuk keamanan</p>
    </div>
</div>

<!-- Alerts -->
<?php if($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php foreach($errors as $e): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-circle-fill me-2"></i><?= htmlspecialchars($e) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="table-card">
            <div class="card-header" style="padding: 20px 24px; border-bottom: 1px solid var(--border-color);">
                <h5 class="card-title mb-0">
                    <i class="bi bi-key me-2" style="color: var(--primary-light);"></i>Form Ganti Password
                </h5>
            </div>
            <div class="card-body" style="padding: 24px;">
                <!-- User Info -->
                <div style="padding: 16px; background: var(--bg-main); border-radius: 10px; margin-bottom: 24px;">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width: 50px; height: 50px; background: linear-gradient(135deg, var(--primary-light), var(--accent)); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff; font-weight: 600;">
                            <?= strtoupper(substr($_SESSION['user']['name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div style="font-weight: 600; color: var(--text-dark);"><?= htmlspecialchars($_SESSION['user']['name']) ?></div>
                            <small style="color: var(--text-muted);"><?= htmlspecialchars($_SESSION['user']['email']) ?></small>
                        </div>
                    </div>
                </div>
                
                <form method="POST" id="changePasswordForm">
                    <div class="form-group mb-3">
                        <label class="form-label">Password Saat Ini <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" name="current_password" id="currentPassword" class="form-control" placeholder="Masukkan password saat ini" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('currentPassword', this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <hr style="margin: 20px 0;">
                    
                    <div class="form-group mb-3">
                        <label class="form-label">Password Baru <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key"></i></span>
                            <input type="password" name="new_password" id="newPassword" class="form-control" placeholder="Minimal 6 karakter" required minlength="6">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('newPassword', this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">Password minimal 6 karakter</div>
                    </div>
                    
                    <div class="form-group mb-4">
                        <label class="form-label">Konfirmasi Password Baru <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                            <input type="password" name="confirm_password" id="confirmPassword" class="form-control" placeholder="Ulangi password baru" required minlength="6">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirmPassword', this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div id="passwordMatch" class="form-text"></div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-check-lg me-1"></i> Simpan Password Baru
                        </button>
                        <a href="/index.php" class="btn btn-secondary">
                            <i class="bi bi-x-lg me-1"></i> Batal
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Security Tips -->
        <div class="table-card mt-4">
            <div class="card-body" style="padding: 20px;">
                <h6 style="color: var(--text-dark); margin-bottom: 12px;">
                    <i class="bi bi-shield-check me-2" style="color: var(--success);"></i>Tips Keamanan Password
                </h6>
                <ul style="color: var(--text-muted); font-size: 13px; margin: 0; padding-left: 20px;">
                    <li>Gunakan kombinasi huruf besar, huruf kecil, dan angka</li>
                    <li>Jangan gunakan informasi pribadi seperti nama atau tanggal lahir</li>
                    <li>Jangan gunakan password yang sama dengan akun lain</li>
                    <li>Ganti password secara berkala untuk keamanan</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle password visibility
function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    
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

// Password match validation
document.getElementById('confirmPassword')?.addEventListener('input', function() {
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = this.value;
    const matchText = document.getElementById('passwordMatch');
    
    if (confirmPassword === '') {
        matchText.textContent = '';
        matchText.className = 'form-text';
    } else if (newPassword === confirmPassword) {
        matchText.textContent = '✓ Password cocok';
        matchText.className = 'form-text text-success';
    } else {
        matchText.textContent = '✗ Password tidak cocok';
        matchText.className = 'form-text text-danger';
    }
});

// Form validation before submit
document.getElementById('changePasswordForm')?.addEventListener('submit', function(e) {
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    
    if (newPassword !== confirmPassword) {
        e.preventDefault();
        alert('Konfirmasi password tidak cocok!');
    }
});
</script>
