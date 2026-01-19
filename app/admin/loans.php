<?php
// app/admin/loans.php
// admin page: show list of loans and allow approve/reject
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}
$pdo = require __DIR__ . '/../config/database.php';

// Handle messages
$msg = $_GET['msg'] ?? '';

// Fetch loans (latest first)
$stmt = $pdo->query("
    SELECT l.*, u.name AS user_name, u.email AS user_email, i.name AS inventory_name, i.code AS inventory_code, i.stock_available, i.image AS inventory_image
    FROM loans l
    JOIN users u ON u.id = l.user_id
    JOIN inventories i ON i.id = l.inventory_id
    ORDER BY l.requested_at DESC
");
$loans = $stmt->fetchAll();
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Kelola Peminjaman</h4>
        </div>
        <?php if($msg): ?>
            <div class="alert alert-success mb-0 py-1 px-3">
                <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="card-body p-0">
        <?php if (empty($loans)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox text-secondary" style="font-size: 4rem;"></i>
                <h5 class="mt-3 text-secondary">Belum Ada Peminjaman</h5>
                <p class="text-secondary">Belum ada permintaan peminjaman dari karyawan.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Peminjam</th>
                            <th>Barang</th>
                            <th>Qty</th>
                            <th>Catatan</th>
                            <th>Tanggal</th>
                            <th>Stage</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($loans as $l): ?>
                            <tr>
                                <td><span class="badge bg-secondary">#<?= $l['id'] ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="me-2" style="width: 35px; height: 35px; background: linear-gradient(135deg, #0F75BC, #1E88E5); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                                            <?= strtoupper(substr($l['user_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($l['user_name']) ?></div>
                                            <small class="text-secondary"><?= htmlspecialchars($l['user_email']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if ($l['inventory_image']): ?>
                                            <img src="/public/assets/uploads/<?= htmlspecialchars($l['inventory_image']) ?>" 
                                                 alt="" class="me-2" 
                                                 style="width: 40px; height: 40px; object-fit: cover; border-radius: 8px;">
                                        <?php else: ?>
                                            <div class="me-2" style="width: 40px; height: 40px; background: rgba(255,255,255,0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                <i class="bi bi-box-seam text-secondary"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div><?= htmlspecialchars($l['inventory_name']) ?></div>
                                            <small class="text-secondary"><?= htmlspecialchars($l['inventory_code']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="fw-bold text-pln-yellow"><?= $l['quantity'] ?></span></td>
                                <td>
                                    <?php if ($l['note']): ?>
                                        <button type="button" class="btn btn-sm btn-outline-info" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#noteModal<?= $l['id'] ?>">
                                            <i class="bi bi-chat-text me-1"></i> Lihat
                                        </button>
                                    <?php else: ?>
                                        <span class="text-secondary small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small>
                                        <?= date('d M Y', strtotime($l['requested_at'])) ?><br>
                                        <span class="text-secondary"><?= date('H:i', strtotime($l['requested_at'])) ?></span>
                                    </small>
                                </td>
                                <td>
                                    <?php if($l['stage'] === 'pending'): ?>
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    <?php elseif($l['stage'] === 'awaiting_document'): ?>
                                        <span class="badge bg-info">Awaiting Document</span>
                                        <small class="text-muted d-block">User must upload filled template</small>
                                    <?php elseif($l['stage'] === 'submitted'): ?>
                                        <span class="badge bg-primary">Submitted</span>
                                    <?php elseif($l['stage'] === 'approved'): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Approved</span>
                                        <?php 
                                        $rs = $l['return_stage'] ?? 'none';
                                        if ($rs !== 'none' && $rs !== ''): 
                                            $returnLabels = [
                                                'pending_return' => ['Pengajuan Kembali', 'warning', 'text-dark'],
                                                'awaiting_return_doc' => ['Tunggu Dok Kembali', 'info', ''],
                                                'return_submitted' => ['Dok Kembali Submitted', 'primary', ''],
                                                'return_approved' => ['Dikembalikan', 'secondary', ''],
                                                'return_rejected' => ['Kembali Ditolak', 'danger', '']
                                            ];
                                            $rInfo = $returnLabels[$rs] ?? ['Unknown', 'secondary', ''];
                                        ?>
                                            <br><span class="badge bg-<?= $rInfo[1] ?> <?= $rInfo[2] ?> mt-1"><?= $rInfo[0] ?></span>
                                        <?php endif; ?>
                                    <?php elseif($l['stage'] === 'rejected'): ?>
                                        <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Rejected</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= htmlspecialchars($l['stage'] ?? $l['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($l['stage'] === 'pending'): ?>
                                        <!-- Approve initial -->
                                        <form method="POST" action="/index.php?page=loan_approve" style="display:inline-block" data-confirm="Set initial approval and request employee document?">
                                            <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                            <button class="btn btn-sm btn-success">Approve (request doc)</button>
                                        </form>
                                    <?php elseif($l['stage'] === 'awaiting_document'): ?>
                                        <small class="text-secondary">Waiting for user upload</small>
                                    <?php elseif($l['stage'] === 'submitted'): ?>
                                        <?php if(!empty($l['document_path'])): ?>
                                            <a class="btn btn-sm btn-info" href="/public/<?= htmlspecialchars($l['document_path']) ?>" target="_blank">
                                                <i class="bi bi-download me-1"></i>Download Doc
                                            </a>
                                        <?php endif; ?>
                                        <!-- Final approve / reject -->
                                        <form method="POST" action="/index.php?page=final_approve" style="display:inline-block" data-confirm="Final approve and reduce stock?">
                                            <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                            <button class="btn btn-sm btn-success">Final Approve</button>
                                        </form>
                                        <form method="POST" action="/index.php?page=final_reject" style="display:inline-block" data-confirm="Reject this loan?">
                                            <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                            <button class="btn btn-sm btn-danger">Reject</button>
                                        </form>
                                    <?php elseif($l['stage'] === 'approved'): ?>
                                        <span class="text-secondary">—</span>
                                    <?php elseif($l['stage'] === 'rejected'): ?>
                                        <span class="text-secondary">—</span>
                                    <?php else: ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Note Modals (placed outside table to prevent rendering issues) -->
<?php foreach($loans as $l): ?>
    <?php if ($l['note']): ?>
        <div class="modal fade" id="noteModal<?= $l['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-chat-text me-2"></i>Catatan dari <?= htmlspecialchars($l['user_name']) ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <small class="text-secondary">Barang yang diminta:</small>
                            <div class="fw-bold"><?= htmlspecialchars($l['inventory_name']) ?> (<?= $l['quantity'] ?> unit)</div>
                        </div>
                        <div class="p-3" style="background: rgba(15, 117, 188, 0.1); border-radius: 10px; border-left: 4px solid #FDB913;">
                            <small class="text-secondary d-block mb-2">Catatan:</small>
                            <p class="mb-0"><?= nl2br(htmlspecialchars($l['note'])) ?></p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
