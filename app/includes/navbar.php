<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/index.php">
      <span class="brand-icons">
        <img src="/public/assets/img/logopln.png" alt="PLN Logo" />
        <img src="/public/assets/img/danantara.png" alt="Danantara Logo" class="danantara" />
      </span>
      &nbsp;PLN Inventory
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarMain">
      <ul class="navbar-nav ms-auto">
        <?php if (isset($_SESSION['user'])): ?>
          <li class="nav-item">
            <a class="nav-link" href="/index.php">
              <i class="bi bi-speedometer2"></i> Dashboard
            </a>
          </li>
          <?php if($_SESSION['user']['role'] === 'admin'): ?>
            <li class="nav-item">
              <a class="nav-link" href="/index.php?page=admin_inventory_list">
                <i class="bi bi-box-seam"></i> Kelola Inventaris
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="/index.php?page=admin_categories">
                <i class="bi bi-tags"></i> Kategori
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="/index.php?page=admin_loans">
                <i class="bi bi-clipboard-check"></i> Peminjaman
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="/index.php?page=admin_returns">
                <i class="bi bi-box-arrow-in-left"></i> Pengembalian
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="/index.php?page=admin_users_list">
                <i class="bi bi-people"></i> Kelola User
              </a>
            </li>
          <?php else: ?>
            <li class="nav-item">
              <a class="nav-link" href="/index.php?page=catalog">
                <i class="bi bi-grid"></i> Katalog Barang
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="/index.php?page=user_request_loan">
                <i class="bi bi-plus-circle"></i> Ajukan Peminjaman
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="/index.php?page=history">
                <i class="bi bi-clock-history"></i> Riwayat Saya
              </a>
            </li>
          <?php endif; ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
              <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['user']['name']) ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
              <li><span class="dropdown-item-text small text-muted"><?= htmlspecialchars($_SESSION['user']['email']) ?></span></li>
              <li><span class="dropdown-item-text"><span class="badge bg-<?= $_SESSION['user']['role'] === 'admin' ? 'warning' : 'primary' ?>"><?= ucfirst($_SESSION['user']['role']) ?></span></span></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="/index.php?page=logout"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item">
            <a class="nav-link" href="/index.php?page=login">
              <i class="bi bi-box-arrow-in-right"></i> Login
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="/index.php?page=register">
              <i class="bi bi-person-plus"></i> Register
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
