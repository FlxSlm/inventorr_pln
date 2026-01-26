<?php
// app/admin/templates.php - Document Templates Management
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';

$errors = [];
$success = '';

// Handle uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload' && isset($_FILES['template_file'])) {
        $templateType = $_POST['template_type'] ?? '';
        $templateName = trim($_POST['template_name'] ?? '');
        $file = $_FILES['template_file'];
        
        $allowedTypes = ['application/pdf', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
        $allowedExts = ['pdf', 'xlsx', 'xls'];
        
        if (empty($templateType) || empty($templateName)) {
            $errors[] = 'Tipe dan nama template harus diisi.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Gagal upload file.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts)) {
                $errors[] = 'Format file tidak valid. Hanya PDF, XLSX, XLS.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Ukuran file maksimal 5MB.';
            } else {
                $uploadDir = __DIR__ . '/../../public/assets/uploads/templates/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $filename = 'template_' . $templateType . '_' . time() . '.' . $ext;
                $targetPath = $uploadDir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    // Deactivate old templates of same type
                    $stmt = $pdo->prepare('UPDATE document_templates SET is_active = 0 WHERE template_type = ?');
                    $stmt->execute([$templateType]);
                    
                    // Insert new template
                    $stmt = $pdo->prepare('INSERT INTO document_templates (template_type, template_name, file_path, is_active, uploaded_at) VALUES (?, ?, ?, 1, NOW())');
                    $stmt->execute([$templateType, $templateName, 'uploads/templates/' . $filename]);
                    
                    $success = 'Template berhasil diupload.';
                } else {
                    $errors[] = 'Gagal menyimpan file.';
                }
            }
        }
    }
    
    elseif ($action === 'activate') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT template_type FROM document_templates WHERE id = ?');
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        
        if ($template) {
            $pdo->prepare('UPDATE document_templates SET is_active = 0 WHERE template_type = ?')->execute([$template['template_type']]);
            $pdo->prepare('UPDATE document_templates SET is_active = 1 WHERE id = ?')->execute([$templateId]);
            $success = 'Template diaktifkan.';
        }
    }
    
    elseif ($action === 'delete') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT file_path FROM document_templates WHERE id = ?');
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        
        if ($template) {
            // Delete file
            $filePath = __DIR__ . '/../../public/assets/' . $template['file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $pdo->prepare('DELETE FROM document_templates WHERE id = ?')->execute([$templateId]);
            $success = 'Template dihapus.';
        }
    }
}

// Fetch templates grouped by type
$stmt = $pdo->query('SELECT * FROM document_templates ORDER BY template_type, uploaded_at DESC');
$allTemplates = $stmt->fetchAll();

$templatesByType = [];
foreach ($allTemplates as $t) {
    $templatesByType[$t['template_type']][] = $t;
}

$typeLabels = [
    'loan' => ['Peminjaman (Loan)', 'clipboard-check', 'primary'],
    'return' => ['Pengembalian (Return)', 'box-arrow-in-left', 'success'],
    'request' => ['Permintaan (Request)', 'cart-check', 'warning']
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">
            <i class="bi bi-file-earmark-text me-2"></i>Template Dokumen
        </h1>
        <p class="text-muted mb-0">Kelola template dokumen untuk peminjaman, pengembalian, dan permintaan</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
        <i class="bi bi-upload me-2"></i>Upload Template
    </button>
</div>

<?php if($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php foreach($errors as $e): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($e) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<!-- Template Cards by Type -->
<div class="row g-4">
    <?php foreach ($typeLabels as $type => $info): ?>
    <div class="col-lg-4 col-md-6">
        <div class="modern-card h-100">
            <div class="card-header d-flex align-items-center gap-3">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--<?= $info[2] ?>), var(--<?= $info[2] ?>-light, var(--<?= $info[2] ?>))); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-<?= $info[1] ?>" style="color: #fff; font-size: 20px;"></i>
                </div>
                <div>
                    <h5 class="card-title mb-1"><?= $info[0] ?></h5>
                    <small class="text-muted">Template untuk <?= $info[0] ?></small>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($templatesByType[$type])): ?>
                <div class="text-center py-4">
                    <div class="text-muted mb-2"><i class="bi bi-file-earmark-x" style="font-size: 32px;"></i></div>
                    <p class="text-muted mb-0">Belum ada template</p>
                </div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($templatesByType[$type] as $t): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0 <?= $t['is_active'] ? 'border-start border-success border-3 ps-3' : '' ?>">
                        <div>
                            <div class="fw-semibold">
                                <?= htmlspecialchars($t['template_name']) ?>
                                <?php if ($t['is_active']): ?>
                                <span class="badge bg-success ms-2">Aktif</span>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted">
                                <?= strtoupper(pathinfo($t['file_path'], PATHINFO_EXTENSION)) ?> &bull; 
                                <?= date('d M Y', strtotime($t['uploaded_at'])) ?>
                            </small>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <a href="/public/assets/<?= htmlspecialchars($t['file_path']) ?>" class="btn btn-outline-primary" target="_blank" title="Download">
                                <i class="bi bi-download"></i>
                            </a>
                            <?php if (!$t['is_active']): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
                                <button class="btn btn-outline-success" title="Aktifkan"><i class="bi bi-check-lg"></i></button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus template ini?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
                                <button class="btn btn-outline-danger" title="Hapus"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Upload Template Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="upload">
                    
                    <div class="mb-3">
                        <label class="form-label">Tipe Template <span class="text-danger">*</span></label>
                        <select name="template_type" class="form-select" required>
                            <option value="">Pilih Tipe...</option>
                            <option value="loan">Peminjaman (Loan)</option>
                            <option value="return">Pengembalian (Return)</option>
                            <option value="request">Permintaan (Request)</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Nama Template <span class="text-danger">*</span></label>
                        <input type="text" name="template_name" class="form-control" required placeholder="Contoh: Template BAST v2.0">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">File Template <span class="text-danger">*</span></label>
                        <input type="file" name="template_file" class="form-control" required accept=".pdf,.xlsx,.xls">
                        <small class="text-muted">Format: PDF, XLSX, XLS. Maksimal 5MB.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-2"></i>Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.list-group-item { background: transparent; border-bottom: 1px solid var(--border-color); }
.list-group-item:last-child { border-bottom: 0; }
</style>
