<?php
// app/admin/users_list.php - Modern Style
// Admin page: list users, add user, toggle blacklist

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

$pageTitle = 'Kelola User';
$pdo = require __DIR__ . '/../config/database.php';
$meId = (int)$_SESSION['user']['id'];

$errors = [];
$success = '';

// Handle user creation (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = ($_POST['role'] === 'admin') ? 'admin' : 'karyawan';

    if ($name === '' || $email === '' || $password === '') {
        $errors[] = 'Semua field wajib diisi.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'Email sudah terdaftar.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
            $ins->execute([$name, $email, $hash, $role]);
            $success = "User '{$name}' berhasil dibuat.";
        }
    }
}

// Fetch all users
$stmt = $pdo->query('SELECT id, name, email, role, is_blacklisted, created_at FROM users ORDER BY created_at DESC');
$users = $stmt->fetchAll();

// Count stats
$totalUsers = count($users);
$adminCount = 0;
$activeCount = 0;
$blacklistedCount = 0;
foreach ($users as $u) {
    if ($u['role'] === 'admin') $adminCount++;
    if (empty($u['is_blacklisted'])) $activeCount++;
    else $blacklistedCount++;
}
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-people-fill"></i> Kelola User</h3>
        <p>Kelola akun pengguna sistem inventaris</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus me-1"></i> Tambah User
        </button>
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

<!-- Stats -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Total User</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $totalUsers ?></p>
            </div>
            <div class="stat-card-icon primary">
                <i class="bi bi-people"></i>
            </div>
        </div>
    </div>
    <div class="stat-card info" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Admin</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $adminCount ?></p>
            </div>
            <div class="stat-card-icon info">
                <i class="bi bi-shield-check"></i>
            </div>
        </div>
    </div>
    <div class="stat-card success" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Aktif</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $activeCount ?></p>
            </div>
            <div class="stat-card-icon success">
                <i class="bi bi-person-check"></i>
            </div>
        </div>
    </div>
    <div class="stat-card danger" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Blacklist</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $blacklistedCount ?></p>
            </div>
            <div class="stat-card-icon danger">
                <i class="bi bi-person-x"></i>
            </div>
        </div>
    </div>
</div>

<!-- Users Table -->
<div class="table-card">
    <div class="card-header" style="padding: 20px 24px; border-bottom: 1px solid var(--border-color);">
        <h3 class="card-title" style="margin: 0;">
            <i class="bi bi-list-ul"></i> Daftar User
        </h3>
        <div class="topbar-search" style="max-width: 250px;">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Cari user..." id="searchUser">
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="data-table" id="usersTable">
            <thead>
                <tr>
                    <th>User</th>
                    <th class="hide-mobile">Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th class="hide-mobile">Bergabung</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($users)): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state" style="padding: 40px;">
                            <div class="empty-state-icon" style="width: 60px; height: 60px; font-size: 24px;">
                                <i class="bi bi-people"></i>
                            </div>
                            <h5 class="empty-state-title">Belum Ada User</h5>
                            <p class="empty-state-text mb-0">Tambahkan user pertama untuk memulai</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach($users as $u): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="topbar-avatar" style="width: 40px; height: 40px; font-size: 14px; background: <?= $u['role'] === 'admin' ? 'linear-gradient(135deg, var(--warning), #d97706)' : 'linear-gradient(135deg, var(--primary-light), var(--accent))' ?>;">
                                <?= strtoupper(substr($u['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div style="font-weight: 600;">
                                    <?= htmlspecialchars($u['name']) ?>
                                    <?php if($u['id'] === $meId): ?>
                                    <span class="badge bg-secondary" style="font-size: 10px; margin-left: 4px;">Anda</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="hide-mobile"><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <?php if($u['role'] === 'admin'): ?>
                        <span class="badge bg-warning" style="color: #000;">
                            <i class="bi bi-shield-check me-1"></i>Admin
                        </span>
                        <?php else: ?>
                        <span class="badge bg-primary">
                            <i class="bi bi-person me-1"></i>Karyawan
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if(!empty($u['is_blacklisted'])): ?>
                        <span class="status-badge danger">Blacklist</span>
                        <?php else: ?>
                        <span class="status-badge success">Aktif</span>
                        <?php endif; ?>
                    </td>
                    <td class="hide-mobile">
                        <small><?= date('d M Y', strtotime($u['created_at'])) ?></small>
                    </td>
                    <td>
                        <?php if ($u['id'] === $meId): ?>
                        <span style="color: var(--text-light);">—</span>
                        <?php else: ?>
                        <div class="table-actions">
                            <a class="table-action-btn <?= !empty($u['is_blacklisted']) ? 'success' : '' ?>"
                               href="/index.php?page=toggle_blacklist&id=<?= $u['id'] ?>"
                               title="<?= !empty($u['is_blacklisted']) ? 'Aktifkan' : 'Blacklist' ?>"
                               onclick="return confirm('<?= !empty($u['is_blacklisted']) ? 'Aktifkan user ini?' : 'Blacklist user ini? User tidak akan bisa login.' ?>')">
                                <i class="bi bi-<?= !empty($u['is_blacklisted']) ? 'person-check' : 'person-x' ?>"></i>
                            </a>
                            <a class="table-action-btn danger" 
                               href="/index.php?page=admin_delete_user&id=<?= $u['id'] ?>"
                               title="Hapus"
                               onclick="return confirm('Hapus user ini secara permanen? Tindakan ini tidak dapat dibatalkan.')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-person-plus me-2" style="color: var(--primary-light);"></i>Tambah User Baru
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_user">
                    <div class="form-group">
                        <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                        <input name="name" class="form-control" placeholder="Masukkan nama lengkap" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input name="email" type="email" class="form-control" placeholder="contoh@email.com" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-select">
                            <option value="karyawan">Karyawan</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <input name="password" type="password" class="form-control" placeholder="Minimal 6 karakter" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i> Tambah User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Simple search filter
document.getElementById('searchUser')?.addEventListener('keyup', function() {
    const filter = this.value.toLowerCase();
    const rows = document.querySelectorAll('#usersTable tbody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
});
</script>
