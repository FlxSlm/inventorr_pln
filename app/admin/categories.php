<?php
// app/admin/categories.php
// Admin page to manage item categories/tags - Modern Style

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

$pageTitle = 'Kelola Kategori';
$pdo = require __DIR__ . '/../config/database.php';

$errors = [];
$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Add new category
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $color = $_POST['color'] ?? '#1a9aaa';
        
        if (empty($name)) {
            $errors[] = 'Nama kategori wajib diisi.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO categories (name, description, color) VALUES (?, ?, ?)');
            $stmt->execute([$name, $description, $color]);
            $success = 'Kategori berhasil ditambahkan.';
        }
    }
    
    // Update category
    elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $color = $_POST['color'] ?? '#1a9aaa';
        
        if (empty($name) || !$id) {
            $errors[] = 'Data tidak valid.';
        } else {
            $stmt = $pdo->prepare('UPDATE categories SET name = ?, description = ?, color = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$name, $description, $color, $id]);
            $success = 'Kategori berhasil diperbarui.';
        }
    }
    
    // Delete category
    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
            $stmt->execute([$id]);
            $success = 'Kategori berhasil dihapus.';
        }
    }
}

// Fetch all categories with item count
$categories = $pdo->query("
    SELECT c.*, COUNT(ic.inventory_id) as item_count
    FROM categories c
    LEFT JOIN inventory_categories ic ON ic.category_id = c.id
    GROUP BY c.id
    ORDER BY c.name ASC
")->fetchAll();
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-tags-fill"></i> Kelola Kategori</h3>
        <p>Buat dan kelola kategori/tag untuk barang inventaris</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
            <i class="bi bi-plus-lg me-1"></i> Tambah Kategori
        </button>
    </div>
</div>

<!-- Alerts -->
<?php foreach($errors as $e): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-circle-fill me-2"></i>
    <?= htmlspecialchars($e) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<?php if($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle-fill me-2"></i>
    <?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Categories Grid -->
<div class="row g-4">
    <?php if (empty($categories)): ?>
    <div class="col-12">
        <div class="modern-card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-tags"></i>
                    </div>
                    <h5 class="empty-state-title">Belum Ada Kategori</h5>
                    <p class="empty-state-text">Mulai dengan menambahkan kategori pertama untuk mengorganisir inventaris Anda.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="bi bi-plus-lg me-1"></i> Tambah Kategori Pertama
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <?php foreach($categories as $cat): ?>
    <div class="col-md-6 col-lg-4">
        <div class="category-card">
            <div class="d-flex align-items-start justify-content-between mb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="category-color-badge" style="background: <?= htmlspecialchars($cat['color']) ?>;">
                        <i class="bi bi-tag-fill"></i>
                    </div>
                    <div>
                        <h5 style="margin: 0 0 4px 0; font-weight: 600; color: var(--text-dark);">
                            <?= htmlspecialchars($cat['name']) ?>
                        </h5>
                        <span class="badge bg-secondary"><?= $cat['item_count'] ?> barang</span>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="card-menu-btn" data-bs-toggle="dropdown">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#editCategoryModal<?= $cat['id'] ?>">
                                <i class="bi bi-pencil me-2"></i>Edit
                            </button>
                        </li>
                        <li>
                            <form method="POST" onsubmit="return confirm('Hapus kategori ini? Barang dengan kategori ini tidak akan dihapus.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="bi bi-trash me-2"></i>Hapus
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
            <?php if ($cat['description']): ?>
            <p style="color: var(--text-muted); font-size: 14px; margin: 0;">
                <?= htmlspecialchars($cat['description']) ?>
            </p>
            <?php else: ?>
            <p style="color: var(--text-light); font-size: 14px; margin: 0; font-style: italic;">
                Tidak ada deskripsi
            </p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Edit Modal -->
    <div class="modal fade" id="editCategoryModal<?= $cat['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2" style="color: var(--primary-light);"></i>Edit Kategori</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                        <div class="form-group">
                            <label class="form-label">Nama Kategori <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($cat['name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Deskripsi</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Deskripsi singkat kategori..."><?= htmlspecialchars($cat['description'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">Warna Label</label>
                            <div class="d-flex align-items-center gap-3">
                                <input type="color" name="color" class="form-control form-control-color" value="<?= htmlspecialchars($cat['color']) ?>" style="width: 60px; height: 42px;">
                                <span class="text-muted" style="font-size: 13px;">Pilih warna untuk identifikasi kategori</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i> Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-lg me-2" style="color: var(--primary-light);"></i>Tambah Kategori Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label class="form-label">Nama Kategori <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Contoh: Elektronik, ATK, Furnitur" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Deskripsi</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Deskripsi singkat kategori..."></textarea>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">Warna Label</label>
                        <div class="d-flex align-items-center gap-3">
                            <input type="color" name="color" class="form-control form-control-color" value="#1a9aaa" style="width: 60px; height: 42px;">
                            <span class="text-muted" style="font-size: 13px;">Pilih warna untuk identifikasi kategori</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i> Tambah Kategori
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
