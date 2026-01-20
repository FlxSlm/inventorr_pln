<?php
// app/admin/dashboard.php
// Assumes session started and user is authenticated and is admin (checked in index.php)
$pdo = require __DIR__ . '/../config/database.php';

// quick stats
$totalItems = $pdo->query('SELECT COUNT(*) FROM inventories WHERE deleted_at IS NULL')->fetchColumn();
$lowStock = $pdo->query('SELECT COUNT(*) FROM inventories WHERE stock_available <= 2 AND deleted_at IS NULL')->fetchColumn();
$totalPendingLoans = $pdo->query("SELECT COUNT(*) FROM loans WHERE status = 'pending'")->fetchColumn();
$totalPendingReturns = $pdo->query("SELECT COUNT(*) FROM loans WHERE return_stage = 'pending_return' OR return_stage = 'return_submitted'")->fetchColumn();
$totalUsers = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

// recent loans
$stmt = $pdo->query("
  SELECT l.*, u.name AS user_name, i.name AS inventory_name
  FROM loans l
  JOIN users u ON u.id = l.user_id
  JOIN inventories i ON i.id = l.inventory_id
  ORDER BY l.requested_at DESC
  LIMIT 5
");
$recentLoans = $stmt->fetchAll();

// Top borrowed items (for chart)
$topBorrowed = $pdo->query("
  SELECT i.name, COUNT(l.id) as borrow_count, SUM(l.quantity) as total_qty
  FROM loans l
  JOIN inventories i ON i.id = l.inventory_id
  GROUP BY l.inventory_id
  ORDER BY borrow_count DESC
  LIMIT 7
")->fetchAll();

$chartLabels = [];
$chartData = [];
$chartColors = ['#0F75BC', '#FDB913', '#10B981', '#EF4444', '#8B5CF6', '#F59E0B', '#3B82F6'];
foreach ($topBorrowed as $idx => $item) {
    $chartLabels[] = $item['name'];
    $chartData[] = (int)$item['borrow_count'];
}
?>

<!-- Dashboard Header -->
<div class="dashboard-header mb-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap">
    <div>
      <h3 class="mb-1"><i class="bi bi-speedometer2 me-2"></i>Dashboard Admin</h3>
      <p class="mb-0 opacity-75">Selamat datang, <?= htmlspecialchars($_SESSION['user']['name']) ?>! Kelola inventaris PLN dengan mudah.</p>
    </div>
    <div class="mt-2 mt-md-0">
      <a class="btn btn-warning me-2" href="/index.php?page=admin_inventory_list">
        <i class="bi bi-box-seam me-1"></i> Inventaris
      </a>
      <a class="btn btn-light me-2" href="/index.php?page=admin_users_list">
        <i class="bi bi-people me-1"></i> Users
      </a>
      <a class="btn btn-outline-light" href="/index.php?page=admin_loans">
        <i class="bi bi-clipboard-check me-1"></i> Peminjaman
      </a>
    </div>
  </div>
</div>

<!-- Stats Cards -->
<div class="row g-4 mb-4">
  <div class="col-md-6 col-lg-3">
    <div class="card stats-card h-100">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <div class="widget-icon blue me-3">
            <i class="bi bi-box-seam"></i>
          </div>
          <div>
            <div class="stats-number"><?= (int)$totalItems ?></div>
            <div class="stats-label">Total Barang</div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-lg-3">
    <div class="card stats-card h-100" style="border-left-color: #dc3545;">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <div class="widget-icon" style="background: linear-gradient(135deg, #dc3545, #e74c3c);">
            <i class="bi bi-exclamation-triangle"></i>
          </div>
          <div class="ms-3">
            <div class="stats-number" style="color: #ff6b6b;"><?= (int)$lowStock ?></div>
            <div class="stats-label">Stok Rendah</div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-lg-3">
    <div class="card stats-card h-100" style="border-left-color: #FDB913;">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <div class="widget-icon yellow me-3">
            <i class="bi bi-hourglass-split"></i>
          </div>
          <div>
            <div class="stats-number"><?= (int)$totalPendingLoans ?></div>
            <div class="stats-label">Menunggu Approval</div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-lg-3">
    <div class="card stats-card h-100" style="border-left-color: #8B5CF6;">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <div class="widget-icon" style="background: linear-gradient(135deg, #8B5CF6, #A78BFA);">
            <i class="bi bi-box-arrow-in-left"></i>
          </div>
          <div class="ms-3">
            <div class="stats-number" style="color: #A78BFA;"><?= (int)$totalPendingReturns ?></div>
            <div class="stats-label">Pengembalian Pending</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Chart and Stats Row -->
<div class="row g-4 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-bar-chart me-2"></i>Barang Paling Sering Dipinjam
      </div>
      <div class="card-body">
        <?php if (empty($topBorrowed)): ?>
          <div class="text-center py-4">
            <i class="bi bi-bar-chart text-secondary" style="font-size: 3rem;"></i>
            <p class="text-secondary mt-2">Belum ada data peminjaman</p>
          </div>
        <?php else: ?>
          <canvas id="topBorrowedChart" height="250"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-pie-chart me-2"></i>Statistik Cepat
      </div>
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3 p-2" style="background: rgba(15, 117, 188, 0.15); border-radius: 8px;">
          <span class="text-white"><i class="bi bi-people me-2" style="color: #4DA6E8;"></i>Total User</span>
          <span class="fw-bold" style="color: #4DA6E8; font-size: 1.2rem;"><?= (int)$totalUsers ?></span>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-3 p-2" style="background: rgba(16, 185, 129, 0.15); border-radius: 8px;">
          <span class="text-white"><i class="bi bi-check-circle me-2" style="color: #22c55e;"></i>Barang Tersedia</span>
          <span class="fw-bold" style="color: #22c55e; font-size: 1.2rem;"><?= (int)$totalItems ?></span>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-3 p-2" style="background: rgba(239, 68, 68, 0.15); border-radius: 8px;">
          <span class="text-white"><i class="bi bi-exclamation-triangle me-2" style="color: #ef4444;"></i>Stok Rendah</span>
          <span class="fw-bold" style="color: #ef4444; font-size: 1.2rem;"><?= (int)$lowStock ?></span>
        </div>
        <div class="d-flex justify-content-between align-items-center p-2" style="background: rgba(253, 185, 19, 0.15); border-radius: 8px;">
          <span class="text-white"><i class="bi bi-clock me-2" style="color: #FDB913;"></i>Total Peminjaman</span>
          <span class="fw-bold" style="color: #FDB913; font-size: 1.2rem;"><?= $pdo->query('SELECT COUNT(*) FROM loans')->fetchColumn() ?></span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Recent Loans Table -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-clock-history me-2"></i>Peminjaman Terbaru</span>
    <a href="/index.php?page=admin_loans" class="btn btn-sm btn-outline-light">Lihat Semua</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>ID</th>
            <th>Peminjam</th>
            <th>Barang</th>
            <th>Jumlah</th>
            <th>Tanggal Request</th>
            <th>Status</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($recentLoans)): ?>
            <tr>
              <td colspan="7" class="text-center py-4">
                <i class="bi bi-inbox text-secondary" style="font-size: 2rem;"></i>
                <p class="text-secondary mt-2 mb-0">Belum ada peminjaman</p>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach($recentLoans as $l): ?>
              <tr>
                <td><span class="badge bg-secondary">#<?= $l['id'] ?></span></td>
                <td>
                  <i class="bi bi-person-circle text-pln-blue me-1"></i>
                  <?= htmlspecialchars($l['user_name']) ?>
                </td>
                <td><?= htmlspecialchars($l['inventory_name']) ?></td>
                <td><span class="fw-bold"><?= $l['quantity'] ?></span></td>
                <td><small><?= date('d M Y, H:i', strtotime($l['requested_at'])) ?></small></td>
                <td>
                  <?php if($l['status'] === 'pending'): ?>
                    <span class="badge bg-warning"><i class="bi bi-hourglass-split me-1"></i>Pending</span>
                  <?php elseif($l['status'] === 'approved'): ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Disetujui</span>
                  <?php elseif($l['status'] === 'rejected'): ?>
                    <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Ditolak</span>
                  <?php elseif($l['status'] === 'returned'): ?>
                    <span class="badge bg-success"><i class="bi bi-arrow-return-left me-1"></i>Dikembalikan</span>
                  <?php else: ?>
                    <span class="badge bg-secondary"><?= htmlspecialchars($l['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if($l['status'] === 'pending' && $l['stage'] === 'pending'): ?>
                    <form method="POST" action="/index.php?page=loan_approve" style="display:inline-block" onsubmit="return confirm('Approve peminjaman ini?');">
                      <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                      <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i></button>
                    </form>
                    <form method="POST" action="/index.php?page=loan_reject" style="display:inline-block" onsubmit="return confirm('Reject peminjaman ini?');">
                      <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                      <button class="btn btn-sm btn-danger"><i class="bi bi-x-lg"></i></button>
                    </form>
                  <?php else: ?>
                    <span class="text-secondary">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if (!empty($topBorrowed)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
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
                    'rgba(15, 117, 188, 0.8)',
                    'rgba(253, 185, 19, 0.8)',
                    'rgba(16, 185, 129, 0.8)',
                    'rgba(239, 68, 68, 0.8)',
                    'rgba(139, 92, 246, 0.8)',
                    'rgba(245, 158, 11, 0.8)',
                    'rgba(59, 130, 246, 0.8)'
                ],
                borderColor: [
                    'rgb(15, 117, 188)',
                    'rgb(253, 185, 19)',
                    'rgb(16, 185, 129)',
                    'rgb(239, 68, 68)',
                    'rgb(139, 92, 246)',
                    'rgb(245, 158, 11)',
                    'rgb(59, 130, 246)'
                ],
                borderWidth: 2,
                borderRadius: 8
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
                        color: 'rgba(255,255,255,0.7)',
                        stepSize: 1
                    },
                    grid: {
                        color: 'rgba(255,255,255,0.1)'
                    }
                },
                x: {
                    ticks: {
                        color: 'rgba(255,255,255,0.7)',
                        maxRotation: 45,
                        minRotation: 45
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
});
</script>
<?php endif; ?>