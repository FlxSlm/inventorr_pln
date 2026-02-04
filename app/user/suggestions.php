<?php
// app/user/suggestions.php
// Halaman untuk karyawan mengajukan usulan material

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'karyawan') {
    header('Location: /index.php?page=login');
    exit;
}

$pageTitle = 'Usulan Material';
$pdo = require __DIR__ . '/../config/database.php';

$success = '';
$error = '';
$userId = $_SESSION['user']['id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    
    if (empty($subject) || empty($message)) {
        $error = 'Mohon isi semua field yang wajib diisi.';
    } else {
        // Handle multiple image upload
        $imageNames = [];
        if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
            $uploadDir = 'C:/XAMPP/htdocs/inventory_pln/public/assets/uploads/suggestions/';
            
            // Create directory if not exists
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $totalFiles = count($_FILES['images']['name']);
            $maxFiles = 5; // Maximum 5 images per suggestion
            
            for ($i = 0; $i < min($totalFiles, $maxFiles); $i++) {
                if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                    $fileType = mime_content_type($_FILES['images']['tmp_name'][$i]);
                    
                    if (!in_array($fileType, $allowedTypes)) {
                        continue; // Skip invalid file types
                    }
                    
                    if ($_FILES['images']['size'][$i] > 5 * 1024 * 1024) {
                        continue; // Skip files over 5MB
                    }
                    
                    $ext = pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION);
                    $imageName = 'sug_' . $userId . '_' . time() . '_' . $i . '.' . $ext;
                    
                    if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $uploadDir . $imageName)) {
                        $imageNames[] = $imageName;
                    }
                }
            }
        }
        
        // Also support single image upload for backward compatibility
        if (empty($imageNames) && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'C:/XAMPP/htdocs/inventory_pln/public/assets/uploads/suggestions/';
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = mime_content_type($_FILES['image']['tmp_name']);
            
            if (in_array($fileType, $allowedTypes) && $_FILES['image']['size'] <= 5 * 1024 * 1024) {
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $imageName = 'sug_' . $userId . '_' . time() . '.' . $ext;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $imageName)) {
                    $imageNames[] = $imageName;
                }
            }
        }
        
        // Store first image in main column, rest in images_json column
        $primaryImage = !empty($imageNames) ? $imageNames[0] : null;
        $additionalImages = count($imageNames) > 1 ? json_encode(array_slice($imageNames, 1)) : null;
        
        if (empty($error)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO material_suggestions (user_id, category_id, subject, message, image, images_json)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $categoryId, $subject, $message, $primaryImage, $additionalImages]);
                $success = 'Usulan material berhasil dikirim! Admin akan segera merespon usulan Anda.';
                
                // Clear form
                $subject = $message = '';
                $categoryId = null;
            } catch (PDOException $e) {
                $error = 'Gagal mengirim usulan. Silakan coba lagi.';
            }
        }
    }
}

// Fetch categories
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

