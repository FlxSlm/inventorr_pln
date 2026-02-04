<?php
// app/admin/suggestions.php
// Admin page to view and reply to material suggestions

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

$pageTitle = 'Usulan Material';
$pdo = require __DIR__ . '/../config/database.php';

$success = $_GET['msg'] ?? '';
$error = '';
$adminId = $_SESSION['user']['id'];
$redirectAfterReply = false;

// Handle reply - Must be done BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reply') {
    $suggestionId = (int)($_POST['suggestion_id'] ?? 0);
    $reply = trim($_POST['reply'] ?? '');
    
    if (empty($reply)) {
        $error = 'Mohon isi balasan terlebih dahulu.';
    } else {
        try {
            // Update suggestion with reply
            $stmt = $pdo->prepare("
                UPDATE material_suggestions 
                SET admin_reply = ?, replied_by = ?, replied_at = NOW(), status = 'replied', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reply, $adminId, $suggestionId]);
            
            // Get suggestion details to create notification
            $stmt = $pdo->prepare("SELECT user_id, subject FROM material_suggestions WHERE id = ?");
            $stmt->execute([$suggestionId]);
            $suggestion = $stmt->fetch();
            
            if ($suggestion) {
                // Create notification for user
                $notifTitle = 'Usulan Anda Telah Dibalas';
                $notifMessage = 'Admin telah membalas usulan Anda: "' . $suggestion['subject'] . '"';
                
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type)
                    VALUES (?, 'suggestion_reply', ?, ?, ?, 'suggestion')
                ");
                $stmt->execute([$suggestion['user_id'], $notifTitle, $notifMessage, $suggestionId]);
            }
            
            // Set success message and redirect flag
            $success = 'Balasan berhasil dikirim!';
            $redirectAfterReply = true;
        } catch (PDOException $e) {
            $error = 'Gagal mengirim balasan. Silakan coba lagi.';
        }
    }
}

// Mark as read when viewing
if (isset($_GET['view'])) {
    $viewId = (int)$_GET['view'];
    $pdo->prepare("UPDATE material_suggestions SET status = 'read' WHERE id = ? AND status = 'unread'")->execute([$viewId]);
}

// Get filter
$filter = $_GET['filter'] ?? 'all';

// Fetch suggestions
$sql = "
    SELECT s.*, c.name AS category_name, c.color AS category_color,
           u.name AS user_name, u.email AS user_email
    FROM material_suggestions s
    LEFT JOIN categories c ON c.id = s.category_id
    JOIN users u ON u.id = s.user_id
";

if ($filter === 'unread') {
    $sql .= " WHERE s.status = 'unread'";
} elseif ($filter === 'replied') {
    $sql .= " WHERE s.status = 'replied'";
}

$sql .= " ORDER BY s.created_at DESC";

$suggestions = $pdo->query($sql)->fetchAll();

// Count by status
$unreadCount = $pdo->query("SELECT COUNT(*) FROM material_suggestions WHERE status = 'unread'")->fetchColumn();
$repliedCount = $pdo->query("SELECT COUNT(*) FROM material_suggestions WHERE status = 'replied'")->fetchColumn();
$totalCount = $pdo->query("SELECT COUNT(*) FROM material_suggestions")->fetchColumn();
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-lightbulb-fill"></i> Usulan Material</h3>
        <p>Lihat dan tanggapi usulan material dari karyawan</p>
    </div>
</div>

<?php 
// If redirect needed after successful reply, do JavaScript redirect
if ($redirectAfterReply): 
?>
<script>
    window.location.href = '/index.php?page=admin_suggestions&msg=<?= urlencode($success) ?>';
