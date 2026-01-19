<?php
// app/admin/users_list.php
// Admin page: list users, add user, toggle blacklist
// public/index.php already started session and required auth check

// Ensure only admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

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
        // check unique email
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
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4>Manage Users</h4>
  <a class="btn btn-outline-light" href="/index.php?page=admin_dashboard">Back to Dashboard</a>
</div>

<?php if($success): ?>
  <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php foreach($errors as $e): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<div class="card p-3 mb-4">
  <h5 class="mb-3">Create New User</h5>
  <form method="POST" class="row g-3">
    <input type="hidden" name="action" value="create_user">
    <div class="col-md-4">
      <input name="name" placeholder="Full name" class="form-control" required>
    </div>
    <div class="col-md-4">
      <input name="email" type="email" placeholder="Email" class="form-control" required>
    </div>
    <div class="col-md-2">
      <select name="role" class="form-select">
        <option value="karyawan">Karyawan</option>
        <option value="admin">Admin</option>
      </select>
    </div>
    <div class="col-md-2">
      <input name="password" type="password" placeholder="Password" class="form-control" required>
    </div>
    <div class="col-12">
      <button class="btn btn-success">Create User</button>
    </div>
  </form>
</div>

<div class="card p-3">
  <h5>Users List</h5>
  <table class="table table-striped mt-3">
    <thead>
      <tr><th>#</th><th>Nama</th><th>Email</th><th>Role</th><th>Blacklisted</th><th>Created</th><th>Aksi</th></tr>
    </thead>
    <tbody>
      <?php if(empty($users)): ?>
        <tr><td colspan="7" class="text-center small-muted">No users found</td></tr>
      <?php else: ?>
        <?php foreach($users as $u): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= htmlspecialchars($u['name']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['role']) ?></td>
            <td>
              <?php if(!empty($u['is_blacklisted'])): ?>
                <span class="badge bg-danger">Blacklisted</span>
              <?php else: ?>
                <span class="badge bg-success">Active</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($u['created_at']) ?></td>
            <td>
              <?php if ($u['id'] === $meId): ?>
                <small class="small-muted">You</small>
              <?php else: ?>
                <!-- Toggle blacklist -->
                <a class="btn btn-sm <?= !empty($u['is_blacklisted']) ? 'btn-success' : 'btn-warning' ?>"
                   href="/index.php?page=toggle_blacklist&id=<?= $u['id'] ?>"
                   data-confirm="<?= !empty($u['is_blacklisted']) ? 'Unblock this user?' : 'Blacklist this user? This user will not be able to login.' ?>">
                  <?= !empty($u['is_blacklisted']) ? 'Unblock' : 'Blacklist' ?>
                </a>

                <!-- Delete user -->
                <a class="btn btn-sm btn-danger" href="/index.php?page=admin_delete_user&id=<?= $u['id'] ?>"
                  data-confirm="Delete user permanently? This cannot be undone.">Delete</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
                