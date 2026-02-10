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

// Mark as read if requested - use JavaScript redirect since headers are already sent
$redirectNeeded = false;
if (isset($_GET['mark_read'])) {
    $notifId = (int)$_GET['mark_read'];
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$notifId, $userId]);
    $redirectNeeded = true;
}

// Mark all as read
if (isset($_GET['mark_all_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$userId]);
    $redirectNeeded = true;
}

// Use JavaScript redirect if needed (since headers are already sent by modern_header.php)
if ($redirectNeeded) {
    echo '<script>window.location.href = "/index.php?page=user_notifications";</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=/index.php?page=user_notifications"></noscript>';
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

// Fetch related data for all notifications
$relatedData = [];
foreach ($notifications as $notif) {
    $refId = $notif['reference_id'] ?? null;
    $refType = $notif['reference_type'] ?? null;
    if (!$refId || !$refType) continue;
    
    $key = $refType . '_' . $refId;
    if (isset($relatedData[$key])) continue; // avoid duplicate queries
    
    if ($refType === 'loan') {
        // Fetch loan details with inventory and admin info
        $stmtRef = $pdo->prepare("
            SELECT l.*, i.name AS item_name, i.code AS item_code, i.unit,
                   ua.name AS approved_by_name, ur.name AS rejected_by_name,
                   ura.name AS return_approved_by_name
            FROM loans l
            JOIN inventories i ON i.id = l.inventory_id
            LEFT JOIN users ua ON ua.id = l.approved_by
            LEFT JOIN users ur ON ur.id = l.rejected_by
            LEFT JOIN users ura ON ura.id = l.return_approved_by
            WHERE l.id = ?
        ");
        $stmtRef->execute([$refId]);
        $data = $stmtRef->fetch();
        if ($data) {
            // Check if part of a group, fetch all items in group
            if (!empty($data['group_id'])) {
                $stmtGroup = $pdo->prepare("
                    SELECT l.quantity, i.name AS item_name, i.code AS item_code, i.unit
                    FROM loans l
                    JOIN inventories i ON i.id = l.inventory_id
                    WHERE l.group_id = ?
                ");
                $stmtGroup->execute([$data['group_id']]);
                $data['group_items'] = $stmtGroup->fetchAll();
            }
            // Fetch generated document if any
            $stmtDoc = $pdo->prepare("
                SELECT file_path, doc_number FROM generated_documents 
                WHERE reference_id = ? AND reference_type = 'loan'
                ORDER BY created_at DESC LIMIT 1
            ");
            try {
                $stmtDoc->execute([$data['group_id'] ?? $refId]);
                $data['document'] = $stmtDoc->fetch();
            } catch (PDOException $e) {
                $data['document'] = null;
            }
            $relatedData[$key] = $data;
        }
    } elseif ($refType === 'request') {
        $stmtRef = $pdo->prepare("
            SELECT r.*, i.name AS item_name, i.code AS item_code, i.unit,
                   ua.name AS approved_by_name, ur.name AS rejected_by_name
            FROM requests r
            JOIN inventories i ON i.id = r.inventory_id
            LEFT JOIN users ua ON ua.id = r.approved_by
            LEFT JOIN users ur ON ur.id = r.rejected_by
            WHERE r.id = ?
        ");
        $stmtRef->execute([$refId]);
        $data = $stmtRef->fetch();
        if ($data) {
            if (!empty($data['group_id'])) {
                $stmtGroup = $pdo->prepare("
                    SELECT r.quantity, i.name AS item_name, i.code AS item_code, i.unit
                    FROM requests r
                    JOIN inventories i ON i.id = r.inventory_id
                    WHERE r.group_id = ?
                ");
                $stmtGroup->execute([$data['group_id']]);
                $data['group_items'] = $stmtGroup->fetchAll();
            }
            $stmtDoc = $pdo->prepare("
                SELECT file_path, doc_number FROM generated_documents 
                WHERE reference_id = ? AND reference_type = 'request'
                ORDER BY created_at DESC LIMIT 1
            ");
            try {
                $stmtDoc->execute([$data['group_id'] ?? $refId]);
                $data['document'] = $stmtDoc->fetch();
            } catch (PDOException $e) {
                $data['document'] = null;
            }
            $relatedData[$key] = $data;
        }
    } elseif ($refType === 'suggestion') {
        $stmtRef = $pdo->prepare("
            SELECT s.*, u.name AS admin_name
            FROM material_suggestions s
            LEFT JOIN users u ON u.id = s.replied_by
            WHERE s.id = ?
        ");
        $stmtRef->execute([$refId]);
        $data = $stmtRef->fetch();
        if ($data) {
            $relatedData[$key] = $data;
        }
    }
}

// Helper to get related data for a notification
function getRelated($notif, $relatedData) {
    $refId = $notif['reference_id'] ?? null;
    $refType = $notif['reference_type'] ?? null;
    if (!$refId || !$refType) return null;
    return $relatedData[$refType . '_' . $refId] ?? null;
}

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
                 style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); cursor: pointer; <?= !$notif['is_read'] ? 'background: rgba(26, 154, 170, 0.03);' : '' ?>"
                 <?php if ($notif['reference_id'] && $notif['reference_type']): ?>
                 data-bs-toggle="modal" data-bs-target="#notifDetailModal<?= $notif['id'] ?>"
                 <?php endif; ?>
                 >
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
                        <div class="d-flex align-items-center gap-2 mt-2">
                            <?php if ($notif['reference_id'] && $notif['reference_type']): ?>
                            <span class="btn btn-sm btn-outline-primary" style="font-size: 12px; padding: 2px 10px;">
                                <i class="bi bi-eye me-1"></i>Lihat Detail
                            </span>
                            <?php endif; ?>
                            <?php if (!$notif['is_read']): ?>
                            <a href="/index.php?page=user_notifications&mark_read=<?= $notif['id'] ?>" 
                               class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 2px 10px;"
                               onclick="event.stopPropagation();">
                                <i class="bi bi-check me-1"></i>Tandai Dibaca
                            </a>
                            <?php endif; ?>
                        </div>
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

<!-- Notification Detail Modals -->
<?php foreach ($notifications as $notif): 
    if (!$notif['reference_id'] || !$notif['reference_type']) continue;
    $related = getRelated($notif, $relatedData);
    if (!$related) continue;
    $mStyle = getNotifStyle($notif['type']);
?>
<div class="modal fade" id="notifDetailModal<?= $notif['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom: 3px solid <?= $mStyle['color'] ?>;">
                <h5 class="modal-title">
                    <i class="<?= $mStyle['icon'] ?> me-2" style="color: <?= $mStyle['color'] ?>;"></i>
                    <?= htmlspecialchars($notif['title']) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Notification Info -->
                <div class="d-flex align-items-center gap-2 mb-3" style="padding-bottom: 12px; border-bottom: 1px solid var(--border-color);">
                    <div style="width: 36px; height: 36px; background: <?= $mStyle['bg'] ?>; border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                        <i class="<?= $mStyle['icon'] ?>" style="color: <?= $mStyle['color'] ?>; font-size: 16px;"></i>
                    </div>
                    <div>
                        <small style="color: var(--text-muted);">
                            <i class="bi bi-clock me-1"></i><?= date('d M Y H:i', strtotime($notif['created_at'])) ?>
                        </small>
                    </div>
                </div>

                <?php if ($notif['reference_type'] === 'loan'): ?>
                <!-- LOAN DETAIL -->
                <div style="background: var(--bg-main); padding: 16px; border-radius: var(--radius); margin-bottom: 16px;">
                    <h6 style="color: var(--text-dark); font-weight: 600; margin-bottom: 12px;">
                        <i class="bi bi-box-seam me-2"></i>Detail Barang
                    </h6>
                    <?php if (!empty($related['group_items'])): ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" style="font-size: 14px;">
                            <thead><tr><th>Barang</th><th>Kode</th><th>Jumlah</th></tr></thead>
                            <tbody>
                            <?php foreach ($related['group_items'] as $gi): ?>
                            <tr>
                                <td><?= htmlspecialchars($gi['item_name']) ?></td>
                                <td><code><?= htmlspecialchars($gi['item_code']) ?></code></td>
                                <td><?= $gi['quantity'] ?> <?= htmlspecialchars($gi['unit'] ?? 'unit') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p style="margin: 0; color: var(--text-dark);">
                        <strong><?= htmlspecialchars($related['item_name']) ?></strong> 
                        (<code><?= htmlspecialchars($related['item_code']) ?></code>) 
                        - <?= $related['quantity'] ?> <?= htmlspecialchars($related['unit'] ?? 'unit') ?>
                    </p>
                    <?php endif; ?>
                </div>

                <!-- Status & Admin Info -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div style="background: var(--bg-main); padding: 12px 16px; border-radius: var(--radius);">
                            <small style="color: var(--text-muted); display: block; margin-bottom: 4px;">Status</small>
                            <?php
                            $statusMap = [
                                'pending' => ['Menunggu', 'warning'],
                                'approved' => ['Disetujui', 'success'],
                                'rejected' => ['Ditolak', 'danger'],
                                'returned' => ['Dikembalikan', 'info']
                            ];
                            $st = $statusMap[$related['status']] ?? ['Unknown', 'secondary'];
                            ?>
                            <span class="badge bg-<?= $st[1] ?>"><?= $st[0] ?></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div style="background: var(--bg-main); padding: 12px 16px; border-radius: var(--radius);">
                            <small style="color: var(--text-muted); display: block; margin-bottom: 4px;">Tanggal Pengajuan</small>
                            <span style="color: var(--text-dark);"><?= date('d M Y H:i', strtotime($related['requested_at'])) ?></span>
                        </div>
                    </div>
                </div>

                <?php if (!empty($related['note'])): ?>
                <div style="background: var(--bg-main); padding: 12px 16px; border-radius: var(--radius); margin-bottom: 12px;">
                    <small style="color: var(--text-muted); display: block; margin-bottom: 4px;">
                        <i class="bi bi-chat-text me-1"></i>Catatan Anda
                    </small>
                    <p style="margin: 0; color: var(--text-dark);"><?= htmlspecialchars($related['note']) ?></p>
                </div>
                <?php endif; ?>

                <?php if ($related['status'] === 'approved' && !empty($related['approved_by_name'])): ?>
                <div style="background: linear-gradient(135deg, var(--success) 0%, #059669 100%); padding: 16px; border-radius: var(--radius); color: #fff; margin-bottom: 12px;">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div style="width: 32px; height: 32px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-person-check"></i>
                        </div>
                        <div>
                            <strong>Disetujui oleh <?= htmlspecialchars($related['approved_by_name']) ?></strong>
                            <?php if (!empty($related['approved_at'])): ?>
                            <br><small style="opacity: 0.8;"><?= date('d M Y H:i', strtotime($related['approved_at'])) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($related['status'] === 'rejected'): ?>
                <div style="background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%); padding: 16px; border-radius: var(--radius); color: #fff; margin-bottom: 12px;">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div style="width: 32px; height: 32px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-person-x"></i>
                        </div>
                        <div>
                            <strong>Ditolak<?= !empty($related['rejected_by_name']) ? ' oleh ' . htmlspecialchars($related['rejected_by_name']) : '' ?></strong>
                        </div>
                    </div>
                    <?php if (!empty($related['rejection_note'])): ?>
                    <p style="margin: 0; white-space: pre-line;"><i class="bi bi-quote me-1"></i><?= htmlspecialchars($related['rejection_note']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($related['return_rejection_note']) && in_array($notif['type'], ['return_rejected'])): ?>
                <div style="background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%); padding: 16px; border-radius: var(--radius); color: #fff; margin-bottom: 12px;">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div style="width: 32px; height: 32px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-arrow-return-left"></i>
                        </div>
                        <strong>Alasan Penolakan Pengembalian</strong>
                    </div>
                    <p style="margin: 0; white-space: pre-line;"><?= htmlspecialchars($related['return_rejection_note']) ?></p>
                </div>
                <?php endif; ?>

                <?php if (!empty($related['return_approved_by_name']) && $notif['type'] === 'return_approved'): ?>
                <div style="background: linear-gradient(135deg, var(--success) 0%, #059669 100%); padding: 16px; border-radius: var(--radius); color: #fff; margin-bottom: 12px;">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width: 32px; height: 32px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-box-arrow-in-left"></i>
                        </div>
                        <div>
                            <strong>Pengembalian disetujui oleh <?= htmlspecialchars($related['return_approved_by_name']) ?></strong>
                            <?php if (!empty($related['return_approved_at'])): ?>
                            <br><small style="opacity: 0.8;"><?= date('d M Y H:i', strtotime($related['return_approved_at'])) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($related['document'])): ?>
                <div style="background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.2); padding: 12px 16px; border-radius: var(--radius); margin-bottom: 12px;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <i class="bi bi-file-earmark-text me-2" style="color: var(--info);"></i>
                            <strong style="color: var(--text-dark);">Dokumen Berita Acara</strong>
                            <?php if (!empty($related['document']['doc_number'])): ?>
                            <br><small style="color: var(--text-muted);">No: <?= htmlspecialchars($related['document']['doc_number']) ?></small>
                            <?php endif; ?>
                        </div>
                        <a href="/public/assets/uploads/documents/<?= htmlspecialchars($related['document']['file_path']) ?>" 
                           target="_blank" class="btn btn-sm btn-primary">
                            <i class="bi bi-download me-1"></i>Unduh
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php elseif ($notif['reference_type'] === 'request'): ?>
                <!-- REQUEST DETAIL -->
                <div style="background: var(--bg-main); padding: 16px; border-radius: var(--radius); margin-bottom: 16px;">
                    <h6 style="color: var(--text-dark); font-weight: 600; margin-bottom: 12px;">
                        <i class="bi bi-box-seam me-2"></i>Detail Barang
                    </h6>
                    <?php if (!empty($related['group_items'])): ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" style="font-size: 14px;">
                            <thead><tr><th>Barang</th><th>Kode</th><th>Jumlah</th></tr></thead>
                            <tbody>
                            <?php foreach ($related['group_items'] as $gi): ?>
                            <tr>
                                <td><?= htmlspecialchars($gi['item_name']) ?></td>
                                <td><code><?= htmlspecialchars($gi['item_code']) ?></code></td>
                                <td><?= $gi['quantity'] ?> <?= htmlspecialchars($gi['unit'] ?? 'unit') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p style="margin: 0; color: var(--text-dark);">
                        <strong><?= htmlspecialchars($related['item_name']) ?></strong> 
                        (<code><?= htmlspecialchars($related['item_code']) ?></code>) 
                        - <?= $related['quantity'] ?> <?= htmlspecialchars($related['unit'] ?? 'unit') ?>
                    </p>
                    <?php endif; ?>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div style="background: var(--bg-main); padding: 12px 16px; border-radius: var(--radius);">
                            <small style="color: var(--text-muted); display: block; margin-bottom: 4px;">Status</small>
                            <?php
                            $statusMap = [
                                'pending' => ['Menunggu', 'warning'],
                                'approved' => ['Disetujui', 'success'],
                                'rejected' => ['Ditolak', 'danger'],
                                'completed' => ['Selesai', 'info']
                            ];
                            $st = $statusMap[$related['status']] ?? ['Unknown', 'secondary'];
                            ?>
                            <span class="badge bg-<?= $st[1] ?>"><?= $st[0] ?></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div style="background: var(--bg-main); padding: 12px 16px; border-radius: var(--radius);">
                            <small style="color: var(--text-muted); display: block; margin-bottom: 4px;">Tanggal Pengajuan</small>
                            <span style="color: var(--text-dark);"><?= date('d M Y H:i', strtotime($related['requested_at'])) ?></span>
                        </div>
                    </div>
                </div>

                <?php if (!empty($related['note'])): ?>
                <div style="background: var(--bg-main); padding: 12px 16px; border-radius: var(--radius); margin-bottom: 12px;">
                    <small style="color: var(--text-muted); display: block; margin-bottom: 4px;">
                        <i class="bi bi-chat-text me-1"></i>Catatan Anda
                    </small>
                    <p style="margin: 0; color: var(--text-dark);"><?= htmlspecialchars($related['note']) ?></p>
                </div>
                <?php endif; ?>

                <?php if ($related['status'] === 'approved' && !empty($related['approved_by_name'])): ?>
                <div style="background: linear-gradient(135deg, var(--success) 0%, #059669 100%); padding: 16px; border-radius: var(--radius); color: #fff; margin-bottom: 12px;">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width: 32px; height: 32px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-person-check"></i>
                        </div>
                        <div>
                            <strong>Disetujui oleh <?= htmlspecialchars($related['approved_by_name']) ?></strong>
                            <?php if (!empty($related['approved_at'])): ?>
                            <br><small style="opacity: 0.8;"><?= date('d M Y H:i', strtotime($related['approved_at'])) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($related['status'] === 'rejected'): ?>
                <div style="background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%); padding: 16px; border-radius: var(--radius); color: #fff; margin-bottom: 12px;">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div style="width: 32px; height: 32px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-person-x"></i>
                        </div>
                        <div>
                            <strong>Ditolak<?= !empty($related['rejected_by_name']) ? ' oleh ' . htmlspecialchars($related['rejected_by_name']) : '' ?></strong>
                        </div>
                    </div>
                    <?php if (!empty($related['rejection_note'])): ?>
                    <p style="margin: 0; white-space: pre-line;"><i class="bi bi-quote me-1"></i><?= htmlspecialchars($related['rejection_note']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($related['document'])): ?>
                <div style="background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.2); padding: 12px 16px; border-radius: var(--radius); margin-bottom: 12px;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <i class="bi bi-file-earmark-text me-2" style="color: var(--info);"></i>
                            <strong style="color: var(--text-dark);">Dokumen Berita Acara</strong>
                            <?php if (!empty($related['document']['doc_number'])): ?>
                            <br><small style="color: var(--text-muted);">No: <?= htmlspecialchars($related['document']['doc_number']) ?></small>
                            <?php endif; ?>
                        </div>
                        <a href="/public/assets/uploads/documents/<?= htmlspecialchars($related['document']['file_path']) ?>" 
                           target="_blank" class="btn btn-sm btn-primary">
                            <i class="bi bi-download me-1"></i>Unduh
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php elseif ($notif['reference_type'] === 'suggestion'): ?>
                <!-- SUGGESTION DETAIL -->
                <div style="background: var(--bg-main); padding: 16px; border-radius: var(--radius); margin-bottom: 16px; border-left: 4px solid var(--primary-light);">
                    <h6 style="color: var(--text-dark); font-weight: 600; margin-bottom: 8px;">
                        <?= htmlspecialchars($related['subject']) ?>
                    </h6>
                    <p style="margin: 0; white-space: pre-line; color: var(--text-muted); font-size: 14px;">
                        <?= htmlspecialchars($related['message']) ?>
                    </p>
                    <small style="color: var(--text-light); display: block; margin-top: 8px;">
                        <i class="bi bi-clock me-1"></i>Dikirim <?= date('d M Y H:i', strtotime($related['created_at'])) ?>
                    </small>
                </div>

                <?php if (!empty($related['admin_reply'])): ?>
                <div style="background: linear-gradient(135deg, var(--primary-light) 0%, var(--accent) 100%); padding: 16px; border-radius: var(--radius); color: #fff;">
                    <div class="d-flex align-items-center mb-2">
                        <div style="width: 32px; height: 32px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 10px;">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <div>
                            <strong>Balasan dari Admin</strong>
                            <br><small style="opacity: 0.8;"><?= htmlspecialchars($related['admin_name'] ?? 'Admin') ?> &bull; <?= date('d M Y H:i', strtotime($related['replied_at'])) ?></small>
                        </div>
                    </div>
                    <p style="margin: 0; white-space: pre-line;"><?= htmlspecialchars($related['admin_reply']) ?></p>
                </div>
                <?php endif; ?>

                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <?php if (!$notif['is_read']): ?>
                <a href="/index.php?page=user_notifications&mark_read=<?= $notif['id'] ?>" class="btn btn-secondary">
                    <i class="bi bi-check me-1"></i>Tandai Dibaca
                </a>
                <?php endif; ?>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
