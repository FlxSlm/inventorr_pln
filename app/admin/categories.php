<?php
// app/admin/categories.php
// Admin page to manage item categories/tags

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

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
        $color = $_POST['color'] ?? '#0F75BC';
        
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
        $color = $_POST['color'] ?? '#0F75BC';
        
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

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1"><i class="bi bi-tags me-2"></i>Kelola Kategori</h3>
        <p class="text-secondary mb-0">Buat dan kelola kategori/tag untuk barang inventaris</p>
    </div>
    <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
        <i class="bi bi-plus-lg me-1"></i> Tambah Kategori
    </button>
</div>

<?php foreach($errors as $e): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= htmlspecialchars($e) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endforeach; ?>

<?php if($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <?php if (empty($categories)): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-tags text-secondary" style="font-size: 4rem;"></i>
                    <h5 class="mt-3 text-secondary">Belum Ada Kategori</h5>
                    <p class="text-secondary">Mulai dengan menambahkan kategori pertama.</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach($categories as $cat): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between">
                            <div class="d-flex align-items-center mb-3">
                                <div class="category-color-badge me-3" style="background: <?= htmlspecialchars($cat['color']) ?>;">
                                    <i class="bi bi-tag-fill"></i>
                                </div>
                                <div>
                                    <h5 class="card-title mb-0"><?= htmlspecialchars($cat['name']) ?></h5>
                                    <small class="text-muted"><?= $cat['item_count'] ?> barang</small>
                                </div>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#editCategoryModal<?= $cat['id'] ?>">
                                            <i class="bi bi-pencil me-2"></i>Edit
                                        </button>
                                    </li>
                                    <li>
                                        <form method="POST" onsubmit="return confirm('Hapus kategori ini?');">
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
                            <p class="card-text text-muted small"><?= htmlspecialchars($cat['description']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Edit Modal -->
            <div class="modal fade" id="editCategoryModal<?= $cat['id'] ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Kategori</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                <div class="mb-3">
                                    <label class="form-label">Nama Kategori *</label>
                                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($cat['name']) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Deskripsi</label>
                                    <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($cat['description'] ?? '') ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Warna</label>
                                    <input type="color" name="color" class="form-control form-control-color" value="<?= htmlspecialchars($cat['color']) ?>">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                <button type="submit" class="btn btn-primary">Simpan</button>
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
                <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Tambah Kategori</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">Nama Kategori *</label>
                        <input type="text" name="name" class="form-control" placeholder="Contoh: Elektronik, ATK, Furnitur" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Deskripsi</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Deskripsi singkat kategori..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Warna</label>
                        <input type="color" name="color" class="form-control form-control-color" value="#0F75BC">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Tambah Kategori</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.category-color-badge {
    width: 45px;
    height: 45px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.2rem;
}
</style>
