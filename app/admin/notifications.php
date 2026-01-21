<?php
// app/admin/notifications.php
// Admin notification center - only shows material suggestions

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

$pageTitle = 'Notifikasi';
$pdo = require __DIR__ . '/../config/database.php';

// Mark suggestion as read if requested
if (isset($_GET['mark_read'])) {
    $suggestionId = (int)$_GET['mark_read'];
    $pdo->prepare("UPDATE material_suggestions SET status = 'read' WHERE id = ? AND status = 'unread'")->execute([$suggestionId]);
    header('Location: /index.php?page=admin_notifications');
    exit;
}

// Mark all as read
if (isset($_GET['mark_all_read'])) {
    $pdo->prepare("UPDATE material_suggestions SET status = 'read' WHERE status = 'unread'")->execute();
    header('Location: /index.php?page=admin_notifications');
    exit;
}

// Fetch unread suggestions (these are the notifications for admin)
$suggestions = $pdo->query("
    SELECT s.*, u.name AS user_name, u.email AS user_email, c.name AS category_name, c.color AS category_color
    FROM material_suggestions s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN categories c ON c.id = s.category_id
    ORDER BY s.created_at DESC
    LIMIT 50
")->fetchAll();

// Count unread
$unreadCount = $pdo->query("SELECT COUNT(*) FROM material_suggestions WHERE status = 'unread'")->fetchColumn();

// Group by date
$grouped = [];
foreach ($suggestions as $sug) {
    $date = date('Y-m-d', strtotime($sug['created_at']));
    $grouped[$date][] = $sug;
}

function formatDateAdmin($date) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    if ($date === $today) return 'Hari Ini';
    if ($date === $yesterday) return 'Kemarin';
    return date('d F Y', strtotime($date));
}
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h3><i class="bi bi-bell-fill"></i> Notifikasi</h3>
        <p>Pusat notifikasi - Usulan material dari karyawan</p>
    </div>
    <?php if ($unreadCount > 0): ?>
    <div class="page-header-actions">
        <a href="/index.php?page=admin_notifications&mark_all_read=1" class="btn btn-secondary">
            <i class="bi bi-check-all me-1"></i> Tandai Semua Dibaca
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Quick Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <p style="color: var(--text-muted); margin: 0 0 4px 0; font-size: 14px;">Usulan Baru</p>
                    <h3 style="margin: 0; color: var(--text-dark);"><?= $unreadCount ?></h3>
                </div>
                <div style="width: 48px; height: 48px; background: var(--warning-light); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-lightbulb" style="color: var(--warning); font-size: 20px;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <p style="color: var(--text-muted); margin: 0 0 4px 0; font-size: 14px;">Total Usulan</p>
                    <h3 style="margin: 0; color: var(--text-dark);"><?= count($suggestions) ?></h3>
                </div>
                <div style="width: 48px; height: 48px; background: rgba(26, 154, 170, 0.1); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-chat-square-text" style="color: var(--primary-light); font-size: 20px;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <a href="/index.php?page=admin_suggestions" class="modern-card d-block text-decoration-none" style="padding: 20px;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <p style="color: var(--text-muted); margin: 0 0 4px 0; font-size: 14px;">Kelola Usulan</p>
                    <h6 style="margin: 0; color: var(--primary-light);">Lihat & Balas →</h6>
                </div>
                <div style="width: 48px; height: 48px; background: var(--primary-light); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-arrow-right" style="color: #fff; font-size: 20px;"></i>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Notifications List -->
<div class="modern-card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="bi bi-inbox me-2" style="color: var(--primary-light);"></i>Usulan Material Terbaru
        </h5>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($suggestions)): ?>
        <div class="empty-state" style="padding: 60px 20px;">
            <div class="empty-state-icon">
                <i class="bi bi-inbox"></i>
            </div>
            <h5 class="empty-state-title">Tidak Ada Notifikasi</h5>
            <p class="empty-state-text">Belum ada usulan material dari karyawan.</p>
        </div>
        <?php else: ?>
        <?php foreach ($grouped as $date => $notifs): ?>
        <div class="notification-date-group">
            <div style="padding: 12px 24px; background: var(--bg-main); border-bottom: 1px solid var(--border-color);">
                <small style="color: var(--text-muted); font-weight: 600;"><?= formatDateAdmin($date) ?></small>
            </div>
            <?php foreach ($notifs as $sug): ?>
            <a href="/index.php?page=admin_suggestions&view=<?= $sug['id'] ?>" 
               class="notification-item d-block text-decoration-none <?= $sug['status'] === 'unread' ? 'unread' : '' ?>" 
               style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); <?= $sug['status'] === 'unread' ? 'background: rgba(26, 154, 170, 0.03);' : ($sug['status'] === 'read' ? 'background: rgba(0,0,0,0.02);' : '') ?>">
                <div class="d-flex gap-3">
                    <div class="topbar-avatar" style="width: 42px; height: 42px; font-size: 16px; flex-shrink: 0;">
                        <?= strtoupper(substr($sug['user_name'], 0, 1)) ?>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <div>
                                <span style="color: var(--text-dark); font-weight: <?= $sug['status'] === 'unread' ? '600' : '500' ?>;">
                                    <?= htmlspecialchars($sug['user_name']) ?>
                                </span>
                                <?php if ($sug['category_name']): ?>
                                <span class="badge ms-2" style="background: <?= $sug['category_color'] ?>15; color: <?= $sug['category_color'] ?>; font-size: 11px;">
                                    <?= htmlspecialchars($sug['category_name']) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <small style="color: var(--text-light); flex-shrink: 0; margin-left: 12px;">
                                <?= date('H:i', strtotime($sug['created_at'])) ?>
                            </small>
                        </div>
                        <h6 style="margin: 0 0 4px 0; color: var(--text-dark); font-weight: <?= $sug['status'] === 'unread' ? '600' : '500' ?>;">
                            <?= htmlspecialchars($sug['subject']) ?>
                        </h6>
                        <p style="color: var(--text-muted); margin: 0; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?= htmlspecialchars(substr($sug['message'], 0, 80)) ?>...
                        </p>
                        <div class="mt-2">
                            <?php if ($sug['status'] === 'unread'): ?>
                            <span class="status-badge warning">Baru</span>
                            <?php elseif ($sug['status'] === 'read'): ?>
                            <span class="status-badge info">Dibaca</span>
                            <?php elseif ($sug['status'] === 'replied'): ?>
                            <span class="status-badge success">Dibalas</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.notification-item.unread {
    border-left: 3px solid var(--warning);
}
.notification-item:hover {
    background: var(--bg-main) !important;
}
</style>
