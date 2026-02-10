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

// Fetch transaction history for each user - grouped by transaction
$userHistory = [];
foreach ($users as $u) {
    $uid = $u['id'];
    
    // Loans - grouped by transaction
    $loansStmt = $pdo->prepare("
        SELECT 
            COALESCE(l.group_id, CONCAT('single_', l.id)) AS transaction_id,
            MIN(l.id) as first_loan_id,
            COUNT(*) as item_count,
            SUM(l.quantity) as total_quantity,
            GROUP_CONCAT(DISTINCT i.name SEPARATOR ', ') as item_names,
            MIN(l.requested_at) as requested_at,
            MAX(l.stage) as stage,
            MAX(l.return_stage) as return_stage,
            MAX(l.note) as note,
            MAX(l.rejection_note) as rejection_note,
            MAX(l.return_note) as return_note,
            MAX(l.approved_at) as approved_at,
            MAX(l.returned_at) as returned_at,
            MAX(ua.name) as approved_by_name,
            MAX(ur.name) as rejected_by_name,
            MAX(ura.name) as return_approved_by_name
        FROM loans l
        JOIN inventories i ON i.id = l.inventory_id
        LEFT JOIN users ua ON ua.id = l.approved_by
        LEFT JOIN users ur ON ur.id = l.rejected_by
        LEFT JOIN users ura ON ura.id = l.return_approved_by
        WHERE l.user_id = ?
        GROUP BY COALESCE(l.group_id, CONCAT('single_', l.id))
        ORDER BY MIN(l.requested_at) DESC
        LIMIT 10
    ");
    $loansStmt->execute([$uid]);
    $userHistory[$uid]['loans'] = $loansStmt->fetchAll();
    
    // Requests - grouped by transaction
    $requestsStmt = $pdo->prepare("
        SELECT 
            COALESCE(r.group_id, CONCAT('single_', r.id)) AS transaction_id,
            MIN(r.id) as first_request_id,
            COUNT(*) as item_count,
            SUM(r.quantity) as total_quantity,
            GROUP_CONCAT(DISTINCT i.name SEPARATOR ', ') as item_names,
            MIN(r.requested_at) as requested_at,
            MAX(r.stage) as stage,
            MAX(r.note) as note,
            MAX(r.rejection_note) as rejection_note,
            MAX(r.approved_at) as approved_at,
            MAX(ua.name) as approved_by_name,
            MAX(ur.name) as rejected_by_name
        FROM requests r
        JOIN inventories i ON i.id = r.inventory_id
        LEFT JOIN users ua ON ua.id = r.approved_by
        LEFT JOIN users ur ON ur.id = r.rejected_by
        WHERE r.user_id = ?
        GROUP BY COALESCE(r.group_id, CONCAT('single_', r.id))
        ORDER BY MIN(r.requested_at) DESC
        LIMIT 10
    ");
    $requestsStmt->execute([$uid]);
    $userHistory[$uid]['requests'] = $requestsStmt->fetchAll();
    
    // Suggestions
    $suggestionsStmt = $pdo->prepare("
        SELECT s.*, c.name AS category_name, c.color AS category_color
        FROM material_suggestions s
        LEFT JOIN categories c ON c.id = s.category_id
        WHERE s.user_id = ?
        ORDER BY s.created_at DESC
        LIMIT 10
    ");
    $suggestionsStmt->execute([$uid]);
    $userHistory[$uid]['suggestions'] = $suggestionsStmt->fetchAll();
    
    // Count totals - count by transaction (group_id), not individual items
    $countLoans = $pdo->prepare("SELECT COUNT(DISTINCT COALESCE(group_id, CONCAT('single_', id))) FROM loans WHERE user_id = ?");
    $countLoans->execute([$uid]);
    $userHistory[$uid]['total_loans'] = $countLoans->fetchColumn();
    
    $countRequests = $pdo->prepare("SELECT COUNT(DISTINCT COALESCE(group_id, CONCAT('single_', id))) FROM requests WHERE user_id = ?");
    $countRequests->execute([$uid]);
    $userHistory[$uid]['total_requests'] = $countRequests->fetchColumn();
    
    $countSuggestions = $pdo->prepare("SELECT COUNT(*) FROM material_suggestions WHERE user_id = ?");
    $countSuggestions->execute([$uid]);
    $userHistory[$uid]['total_suggestions'] = $countSuggestions->fetchColumn();
}

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
<?php if(!empty($_GET['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-circle-fill me-2"></i><?= htmlspecialchars($_GET['error']) ?>
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
                <tr style="cursor: pointer;" onclick="openUserHistory(<?= $u['id'] ?>)" class="user-row">
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
                                <small style="color: var(--text-muted); font-size: 11px;">
                                    <i class="bi bi-clipboard me-1"></i><?= $userHistory[$u['id']]['total_loans'] ?> peminjaman
                                    <i class="bi bi-cart ms-2 me-1"></i><?= $userHistory[$u['id']]['total_requests'] ?> permintaan
                                </small>
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

// Open user history modal
function openUserHistory(userId) {
    event.stopPropagation();
    const modal = new bootstrap.Modal(document.getElementById('historyModal' + userId));
    modal.show();
}
</script>

<!-- User History Modals -->
<?php foreach($users as $u): 
    $history = $userHistory[$u['id']];
    $stageLabels = [
        'pending' => ['Menunggu', 'warning'],
        'approved' => ['Disetujui', 'success'],
        'rejected' => ['Ditolak', 'danger'],
        'submitted' => ['Menunggu Verifikasi', 'info'],
        'awaiting_document' => ['Upload Dokumen', 'info']
    ];
    $returnStageLabels = [
        'none' => ['Belum Dikembalikan', 'secondary'],
        'pending_return' => ['Menunggu', 'warning'],
        'return_approved' => ['Dikembalikan', 'success'],
        'return_rejected' => ['Ditolak', 'danger']
    ];
?>
<div class="modal fade" id="historyModal<?= $u['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border: none; border-radius: 16px; overflow: hidden;">
            <!-- Header -->
            <div class="modal-header" style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); border: none; padding: 24px 28px;">
                <div class="d-flex align-items-center gap-3">
                    <div style="width: 56px; height: 56px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #fff; font-weight: 600;">
                        <?= strtoupper(substr($u['name'], 0, 1)) ?>
                    </div>
                    <div>
                        <h5 class="modal-title" style="color: #fff; font-weight: 600; margin: 0;"><?= htmlspecialchars($u['name']) ?></h5>
                        <small style="color: rgba(255,255,255,0.8);"><?= htmlspecialchars($u['email']) ?></small>
                        <div style="margin-top: 4px;">
                            <span class="badge" style="background: rgba(255,255,255,0.2);">
                                <?= $u['role'] === 'admin' ? 'Admin' : 'Karyawan' ?>
                            </span>
                            <span class="badge" style="background: <?= empty($u['is_blacklisted']) ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)' ?>;">
                                <?= empty($u['is_blacklisted']) ? 'Aktif' : 'Blacklist' ?>
                            </span>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body" style="padding: 0;">
                <!-- Summary Stats -->
                <div style="padding: 20px 28px; background: var(--bg-main); border-bottom: 1px solid var(--border-color);">
                    <div class="row g-3">
                        <div class="col-4">
                            <div style="text-align: center; padding: 12px; background: var(--bg-card); border-radius: 10px;">
                                <div style="font-size: 24px; font-weight: 700; color: var(--primary-light);"><?= $history['total_loans'] ?></div>
                                <div style="font-size: 12px; color: var(--text-muted);">Peminjaman</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div style="text-align: center; padding: 12px; background: var(--bg-card); border-radius: 10px;">
                                <div style="font-size: 24px; font-weight: 700; color: var(--success);"><?= $history['total_requests'] ?></div>
                                <div style="font-size: 12px; color: var(--text-muted);">Permintaan</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div style="text-align: center; padding: 12px; background: var(--bg-card); border-radius: 10px;">
                                <div style="font-size: 24px; font-weight: 700; color: var(--warning);"><?= $history['total_suggestions'] ?></div>
                                <div style="font-size: 12px; color: var(--text-muted);">Usulan Material</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tabs -->
                <ul class="nav nav-tabs" style="padding: 0 28px; background: var(--bg-card); border-bottom: 1px solid var(--border-color);">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#loans<?= $u['id'] ?>">
                            <i class="bi bi-clipboard me-1"></i>Peminjaman
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#requests<?= $u['id'] ?>">
                            <i class="bi bi-cart me-1"></i>Permintaan
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#suggestions<?= $u['id'] ?>">
                            <i class="bi bi-lightbulb me-1"></i>Usulan Material
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" style="padding: 20px 28px; max-height: 400px; overflow-y: auto;">
                    <!-- Loans Tab -->
                    <div class="tab-pane fade show active" id="loans<?= $u['id'] ?>">
                        <?php if(empty($history['loans'])): ?>
                        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="bi bi-inbox" style="font-size: 32px;"></i>
                            <p style="margin: 10px 0 0 0;">Belum ada riwayat peminjaman</p>
                        </div>
                        <?php else: ?>
                        <?php $loanNum = 1; foreach($history['loans'] as $loan): 
                            $stage = $loan['stage'] ?? 'pending';
                            $stageInfo = $stageLabels[$stage] ?? ['Unknown', 'secondary'];
                            $returnStage = $loan['return_stage'] ?? 'none';
                            $returnInfo = $returnStageLabels[$returnStage] ?? ['N/A', 'secondary'];
                            // Truncate item names if too long
                            $itemNames = $loan['item_names'];
                            if (strlen($itemNames) > 50) {
                                $itemNames = substr($itemNames, 0, 47) . '...';
                            }
                        ?>
                        <div style="padding: 14px; border: 1px solid var(--border-color); border-radius: 10px; margin-bottom: 12px; background: var(--bg-card);">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="badge bg-secondary"><?= $loanNum++ ?></span>
                                        <span style="font-weight: 600; color: var(--text-dark);" title="<?= htmlspecialchars($loan['item_names']) ?>"><?= htmlspecialchars($itemNames) ?></span>
                                    </div>
                                    <small style="color: var(--text-muted);">
                                        <?php if($loan['item_count'] > 1): ?>
                                        <span class="badge bg-info me-1"><?= $loan['item_count'] ?> item</span>
                                        <?php endif; ?>
                                        Total: <?= $loan['total_quantity'] ?> unit
                                    </small>
                                </div>
                                <div style="text-align: right;">
                                    <span class="badge bg-<?= $stageInfo[1] ?>"><?= $stageInfo[0] ?></span>
                                    <?php if($stage === 'approved' && !empty($loan['approved_by_name'])): ?>
                                    <br><small class="text-muted" style="font-size: 11px;">oleh <?= htmlspecialchars($loan['approved_by_name']) ?></small>
                                    <?php elseif($stage === 'rejected' && !empty($loan['rejected_by_name'])): ?>
                                    <br><small class="text-muted" style="font-size: 11px;">oleh <?= htmlspecialchars($loan['rejected_by_name']) ?></small>
                                    <?php endif; ?>
                                    <?php if($stage === 'approved'): ?>
                                    <br><small class="badge bg-<?= $returnInfo[1] ?>" style="margin-top: 4px;"><?= $returnInfo[0] ?></small>
                                    <?php if($returnStage === 'return_approved' && !empty($loan['return_approved_by_name'])): ?>
                                    <br><small class="text-muted" style="font-size: 11px;">oleh <?= htmlspecialchars($loan['return_approved_by_name']) ?></small>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="margin-top: 10px; font-size: 12px; color: var(--text-muted);">
                                <i class="bi bi-calendar me-1"></i>Diajukan: <?= date('d M Y H:i', strtotime($loan['requested_at'])) ?>
                                <?php if(!empty($loan['approved_at'])): ?>
                                <br><i class="bi bi-check2-circle me-1"></i>Disetujui: <?= date('d M Y H:i', strtotime($loan['approved_at'])) ?>
                                <?php endif; ?>
                                <?php if(!empty($loan['returned_at'])): ?>
                                <br><i class="bi bi-arrow-return-left me-1"></i>Dikembalikan: <?= date('d M Y H:i', strtotime($loan['returned_at'])) ?>
                                <?php endif; ?>
                            </div>
                            <?php if(!empty($loan['note'])): ?>
                            <div style="margin-top: 10px; padding: 10px; background: var(--bg-main); border-radius: 6px; font-size: 13px;">
                                <strong style="color: var(--text-dark);"><i class="bi bi-chat-left-text me-1"></i>Catatan Karyawan:</strong>
                                <p style="margin: 5px 0 0 0; color: var(--text-muted);"><?= nl2br(htmlspecialchars($loan['note'])) ?></p>
                            </div>
                            <?php endif; ?>
                            <?php if(!empty($loan['rejection_note'])): ?>
                            <div style="margin-top: 10px; padding: 10px; background: rgba(239,68,68,0.1); border-radius: 6px; border-left: 3px solid var(--danger); font-size: 13px;">
                                <strong style="color: var(--danger);"><i class="bi bi-x-circle me-1"></i>Alasan Penolakan:</strong>
                                <p style="margin: 5px 0 0 0;"><?= nl2br(htmlspecialchars($loan['rejection_note'])) ?></p>
                            </div>
                            <?php endif; ?>
                            <?php if(!empty($loan['return_note'])): ?>
                            <div style="margin-top: 10px; padding: 10px; background: rgba(59,130,246,0.1); border-radius: 6px; border-left: 3px solid var(--info); font-size: 13px;">
                                <strong style="color: var(--info);"><i class="bi bi-arrow-return-left me-1"></i>Catatan Pengembalian:</strong>
                                <p style="margin: 5px 0 0 0;"><?= nl2br(htmlspecialchars($loan['return_note'])) ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Requests Tab -->
                    <div class="tab-pane fade" id="requests<?= $u['id'] ?>">
                        <?php if(empty($history['requests'])): ?>
                        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="bi bi-inbox" style="font-size: 32px;"></i>
                            <p style="margin: 10px 0 0 0;">Belum ada riwayat permintaan</p>
                        </div>
                        <?php else: ?>
                        <?php $reqNum = 1; foreach($history['requests'] as $req): 
                            $stage = $req['stage'] ?? 'pending';
                            $stageInfo = $stageLabels[$stage] ?? ['Unknown', 'secondary'];
                            // Truncate item names if too long
                            $itemNames = $req['item_names'];
                            if (strlen($itemNames) > 50) {
                                $itemNames = substr($itemNames, 0, 47) . '...';
                            }
                        ?>
                        <div style="padding: 14px; border: 1px solid var(--border-color); border-radius: 10px; margin-bottom: 12px; background: var(--bg-card);">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="badge bg-secondary"><?= $reqNum++ ?></span>
                                        <span style="font-weight: 600; color: var(--text-dark);" title="<?= htmlspecialchars($req['item_names']) ?>"><?= htmlspecialchars($itemNames) ?></span>
                                    </div>
                                    <small style="color: var(--text-muted);">
                                        <?php if($req['item_count'] > 1): ?>
                                        <span class="badge bg-info me-1"><?= $req['item_count'] ?> item</span>
                                        <?php endif; ?>
                                        Total: <?= $req['total_quantity'] ?> unit
                                    </small>
                                </div>
                                <div style="text-align: right;">
                                    <span class="badge bg-<?= $stageInfo[1] ?>"><?= $stageInfo[0] ?></span>
                                    <?php if($stage === 'approved' && !empty($req['approved_by_name'])): ?>
                                    <br><small class="text-muted" style="font-size: 11px;">oleh <?= htmlspecialchars($req['approved_by_name']) ?></small>
                                    <?php elseif($stage === 'rejected' && !empty($req['rejected_by_name'])): ?>
                                    <br><small class="text-muted" style="font-size: 11px;">oleh <?= htmlspecialchars($req['rejected_by_name']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="margin-top: 10px; font-size: 12px; color: var(--text-muted);">
                                <i class="bi bi-calendar me-1"></i>Diajukan: <?= date('d M Y H:i', strtotime($req['requested_at'])) ?>
                                <?php if(!empty($req['approved_at'])): ?>
                                <br><i class="bi bi-check2-circle me-1"></i>Disetujui: <?= date('d M Y H:i', strtotime($req['approved_at'])) ?>
                                <?php endif; ?>
                            </div>
                            <?php if(!empty($req['note'])): ?>
                            <div style="margin-top: 10px; padding: 10px; background: var(--bg-main); border-radius: 6px; font-size: 13px;">
                                <strong style="color: var(--text-dark);"><i class="bi bi-chat-left-text me-1"></i>Catatan:</strong>
                                <p style="margin: 5px 0 0 0; color: var(--text-muted);"><?= nl2br(htmlspecialchars($req['note'])) ?></p>
                            </div>
                            <?php endif; ?>
                            <?php if(!empty($req['rejection_note'])): ?>
                            <div style="margin-top: 10px; padding: 10px; background: rgba(239,68,68,0.1); border-radius: 6px; border-left: 3px solid var(--danger); font-size: 13px;">
                                <strong style="color: var(--danger);"><i class="bi bi-x-circle me-1"></i>Alasan Penolakan:</strong>
                                <p style="margin: 5px 0 0 0;"><?= nl2br(htmlspecialchars($req['rejection_note'])) ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Suggestions Tab -->
                    <div class="tab-pane fade" id="suggestions<?= $u['id'] ?>">
                        <?php if(empty($history['suggestions'])): ?>
                        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="bi bi-inbox" style="font-size: 32px;"></i>
                            <p style="margin: 10px 0 0 0;">Belum ada usulan material</p>
                        </div>
                        <?php else: ?>
                        <?php foreach($history['suggestions'] as $sug): 
                            $statusLabels = [
                                'pending' => ['Menunggu', 'warning'],
                                'approved' => ['Disetujui', 'success'],
                                'rejected' => ['Ditolak', 'danger']
                            ];
                            $sugStatus = $statusLabels[$sug['status']] ?? ['Unknown', 'secondary'];
                        ?>
                        <div style="padding: 14px; border: 1px solid var(--border-color); border-radius: 10px; margin-bottom: 12px; background: var(--bg-card);">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div style="font-weight: 600; color: var(--text-dark);"><?= htmlspecialchars($sug['subject'] ?? 'N/A') ?></div>
                                    <?php if (!empty($sug['category_name'])): ?>
                                    <small style="color: var(--text-muted);"><span class="badge" style="background-color: <?= $sug['category_color'] ?? '#999' ?>; font-size: 10px;"><?= htmlspecialchars($sug['category_name']) ?></span></small>
                                    <?php endif; ?>
                                </div>
                                <span class="badge bg-<?= $sugStatus[1] ?>"><?= $sugStatus[0] ?></span>
                            </div>
                            <div style="margin-top: 8px; color: var(--text-muted); font-size: 12px;">
                                <?= htmlspecialchars(substr($sug['message'], 0, 100)) ?><?= strlen($sug['message']) > 100 ? '...' : '' ?>
                            </div>
                            <div style="margin-top: 10px; font-size: 12px; color: var(--text-muted);">
                                <i class="bi bi-calendar me-1"></i><?= date('d M Y H:i', strtotime($sug['created_at'])) ?>
                            </div>
                            <?php if(!empty($sug['admin_reply'])): ?>
                            <div style="margin-top: 10px; padding: 10px; background: rgba(16,185,129,0.1); border-radius: 6px; border-left: 3px solid var(--success); font-size: 13px;">
                                <strong style="color: var(--success);"><i class="bi bi-reply me-1"></i>Tanggapan Admin:</strong>
                                <p style="margin: 5px 0 0 0;"><?= nl2br(htmlspecialchars($sug['admin_reply'])) ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer" style="padding: 16px 28px; border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<style>
.user-row:hover { background: var(--bg-main) !important; }
.nav-tabs .nav-link { border: none; color: var(--text-muted); padding: 12px 16px; }
.nav-tabs .nav-link.active { color: var(--primary-light); border-bottom: 2px solid var(--primary-light); background: transparent; }
</style>
