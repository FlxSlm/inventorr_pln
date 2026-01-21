<?php
// app/user/notifications.php
// Notification center for employees

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'karyawan') {
    header('Location: /index.php?page=login');
    exit;
}

$pageTitle = 'Notifikasi';
$pdo = require __DIR__ . '/../config/database.php';

$userId = $_SESSION['user']['id'];

// Mark as read if requested
if (isset($_GET['mark_read'])) {
    $notifId = (int)$_GET['mark_read'];
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$notifId, $userId]);
    
    // Redirect to remove query param
    header('Location: /index.php?page=user_notifications');
    exit;
}

// Mark all as read
if (isset($_GET['mark_all_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$userId]);
    header('Location: /index.php?page=user_notifications');
    exit;
}

// Fetch notifications
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC
    LIMIT 50
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

// Count unread
$unreadCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadCount->execute([$userId]);
$unreadCount = $unreadCount->fetchColumn();

// Group notifications by date
$grouped = [];
foreach ($notifications as $notif) {
    $date = date('Y-m-d', strtotime($notif['created_at']));
    $grouped[$date][] = $notif;
}

// Icon and color mapping
function getNotifStyle($type) {
    $styles = [
        'loan_approved' => ['icon' => 'bi-check-circle-fill', 'color' => 'var(--success)', 'bg' => 'var(--success-light)'],
        'loan_rejected' => ['icon' => 'bi-x-circle-fill', 'color' => 'var(--danger)', 'bg' => 'var(--danger-light)'],
        'document_requested' => ['icon' => 'bi-file-earmark-arrow-up-fill', 'color' => 'var(--info)', 'bg' => 'rgba(59, 130, 246, 0.1)'],
        'return_approved' => ['icon' => 'bi-box-arrow-in-left', 'color' => 'var(--success)', 'bg' => 'var(--success-light)'],
        'return_rejected' => ['icon' => 'bi-box-arrow-in-left', 'color' => 'var(--danger)', 'bg' => 'var(--danger-light)'],
        'suggestion_reply' => ['icon' => 'bi-chat-square-text-fill', 'color' => 'var(--primary-light)', 'bg' => 'rgba(26, 154, 170, 0.1)'],
        'general' => ['icon' => 'bi-bell-fill', 'color' => 'var(--text-muted)', 'bg' => 'var(--bg-main)']
    ];
    return $styles[$type] ?? $styles['general'];
}

function formatDate($date) {
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
        <p>Pusat notifikasi untuk aktivitas akun Anda</p>
    </div>
    <?php if ($unreadCount > 0): ?>
    <div class="page-header-actions">
        <a href="/index.php?page=user_notifications&mark_all_read=1" class="btn btn-secondary">
            <i class="bi bi-check-all me-1"></i> Tandai Semua Dibaca
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <p style="color: var(--text-muted); margin: 0 0 4px 0; font-size: 14px;">Belum Dibaca</p>
                    <h3 style="margin: 0; color: var(--text-dark);"><?= $unreadCount ?></h3>
                </div>
                <div style="width: 48px; height: 48px; background: var(--warning-light); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-envelope" style="color: var(--warning); font-size: 20px;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <p style="color: var(--text-muted); margin: 0 0 4px 0; font-size: 14px;">Sudah Dibaca</p>
                    <h3 style="margin: 0; color: var(--text-dark);"><?= count($notifications) - $unreadCount ?></h3>
                </div>
                <div style="width: 48px; height: 48px; background: var(--success-light); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-envelope-open" style="color: var(--success); font-size: 20px;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="modern-card" style="padding: 20px;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <p style="color: var(--text-muted); margin: 0 0 4px 0; font-size: 14px;">Total Notifikasi</p>
                    <h3 style="margin: 0; color: var(--text-dark);"><?= count($notifications) ?></h3>
                </div>
                <div style="width: 48px; height: 48px; background: rgba(26, 154, 170, 0.1); border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-bell" style="color: var(--primary-light); font-size: 20px;"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Notifications List -->
<div class="modern-card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="bi bi-list-ul me-2" style="color: var(--primary-light);"></i>Semua Notifikasi
        </h5>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($notifications)): ?>
        <div class="empty-state" style="padding: 60px 20px;">
            <div class="empty-state-icon">
                <i class="bi bi-bell-slash"></i>
            </div>
            <h5 class="empty-state-title">Tidak Ada Notifikasi</h5>
            <p class="empty-state-text">Anda belum memiliki notifikasi.</p>
        </div>
        <?php else: ?>
        <?php foreach ($grouped as $date => $notifs): ?>
        <div class="notification-date-group">
            <div style="padding: 12px 24px; background: var(--bg-main); border-bottom: 1px solid var(--border-color);">
                <small style="color: var(--text-muted); font-weight: 600;"><?= formatDate($date) ?></small>
            </div>
            <?php foreach ($notifs as $notif): 
                $style = getNotifStyle($notif['type']);
            ?>
            <div class="notification-item <?= !$notif['is_read'] ? 'unread' : '' ?>" 
                 style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); <?= !$notif['is_read'] ? 'background: rgba(26, 154, 170, 0.03);' : '' ?>">
                <div class="d-flex gap-3">
                    <div style="width: 42px; height: 42px; background: <?= $style['bg'] ?>; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <i class="<?= $style['icon'] ?>" style="color: <?= $style['color'] ?>; font-size: 18px;"></i>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <h6 style="margin: 0; color: var(--text-dark); font-weight: <?= !$notif['is_read'] ? '600' : '500' ?>;">
                                <?= htmlspecialchars($notif['title']) ?>
                            </h6>
                            <small style="color: var(--text-light); flex-shrink: 0; margin-left: 12px;">
                                <?= date('H:i', strtotime($notif['created_at'])) ?>
                            </small>
                        </div>
                        <p style="color: var(--text-muted); margin: 0; font-size: 14px;">
                            <?= htmlspecialchars($notif['message']) ?>
                        </p>
                        <?php if (!$notif['is_read']): ?>
                        <a href="/index.php?page=user_notifications&mark_read=<?= $notif['id'] ?>" 
                           class="btn btn-sm btn-secondary mt-2">
                            <i class="bi bi-check me-1"></i>Tandai Dibaca
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.notification-item.unread {
    border-left: 3px solid var(--primary-light);
}
.notification-item:hover {
    background: var(--bg-main) !important;
}
</style>