</script>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" style="border-radius: var(--radius);">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" style="border-radius: var(--radius);">
    <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card warning" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Belum Dibaca</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $unreadCount ?></p>
            </div>
            <div class="stat-card-icon warning">
                <i class="bi bi-envelope"></i>
            </div>
        </div>
    </div>
    <div class="stat-card success" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Sudah Dibalas</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $repliedCount ?></p>
            </div>
            <div class="stat-card-icon success">
                <i class="bi bi-reply"></i>
            </div>
        </div>
    </div>
    <div class="stat-card" style="padding: 20px;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <p class="stat-card-title" style="margin: 0 0 4px 0;">Total Usulan</p>
                <p class="stat-card-value" style="font-size: 28px; margin: 0;"><?= $totalCount ?></p>
            </div>
            <div class="stat-card-icon primary">
                <i class="bi bi-chat-square-text"></i>
            </div>
        </div>
    </div>
</div>

<!-- Suggestions List -->
<div class="modern-card">
    <div class="card-header" style="padding: 20px 24px; border-bottom: 1px solid var(--border-color);">
        <h3 class="card-title" style="margin: 0;">
            <i class="bi bi-inbox"></i> Daftar Usulan
        </h3>
        <div class="card-actions">
            <div class="table-filters" style="padding: 0;">
                <a href="/index.php?page=admin_suggestions" class="table-filter-btn <?= $filter === 'all' ? 'active' : '' ?>">Semua</a>
                <a href="/index.php?page=admin_suggestions&filter=unread" class="table-filter-btn <?= $filter === 'unread' ? 'active' : '' ?>">
                    Belum Dibaca <?= $unreadCount > 0 ? '('.$unreadCount.')' : '' ?>
                </a>
                <a href="/index.php?page=admin_suggestions&filter=replied" class="table-filter-btn <?= $filter === 'replied' ? 'active' : '' ?>">Sudah Dibalas</a>
            </div>
        </div>
    </div>
    
    <div class="card-body" style="padding: 0;">
        <?php if (empty($suggestions)): ?>
        <div class="empty-state" style="padding: 60px 20px;">
            <div class="empty-state-icon">
                <i class="bi bi-inbox"></i>
            </div>
            <h5 class="empty-state-title">Tidak Ada Usulan</h5>
            <p class="empty-state-text">Belum ada usulan material dari karyawan.</p>
        </div>
        <?php else: ?>
        <div class="suggestion-list">
            <?php foreach ($suggestions as $sug): ?>
            <div class="suggestion-item <?= $sug['status'] === 'unread' ? 'unread' : '' ?> <?= $sug['status'] === 'replied' ? 'replied' : '' ?>" 
                 style="padding: 20px 24px; border-bottom: 1px solid var(--border-color); cursor: pointer; <?= $sug['status'] === 'unread' ? 'background: rgba(26, 154, 170, 0.05);' : '' ?>"
                 data-bs-toggle="modal" data-bs-target="#suggestionModal<?= $sug['id'] ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex gap-3" style="flex: 1;">
                        <div class="topbar-avatar" style="width: 42px; height: 42px; font-size: 16px; flex-shrink: 0;">
                            <?= strtoupper(substr($sug['user_name'], 0, 1)) ?>
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <strong style="color: var(--text-dark);"><?= htmlspecialchars($sug['user_name']) ?></strong>
                                <?php if ($sug['category_name']): ?>
                                <span class="badge" style="background: <?= $sug['category_color'] ?>15; color: <?= $sug['category_color'] ?>; font-size: 11px;">
                                    <?= htmlspecialchars($sug['category_name']) ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($sug['status'] === 'unread'): ?>
                                <span class="badge bg-warning text-dark" style="font-size: 10px;">Baru</span>
                                <?php endif; ?>
                            </div>
                            <h6 style="margin: 0 0 6px 0; color: var(--text-dark); font-weight: <?= $sug['status'] === 'unread' ? '600' : '500' ?>;">
                                <?= htmlspecialchars($sug['subject']) ?>
                            </h6>
                            <p style="color: var(--text-muted); margin: 0; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?= htmlspecialchars(substr($sug['message'], 0, 100)) ?>
                            </p>
                        </div>
                    </div>
                    <div class="text-end" style="flex-shrink: 0; margin-left: 16px;">
                        <small style="color: var(--text-muted);"><?= date('d M Y', strtotime($sug['created_at'])) ?></small>
                        <br>
                        <small style="color: var(--text-light);"><?= date('H:i', strtotime($sug['created_at'])) ?></small>
                        <br>
                        <?php if ($sug['status'] === 'replied'): ?>
                        <span class="status-badge success" style="margin-top: 4px;">Dibalas</span>
                        <?php elseif ($sug['status'] === 'read'): ?>
                        <span class="status-badge info" style="margin-top: 4px;">Dibaca</span>
                        <?php else: ?>
                        <span class="status-badge warning" style="margin-top: 4px;">Baru</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modals -->
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
                <!-- Sender Info -->
                <div class="d-flex align-items-center gap-3 mb-4" style="padding-bottom: 16px; border-bottom: 1px solid var(--border-color);">
                    <div class="topbar-avatar" style="width: 48px; height: 48px; font-size: 18px;">
                        <?= strtoupper(substr($sug['user_name'], 0, 1)) ?>
                    </div>
                    <div>
                        <strong style="color: var(--text-dark);"><?= htmlspecialchars($sug['user_name']) ?></strong>
                        <br><small style="color: var(--text-muted);"><?= htmlspecialchars($sug['user_email']) ?></small>
                    </div>
                    <div class="ms-auto text-end">
                        <small style="color: var(--text-muted);"><?= date('d M Y H:i', strtotime($sug['created_at'])) ?></small>
                        <?php if ($sug['category_name']): ?>
                        <br><span class="badge mt-1" style="background: <?= $sug['category_color'] ?>15; color: <?= $sug['category_color'] ?>;">
                            <?= htmlspecialchars($sug['category_name']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Subject & Message -->
                <h5 style="color: var(--text-dark); margin-bottom: 16px;"><?= htmlspecialchars($sug['subject']) ?></h5>
                
                <div style="background: var(--bg-main); padding: 16px; border-radius: var(--radius); margin-bottom: 20px; border-left: 4px solid var(--primary-light);">
                    <p style="margin: 0; white-space: pre-line;"><?= htmlspecialchars($sug['message']) ?></p>
                    
                    <?php if (!empty($sug['image'])): ?>
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-color);">
                        <small style="color: var(--text-muted); display: block; margin-bottom: 8px;">
                            <i class="bi bi-image me-1"></i>Foto Barang yang Diusulkan:
                        </small>
                        <img src="/public/assets/uploads/suggestions/<?= htmlspecialchars($sug['image']) ?>" 
                             alt="Foto Usulan" 
                             style="max-width: 100%; max-height: 300px; border-radius: 8px; cursor: pointer;"
                             onclick="window.open(this.src, '_blank')">
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($sug['status'] === 'replied' && $sug['admin_reply']): ?>
                <!-- Existing Reply -->
                <div style="background: linear-gradient(135deg, var(--success) 0%, #059669 100%); padding: 16px; border-radius: var(--radius); color: #fff;">
                    <div class="d-flex align-items-center mb-2">
                        <i class="bi bi-reply-fill me-2"></i>
                        <strong>Balasan Anda</strong>
                        <small class="ms-auto" style="opacity: 0.8;"><?= date('d M Y H:i', strtotime($sug['replied_at'])) ?></small>
                    </div>
                    <p style="margin: 0; white-space: pre-line;"><?= htmlspecialchars($sug['admin_reply']) ?></p>
                </div>
                <?php else: ?>
                <!-- Reply Form -->
                <form method="POST">
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="suggestion_id" value="<?= $sug['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label" style="color: var(--text-dark); font-weight: 500;">
                            <i class="bi bi-reply me-1"></i>Balas Usulan
                        </label>
                        <textarea name="reply" class="form-control" rows="4" 
                                  placeholder="Tulis balasan Anda untuk karyawan..." required></textarea>
                    </div>
                    
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-2"></i>Kirim Balasan
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<style>
.suggestion-item:hover {
    background: var(--bg-main) !important;
}
.suggestion-item.unread {
    font-weight: 500;
}
.suggestion-item.replied {
    border-left: 3px solid var(--success);
}
</style>