// Fetch user's suggestions
$stmt = $pdo->prepare("
    SELECT s.*, c.name AS category_name, c.color AS category_color,
           u.name AS admin_name
    FROM material_suggestions s
    LEFT JOIN categories c ON c.id = s.category_id
    LEFT JOIN users u ON u.id = s.replied_by
    WHERE s.user_id = ?
    ORDER BY s.created_at DESC
");
$stmt->execute([$userId]);
$suggestions = $stmt->fetchAll();
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-lightbulb-fill"></i> Usulan Material</h3>
        <p>Berikan masukan dan usulan material kepada admin</p>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" style="border-radius: var(--radius);">
    <i class="bi bi-check-circle me-2"></i><?= $success ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" style="border-radius: var(--radius);">
    <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Form Usulan -->
    <div class="col-lg-5">
        <div class="modern-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-pencil-square me-2" style="color: var(--primary-light);"></i>Ajukan Usulan Baru
                </h5>
            </div>
            <div class="card-body" style="padding: 20px;">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label" style="color: var(--text-dark); font-weight: 500;">
                            Kategori Barang
                        </label>
                        <select name="category_id" class="form-select">
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= ($categoryId ?? '') == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: var(--text-muted);">Pilih kategori barang yang ingin Anda usulkan</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" style="color: var(--text-dark); font-weight: 500;">
                            Judul Usulan <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="subject" class="form-control" 
                               value="<?= htmlspecialchars($subject ?? '') ?>"
                               placeholder="Contoh: Penambahan Printer di Ruang Meeting"
                               required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" style="color: var(--text-dark); font-weight: 500;">
                            Detail Usulan <span class="text-danger">*</span>
                        </label>
                        <textarea name="message" class="form-control" rows="4"
                                  placeholder="Jelaskan detail usulan Anda, termasuk alasan mengapa material ini dibutuhkan..."
                                  required><?= htmlspecialchars($message ?? '') ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" style="color: var(--text-dark); font-weight: 500;">
                            <i class="bi bi-images me-1"></i>Foto Barang (Opsional)
                        </label>
                        <input type="file" name="images[]" class="form-control" accept="image/*" multiple id="suggestionImages">
                        <small style="color: var(--text-muted);">Upload hingga 5 foto barang yang diusulkan. Format: JPG, PNG, GIF, WEBP. Maks 5MB per file</small>
                        <div id="imagePreviewContainer" class="d-flex flex-wrap gap-2 mt-2"></div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-2"></i>Kirim Usulan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Daftar Usulan -->
    <div class="col-lg-7">
        <div class="modern-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-list-check me-2" style="color: var(--primary-light);"></i>Riwayat Usulan Saya
                </h5>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if (empty($suggestions)): ?>
                <div class="empty-state" style="padding: 60px 20px;">
                    <div class="empty-state-icon">
                        <i class="bi bi-chat-square-text"></i>
                    </div>
                    <h5 class="empty-state-title">Belum Ada Usulan</h5>
                    <p class="empty-state-text">Anda belum pernah mengirim usulan material.</p>
                </div>
                <?php else: ?>
                <div class="suggestion-list">
                    <?php foreach ($suggestions as $sug): ?>
                    <div class="suggestion-item <?= $sug['status'] === 'replied' ? 'has-reply' : '' ?>" 
                         style="padding: 20px; border-bottom: 1px solid var(--border-color); cursor: pointer;"
                         data-bs-toggle="modal" data-bs-target="#suggestionModal<?= $sug['id'] ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <?php if ($sug['category_name']): ?>
                                <span class="badge" style="background: <?= $sug['category_color'] ?>15; color: <?= $sug['category_color'] ?>; font-weight: 500; margin-bottom: 6px;">
                                    <?= htmlspecialchars($sug['category_name']) ?>
                                </span>
                                <?php endif; ?>
                                <h6 style="margin: 0; color: var(--text-dark); font-weight: 600;">
                                    <?= htmlspecialchars($sug['subject']) ?>
                                </h6>
                            </div>
                            <?php if ($sug['status'] === 'unread'): ?>
                            <span class="status-badge warning">Menunggu</span>
                            <?php elseif ($sug['status'] === 'read'): ?>
                            <span class="status-badge info">Dibaca</span>
                            <?php elseif ($sug['status'] === 'replied'): ?>
                            <span class="status-badge success">Dibalas</span>
                            <?php endif; ?>
                        </div>
                        <p style="color: var(--text-muted); margin: 0 0 8px 0; font-size: 14px;">
                            <?= htmlspecialchars(substr($sug['message'], 0, 100)) ?><?= strlen($sug['message']) > 100 ? '...' : '' ?>
                        </p>
                        <small style="color: var(--text-light);">
                            <i class="bi bi-clock me-1"></i><?= date('d M Y H:i', strtotime($sug['created_at'])) ?>
                            <?php if ($sug['status'] === 'replied'): ?>
                            <span class="ms-2"><i class="bi bi-reply me-1"></i>Dibalas <?= date('d M Y', strtotime($sug['replied_at'])) ?></span>
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modals for viewing suggestions -->
<?php foreach ($suggestions as $sug): ?>
<div class="modal fade" id="suggestionModal<?= $sug['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-lightbulb me-2" style="color: var(--primary-light);"></i>
                    Detail Usulan
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if ($sug['category_name']): ?>
                <span class="badge mb-2" style="background: <?= $sug['category_color'] ?>15; color: <?= $sug['category_color'] ?>;">
                    <?= htmlspecialchars($sug['category_name']) ?>
                </span>
                <?php endif; ?>
                
                <h5 style="color: var(--text-dark); margin-bottom: 16px;"><?= htmlspecialchars($sug['subject']) ?></h5>
                
                <div style="background: var(--bg-main); padding: 16px; border-radius: var(--radius); margin-bottom: 16px;">
                    <small style="color: var(--text-muted); display: block; margin-bottom: 8px;">
                        <i class="bi bi-clock me-1"></i>Dikirim pada <?= date('d M Y H:i', strtotime($sug['created_at'])) ?>
                    </small>
                    <p style="margin: 0; white-space: pre-line;"><?= htmlspecialchars($sug['message']) ?></p>
                    
                    <?php 
                    // Collect all images
                    $allImages = [];
                    if (!empty($sug['image'])) {
                        $allImages[] = $sug['image'];
                    }
                    if (!empty($sug['images_json'])) {
                        $additionalImages = json_decode($sug['images_json'], true);
                        if (is_array($additionalImages)) {
                            $allImages = array_merge($allImages, $additionalImages);
                        }
                    }
                    
                    if (!empty($allImages)): 
                    ?>
                    <div style="margin-top: 12px;">
                        <small style="color: var(--text-muted); display: block; margin-bottom: 8px;">
                            <i class="bi bi-images me-1"></i>Foto Lampiran (<?= count($allImages) ?>):
                        </small>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($allImages as $img): ?>
                            <img src="/public/assets/uploads/suggestions/<?= htmlspecialchars($img) ?>" 
                                 alt="Foto Usulan" 
                                 style="max-width: 150px; max-height: 150px; border-radius: 8px; cursor: pointer; object-fit: cover;"
                                 onclick="window.open(this.src, '_blank')">
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($sug['status'] === 'replied' && $sug['admin_reply']): ?>
                <div style="background: linear-gradient(135deg, var(--primary-light) 0%, var(--accent) 100%); padding: 16px; border-radius: var(--radius); color: #fff;">
                    <div class="d-flex align-items-center mb-2">
                        <div style="width: 32px; height: 32px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 10px;">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <div>
                            <strong>Balasan dari Admin</strong>
                            <br><small style="opacity: 0.8;"><?= $sug['admin_name'] ?? 'Admin' ?> • <?= date('d M Y H:i', strtotime($sug['replied_at'])) ?></small>
                        </div>
                    </div>
                    <p style="margin: 0; white-space: pre-line;"><?= htmlspecialchars($sug['admin_reply']) ?></p>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<style>
.suggestion-item:hover {
    background: var(--bg-main);
}
.suggestion-item.has-reply {
    border-left: 3px solid var(--success);
}
.preview-image {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid var(--border-color);
}
</style>

<script>
document.getElementById('suggestionImages')?.addEventListener('change', function(e) {
    const container = document.getElementById('imagePreviewContainer');
    container.innerHTML = '';
    
    // Store files in a global array for manipulation
    window.suggestionFiles = Array.from(e.target.files).slice(0, 5);
    
    renderPreviews();
    
    if (e.target.files.length > 5) {
        alert('Maksimal 5 gambar. Hanya 5 gambar pertama yang akan diupload.');
    }
});

function renderPreviews() {
    const container = document.getElementById('imagePreviewContainer');
    container.innerHTML = '';
    
    window.suggestionFiles.forEach((file, idx) => {
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const wrapper = document.createElement('div');
                wrapper.style.cssText = 'position: relative; display: inline-block;';
                wrapper.className = 'suggestion-preview-item';
                
                const img = document.createElement('img');
                img.src = e.target.result;
                img.className = 'preview-image';
                img.title = file.name;
                
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-danger btn-sm';
                removeBtn.style.cssText = 'position: absolute; top: -5px; right: -5px; width: 22px; height: 22px; padding: 0; border-radius: 50%; font-size: 10px; display: flex; align-items: center; justify-content: center;';
                removeBtn.innerHTML = '<i class="bi bi-x"></i>';
                removeBtn.onclick = function() { removeSuggestionImage(idx); };
                
                wrapper.appendChild(img);
                wrapper.appendChild(removeBtn);
                container.appendChild(wrapper);
            };
            reader.readAsDataURL(file);
        }
    });
}

function removeSuggestionImage(index) {
    window.suggestionFiles.splice(index, 1);
    
    // Rebuild file input
    const dt = new DataTransfer();
    window.suggestionFiles.forEach(file => dt.items.add(file));
    document.getElementById('suggestionImages').files = dt.files;
    
    renderPreviews();
}
</script>
