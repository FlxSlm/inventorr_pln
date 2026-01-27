<?php
// app/user/request_history.php - User's request history with grouped support
if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$userId = $_SESSION['user']['id'];
$msg = $_GET['msg'] ?? '';

// Fetch user's requests
$stmt = $pdo->prepare("
    SELECT r.*, i.name as inventory_name, i.code as inventory_code, i.image as inventory_image
    FROM requests r
    JOIN inventories i ON i.id = r.inventory_id
    WHERE r.user_id = ?
    ORDER BY r.requested_at DESC, r.group_id, r.id
");
$stmt->execute([$userId]);
$rawRequests = $stmt->fetchAll();

// Group requests by group_id
$requests = [];
$groupedRequests = [];
foreach ($rawRequests as $req) {
    if (!empty($req['group_id'])) {
        if (!isset($groupedRequests[$req['group_id']])) {
            $groupedRequests[$req['group_id']] = [
                'is_group' => true,
                'group_id' => $req['group_id'],
                'items' => [],
                'requested_at' => $req['requested_at'],
                'stage' => $req['stage'],
                'note' => $req['note'],
                'rejection_note' => $req['rejection_note'],
                'total_quantity' => 0
            ];
        }
        $groupedRequests[$req['group_id']]['items'][] = $req;
        $groupedRequests[$req['group_id']]['total_quantity'] += $req['quantity'];
        if ($req['stage'] === 'rejected') {
            $groupedRequests[$req['group_id']]['stage'] = 'rejected';
            $groupedRequests[$req['group_id']]['rejection_note'] = $req['rejection_note'];
        }
    } else {
        $req['is_group'] = false;
        $requests[] = $req;
    }
}
foreach ($groupedRequests as $group) {
    $requests[] = $group;
}
usort($requests, fn($a, $b) => strtotime($b['requested_at'] ?? $b['items'][0]['requested_at'] ?? 'now') - strtotime($a['requested_at'] ?? $a['items'][0]['requested_at'] ?? 'now'));

$stageLabels = [
    'pending' => ['Menunggu Validasi', 'warning', 'hourglass-split'],
    'awaiting_document' => ['Upload Dokumen', 'info', 'file-earmark-arrow-up'],
    'submitted' => ['Menunggu Verifikasi', 'primary', 'clock'],
    'approved' => ['Disetujui', 'success', 'check-circle'],
    'rejected' => ['Ditolak', 'danger', 'x-circle']
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">
            <i class="bi bi-clock-history me-2"></i>Riwayat Permintaan
        </h1>
        <p class="text-muted mb-0">Daftar permintaan barang Anda</p>
    </div>
    <a href="/index.php?page=user_request_item" class="btn btn-primary">
        <i class="bi bi-plus-lg me-2"></i>Ajukan Permintaan
    </a>
</div>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="modern-card">
    <div class="card-body p-0">
        <?php if (empty($requests)): ?>
        <div class="text-center py-5">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="bi bi-inbox"></i>
                </div>
                <h5 class="empty-state-title">Belum Ada Permintaan</h5>
                <p class="empty-state-text">Anda belum mengajukan permintaan barang.</p>
                <a href="/index.php?page=user_request_item" class="btn btn-primary mt-3">
                    <i class="bi bi-plus-lg me-1"></i>Ajukan Permintaan
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Barang</th>
                        <th>Jumlah</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rowNumber = 0;
                    foreach($requests as $r): 
                        $rowNumber++;
                        $isGroup = !empty($r['is_group']);
                        
                        if ($isGroup):
                            $firstItem = $r['items'][0];
                            $itemCount = count($r['items']);
                            $stage = $r['stage'] ?? $firstItem['stage'];
                            $stageInfo = $stageLabels[$stage] ?? ['Unknown', 'secondary', 'question'];
                    ?>
                    <tr class="group-header" data-group="<?= $r['group_id'] ?>" style="cursor: pointer;" onclick="toggleGroup('<?= $r['group_id'] ?>')">
                        <td><span class="badge bg-secondary"><?= $rowNumber ?></span></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="rounded me-2 d-flex align-items-center justify-content-center" 
                                     style="width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary), var(--primary-light));">
                                    <i class="bi bi-stack text-white"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold">
                                        <i class="bi bi-chevron-right group-chevron me-1" id="chevron-<?= $r['group_id'] ?>"></i>
                                        <?= $itemCount ?> Barang
                                    </div>
                                    <small class="text-muted">Klik untuk detail</small>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge bg-primary"><?= (int)$r['total_quantity'] ?></span></td>
                        <td>
                            <div><?= date('d M Y', strtotime($firstItem['requested_at'])) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($firstItem['requested_at'])) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-<?= $stageInfo[1] ?>">
                                <i class="bi bi-<?= $stageInfo[2] ?> me-1"></i><?= $stageInfo[0] ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($stage === 'rejected' && !empty($r['rejection_note'])): ?>
                            <button type="button" class="btn-alasan" data-bs-toggle="modal" data-bs-target="#rejectionModalGroup<?= $r['group_id'] ?>" onclick="event.stopPropagation();">
                                <i class="bi bi-exclamation-circle"></i> Alasan
                            </button>
                            <?php else: ?>
                            <span class="text-muted small"><i class="bi bi-eye"></i> Detail</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php foreach($r['items'] as $item): 
                        $itemStageInfo = $stageLabels[$item['stage']] ?? ['Unknown', 'secondary', 'question'];
                    ?>
                    <tr class="group-detail-row" data-parent="<?= $r['group_id'] ?>" style="display: none; background: var(--bg-secondary);">
                        <td></td>
                        <td>
                            <div class="d-flex align-items-center ps-3">
                                <div class="border-start border-2 border-primary ps-3">
                                    <?php if ($item['inventory_image']): ?>
                                    <img src="/public/assets/uploads/<?= htmlspecialchars($item['inventory_image']) ?>" 
                                         class="rounded me-2" style="width: 36px; height: 36px; object-fit: cover;">
                                    <?php else: ?>
                                    <div class="rounded me-2 d-flex align-items-center justify-content-center" 
                                         style="width: 36px; height: 36px; background: var(--bg-main);">
                                        <i class="bi bi-box-seam text-muted"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($item['inventory_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($item['inventory_code']) ?></small>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge bg-secondary"><?= $item['quantity'] ?></span></td>
                        <td><small class="text-muted"><?= date('d M Y', strtotime($item['requested_at'])) ?></small></td>
                        <td>
                            <span class="badge bg-<?= $itemStageInfo[1] ?>" style="font-size: 10px;"><?= $itemStageInfo[0] ?></span>
                        </td>
                        <td>
                            <?php if ($item['stage'] === 'awaiting_document'): ?>
                            <a href="/index.php?page=upload_request_document&request_id=<?= $item['id'] ?>" class="btn btn-sm btn-primary" onclick="event.stopPropagation();">
                                <i class="bi bi-upload"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php else:
                        $stage = $r['stage'] ?? 'pending';
                        $stageInfo = $stageLabels[$stage] ?? ['Unknown', 'secondary', 'question'];
                    ?>
                    <tr>
                        <td><span class="badge bg-secondary"><?= $rowNumber ?></span></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <?php if ($r['inventory_image']): ?>
                                <img src="/public/assets/uploads/<?= htmlspecialchars($r['inventory_image']) ?>" 
                                     alt="" class="rounded me-2" style="width: 40px; height: 40px; object-fit: cover;">
                                <?php else: ?>
                                <div class="rounded me-2 d-flex align-items-center justify-content-center" 
                                     style="width: 40px; height: 40px; background: var(--bg-main);">
                                    <i class="bi bi-box-seam text-muted"></i>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($r['inventory_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($r['inventory_code']) ?></small>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge bg-primary"><?= $r['quantity'] ?></span></td>
                        <td>
                            <div><?= date('d M Y', strtotime($r['requested_at'])) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($r['requested_at'])) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-<?= $stageInfo[1] ?>">
                                <i class="bi bi-<?= $stageInfo[2] ?> me-1"></i><?= $stageInfo[0] ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($stage === 'awaiting_document'): ?>
                            <a href="/index.php?page=upload_request_document&request_id=<?= $r['id'] ?>" class="btn btn-sm btn-primary">
                                <i class="bi bi-upload me-1"></i>Upload
                            </a>
                            <?php elseif ($stage === 'rejected' && $r['rejection_note']): ?>
                            <button type="button" class="btn-alasan" data-bs-toggle="modal" data-bs-target="#rejectionModal<?= $r['id'] ?>">
                                <i class="bi bi-exclamation-circle"></i> Alasan
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Rejection Modals -->
<?php 
// Generate modals for all requests (including grouped items)
$allRequestItems = [];
foreach($requests as $r) {
    if (!empty($r['is_group'])) {
        foreach($r['items'] as $item) {
            $allRequestItems[] = $item;
        }
        // Create group rejection modal
        if ($r['stage'] === 'rejected' && !empty($r['rejection_note'])) {
            echo '<div class="modal fade" id="rejectionModalGroup' . $r['group_id'] . '" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <div class="modal-header border-0">
                  <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Permintaan Ditolak</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <div class="mb-3 p-3 rounded" style="background: var(--bg-main);">
                    <div class="fw-semibold mb-2">' . count($r['items']) . ' Barang:</div>';
            foreach($r['items'] as $gi) {
                echo '<div class="d-flex align-items-center mb-2">
                    <i class="bi bi-box-seam text-muted me-2"></i>
                    <span>' . htmlspecialchars($gi['inventory_name']) . ' (' . (int)$gi['quantity'] . ' unit)</span>
                </div>';
            }
            echo '</div>
                  <div class="rejection-reason-box">
                    <div class="reason-label"><i class="bi bi-exclamation-triangle me-1"></i>Alasan Penolakan</div>
                    <p class="reason-text">' . nl2br(htmlspecialchars($r['rejection_note'])) . '</p>
                  </div>
                </div>
                <div class="modal-footer border-0">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                  <a href="/index.php?page=user_request_item" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Ajukan Baru</a>
                </div>
              </div>
            </div>
          </div>';
        }
    } else {
        $allRequestItems[] = $r;
    }
}

foreach($allRequestItems as $r): ?>
<?php if ($r['stage'] === 'rejected' && !empty($r['rejection_note'])): ?>
<div class="modal fade" id="rejectionModal<?= $r['id'] ?>" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h5 class="modal-title">
          <i class="bi bi-x-circle text-danger me-2"></i>Permintaan Ditolak
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex align-items-center mb-3 p-3 rounded" style="background: var(--bg-main);">
          <?php if($r['inventory_image']): ?>
          <img src="/public/assets/uploads/<?= htmlspecialchars($r['inventory_image']) ?>" 
               class="rounded me-3" style="width: 50px; height: 50px; object-fit: cover;">
          <?php else: ?>
          <div class="rounded me-3 d-flex align-items-center justify-content-center" 
               style="width: 50px; height: 50px; background: var(--bg-tertiary);">
              <i class="bi bi-box-seam text-muted"></i>
          </div>
          <?php endif; ?>
          <div>
              <div class="fw-semibold"><?= htmlspecialchars($r['inventory_name']) ?></div>
              <small class="text-muted"><?= (int)$r['quantity'] ?> unit</small>
          </div>
        </div>
        
        <div class="rejection-reason-box">
          <div class="reason-label"><i class="bi bi-exclamation-triangle me-1"></i>Alasan Penolakan</div>
          <p class="reason-text"><?= nl2br(htmlspecialchars($r['rejection_note'])) ?></p>
        </div>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
        <a href="/index.php?page=user_request_item" class="btn btn-primary">
          <i class="bi bi-plus-lg me-1"></i>Ajukan Baru
        </a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<script>
function toggleGroup(groupId) {
    const detailRows = document.querySelectorAll(`tr[data-parent="${groupId}"]`);
    const chevron = document.getElementById(`chevron-${groupId}`);
    
    detailRows.forEach(row => {
        row.style.display = row.style.display === 'none' ? '' : 'none';
    });
    
    if (chevron) {
        chevron.classList.toggle('bi-chevron-right');
        chevron.classList.toggle('bi-chevron-down');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (el) { return new bootstrap.Tooltip(el); });
});
</script>

<style>
.group-header { transition: background 0.2s; }
.group-header:hover { background: var(--bg-tertiary) !important; }
.group-detail-row { font-size: 0.9em; }
.group-chevron { transition: transform 0.2s; }
</style>
