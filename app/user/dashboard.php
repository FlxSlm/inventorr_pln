<?php
// app/user/dashboard.php
$pdo = require __DIR__ . '/../config/database.php';
$userId = $_SESSION['user']['id'] ?? 0;

// counts
$totalAvailable = $pdo->query('SELECT COUNT(*) FROM inventories WHERE stock_available > 0 AND deleted_at IS NULL')->fetchColumn();
$myPending = $pdo->prepare('SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = "pending"');
$myPending->execute([$userId]);
$myPendingCount = $myPending->fetchColumn();

$myApproved = $pdo->prepare('SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = "approved"');
$myApproved->execute([$userId]);
$myApprovedCount = $myApproved->fetchColumn();

// Get loans awaiting document upload
$awaitingDoc = $pdo->prepare('SELECT * FROM loans WHERE user_id = ? AND stage = "awaiting_document"');
$awaitingDoc->execute([$userId]);
$awaitingDocLoans = $awaitingDoc->fetchAll();

// Get loans awaiting return document upload
$awaitingReturnDoc = $pdo->prepare('SELECT * FROM loans WHERE user_id = ? AND return_stage = "awaiting_return_doc"');
$awaitingReturnDoc->execute([$userId]);
$awaitingReturnDocLoans = $awaitingReturnDoc->fetchAll();

// Get featured items (latest 6 items with stock)
$featuredItems = $pdo->query('SELECT * FROM inventories WHERE stock_available > 0 AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 6')->fetchAll();
?>

<!-- Alert for Awaiting Document -->
<?php foreach($awaitingDocLoans as $loan): ?>
    <div class="alert alert-info alert-dismissible fade show mb-3" role="alert">
        <i class="bi bi-file-earmark-text me-2"></i>
        Your loan request #<?= $loan['id'] ?> has been initially approved. Please
        <a href="/index.php?page=upload_document&loan_id=<?= $loan['id'] ?>" class="alert-link">download template & upload</a>.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>

<!-- Dashboard Header -->
<div class="dashboard-header mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h3 class="mb-1"><i class="bi bi-speedometer2 me-2"></i>Dashboard Karyawan</h3>
            <p class="mb-0 opacity-75">Selamat datang, <?= htmlspecialchars($_SESSION['user']['name']) ?>!</p>
        </div>
        <div class="mt-2 mt-md-0">
            <a class="btn btn-warning me-2" href="/index.php?page=user_request_loan">
                <i class="bi bi-plus-lg me-1"></i> Ajukan Peminjaman
            </a>
            <a class="btn btn-outline-light" href="/index.php?page=history">
                <i class="bi bi-clock-history me-1"></i> Riwayat
            </a>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card stats-card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="widget-icon blue me-3">
                        <i class="bi bi-box-seam"></i>
                    </div>
                    <div>
                        <div class="stats-number"><?= (int)$totalAvailable ?></div>
                        <div class="stats-label">Barang Tersedia</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stats-card h-100" style="border-left-color: #FDB913;">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="widget-icon yellow me-3">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                    <div>
                        <div class="stats-number"><?= (int)$myPendingCount ?></div>
                        <div class="stats-label">Menunggu Approval</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stats-card h-100" style="border-left-color: #10b981;">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="widget-icon green me-3">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div>
                        <div class="stats-number" style="color: #22c55e;"><?= (int)$myApprovedCount ?></div>
                        <div class="stats-label">Disetujui</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Awaiting Return Document Upload Section -->
<?php if (!empty($awaitingReturnDocLoans)): ?>
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <i class="bi bi-file-earmark-arrow-up me-2"></i>
        Upload Dokumen Pengembalian
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            Pengembalian Anda telah disetujui tahap 1. Silakan upload dokumen pengembalian untuk proses verifikasi akhir.
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Barang</th>
                        <th>Kode</th>
                        <th>Qty</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($awaitingReturnDocLoans as $rl): 
                        $inv = $pdo->query("SELECT * FROM inventories WHERE id = {$rl['inventory_id']}")->fetch();
                    ?>
                    <tr>
                        <td class="text-white"><?= htmlspecialchars($inv['name'] ?? '-') ?></td>
                        <td class="text-secondary"><?= htmlspecialchars($inv['code'] ?? '-') ?></td>
                        <td class="text-white"><?= $rl['quantity'] ?></td>
                        <td>
                            <a href="/index.php?page=upload_return_document&loan_id=<?= $rl['id'] ?>" 
                               class="btn btn-sm btn-info">
                                <i class="bi bi-upload me-1"></i> Upload Dok
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row mb-4">
    <!-- Chart Card -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-bar-chart me-2"></i>Barang Paling Sering Dipinjam
            </div>
            <div class="card-body">
                <?php if (!empty($chartLabels)): ?>
                    <canvas id="topBorrowedChart" height="200"></canvas>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="bi bi-bar-chart text-secondary" style="font-size: 3rem;"></i>
                        <p class="text-secondary mt-2">Belum ada data peminjaman</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Featured Items Card -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-grid me-2"></i>Barang Terbaru</span>
                <a href="/index.php?page=catalog" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-arrow-right me-1"></i> Lihat Semua
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($featuredItems)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-inbox text-secondary" style="font-size: 3rem;"></i>
                        <p class="text-secondary mt-2">Belum ada barang tersedia</p>
                    </div>
                <?php else: ?>
                    <div class="row g-2">
                        <?php foreach(array_slice($featuredItems, 0, 4) as $it): ?>
                            <div class="col-6">
                                <div class="d-flex align-items-center p-2 featured-item-card">
                                    <?php if ($it['image']): ?>
                                        <img src="/public/assets/uploads/<?= htmlspecialchars($it['image']) ?>" 
                                             alt="<?= htmlspecialchars($it['name']) ?>" 
                                             class="featured-item-image me-2">
                                    <?php else: ?>
                                        <div class="featured-item-placeholder me-2">
                                            <i class="bi bi-box-seam"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-0 text-pln-yellow small"><?= htmlspecialchars($it['name']) ?></h6>
                                        <small class="text-success">
                                            <?= $it['stock_available'] ?> tersedia
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.featured-item-card {
    background: rgba(15, 117, 188, 0.1);
    border-radius: 10px;
    transition: all 0.3s ease;
}
.featured-item-card:hover {
    background: rgba(15, 117, 188, 0.2);
    transform: translateX(5px);
}
.featured-item-image {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 10px;
}
.featured-item-placeholder {
    width: 60px;
    height: 60px;
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: rgba(255,255,255,0.3);
}
</style>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php if (!empty($chartLabels)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('topBorrowedChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                label: 'Jumlah Peminjaman',
                data: <?= json_encode($chartData) ?>,
                backgroundColor: [
                    'rgba(255, 206, 86, 0.8)',
                    'rgba(15, 117, 188, 0.8)',
                    'rgba(75, 192, 192, 0.8)',
                    'rgba(153, 102, 255, 0.8)',
                    'rgba(255, 159, 64, 0.8)'
                ],
                borderColor: [
                    'rgba(255, 206, 86, 1)',
                    'rgba(15, 117, 188, 1)',
                    'rgba(75, 192, 192, 1)',
                    'rgba(153, 102, 255, 1)',
                    'rgba(255, 159, 64, 1)'
                ],
                borderWidth: 1,
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)',
                        stepSize: 1
                    },
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    }
                },
                x: {
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)',
                        maxRotation: 45,
                        minRotation: 45
                    },
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    }
                }
            }
        }
    });
});
<?php endif; ?>
</script>
