<?php
// app/includes/modern_header.php
// Modern dashboard header with new teal/cyan theme

// Session timeout check (30 minutes = 1800 seconds)
$sessionTimeout = 30 * 60; // 30 minutes in seconds
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $sessionTimeout)) {
    // Session expired, destroy and redirect
    session_unset();
    session_destroy();
    header('Location: /index.php?page=login&msg=session_expired');
    exit;
}
// Update last activity time
$_SESSION['last_activity'] = time();

// Initialize database connection once at the top
$pdo_temp = require __DIR__ . '/../config/database.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PLN Inventory System</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Modern Dashboard CSS -->
    <link href="/public/assets/css/modern-dashboard.css" rel="stylesheet">
    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">
</head>
<body>
    <div class="app-wrapper">
        <!-- Sidebar Overlay for Mobile -->
        <div class="sidebar-overlay"></div>
        
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <a href="/index.php" class="sidebar-logo">
                    <div class="sidebar-logo-icon">
                        <i class="bi bi-lightning-charge-fill"></i>
                    </div>
                    <span class="sidebar-logo-text">PLN Inventory</span>
                </a>
            </div>
            
            <nav class="sidebar-nav">
                <?php if (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin'): ?>
                <!-- Admin Menu -->
                <div class="sidebar-menu-section">
                    <p class="sidebar-menu-title">Menu Utama</p>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="/index.php" class="sidebar-menu-link <?= (!isset($_GET['page']) || $_GET['page'] === 'admin_dashboard') ? 'active' : '' ?>">
                                <i class="bi bi-grid-1x2-fill"></i>
                                <span>Dashboard</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="/index.php?page=admin_inventory_list" class="sidebar-menu-link <?= (isset($_GET['page']) && $_GET['page'] === 'admin_inventory_list') ? 'active' : '' ?>">
                                <i class="bi bi-box-seam-fill"></i>
                                <span>Inventaris</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="/index.php?page=admin_categories" class="sidebar-menu-link <?= (isset($_GET['page']) && $_GET['page'] === 'admin_categories') ? 'active' : '' ?>">
                                <i class="bi bi-tags-fill"></i>
                                <span>Kategori</span>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <div class="sidebar-menu-section">
                    <p class="sidebar-menu-title">Transaksi</p>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="/index.php?page=admin_loans" class="sidebar-menu-link <?= (isset($_GET['page']) && $_GET['page'] === 'admin_loans') ? 'active' : '' ?>">
                                <i class="bi bi-clipboard-check-fill"></i>
                                <span>Peminjaman</span>
                                <?php 
                                $pendingLoansCount = $pdo_temp->query("SELECT COUNT(*) FROM loans WHERE stage = 'pending'")->fetchColumn();
                                if ($pendingLoansCount > 0): 
                                ?>
                                <span class="sidebar-menu-badge"><?= $pendingLoansCount ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="/index.php?page=admin_returns" class="sidebar-menu-link <?= (isset($_GET['page']) && $_GET['page'] === 'admin_returns') ? 'active' : '' ?>">
                                <i class="bi bi-box-arrow-in-left"></i>
                                <span>Pengembalian</span>
                                <?php 
                                $pendingReturnsCount = $pdo_temp->query("SELECT COUNT(*) FROM loans WHERE return_stage IN ('pending_return', 'return_submitted', 'awaiting_return_doc')")->fetchColumn();
                                if ($pendingReturnsCount > 0): 
                                ?>
                                <span class="sidebar-menu-badge"><?= $pendingReturnsCount ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <div class="sidebar-menu-section">
                    <p class="sidebar-menu-title">Pengguna</p>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="/index.php?page=admin_users_list" class="sidebar-menu-link <?= (isset($_GET['page']) && $_GET['page'] === 'admin_users_list') ? 'active' : '' ?>">
                                <i class="bi bi-people-fill"></i>
                                <span>Kelola User</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="/index.php?page=admin_suggestions" class="sidebar-menu-link <?= (isset($_GET['page']) && ($_GET['page'] === 'admin_suggestions' || $_GET['page'] === 'admin_notifications')) ? 'active' : '' ?>">
                                <i class="bi bi-lightbulb-fill"></i>
                                <span>Usulan Material</span>
                                <?php 
                                $unreadSuggestionsCount = $pdo_temp->query("SELECT COUNT(*) FROM material_suggestions WHERE status = 'unread'")->fetchColumn();
                                if ($unreadSuggestionsCount > 0): 
                                ?>
                                <span class="sidebar-menu-badge"><?= $unreadSuggestionsCount ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="/index.php?page=admin_settings" class="sidebar-menu-link <?= (isset($_GET['page']) && $_GET['page'] === 'admin_settings') ? 'active' : '' ?>">
                                <i class="bi bi-gear-fill"></i>
                                <span>Pengaturan</span>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <?php else: ?>
                <!-- User Menu -->
                <div class="sidebar-menu-section">
                    <p class="sidebar-menu-title">Menu Utama</p>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="/index.php" class="sidebar-menu-link <?= (!isset($_GET['page']) || $_GET['page'] === 'user_dashboard') ? 'active' : '' ?>">
                                <i class="bi bi-grid-1x2-fill"></i>
                                <span>Dashboard</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="/index.php?page=catalog" class="sidebar-menu-link <?= (isset($_GET['page']) && $_GET['page'] === 'catalog') ? 'active' : '' ?>">
                                <i class="bi bi-grid-3x3-gap-fill"></i>
                                <span>Katalog Barang</span>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <div class="sidebar-menu-section">
                    <p class="sidebar-menu-title">Peminjaman</p>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="/index.php?page=user_request_loan" class="sidebar-menu-link <?= (isset($_GET['page']) && $_GET['page'] === 'user_request_loan') ? 'active' : '' ?>">
                                <i class="bi bi-plus-circle-fill"></i>
                                <span>Ajukan Peminjaman</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="/index.php?page=history" class="sidebar-menu-link <?= (isset($_GET['page']) && $_GET['page'] === 'history') ? 'active' : '' ?>">
                                <i class="bi bi-clock-history"></i>
                                <span>Riwayat Saya</span>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <div class="sidebar-menu-section">
                    <p class="sidebar-menu-title">Lainnya</p>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="/index.php?page=user_suggestions" class="sidebar-menu-link <?= (isset($_GET['page']) && $_GET['page'] === 'user_suggestions') ? 'active' : '' ?>">
                                <i class="bi bi-lightbulb-fill"></i>
                                <span>Usulan Material</span>
                            </a>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>
            </nav>
            
            <!-- User Info at Bottom -->
            <?php if (isset($_SESSION['user'])): ?>
            <div class="sidebar-user">
                <div class="sidebar-user-info">
                    <div class="sidebar-user-avatar">
                        <?= strtoupper(substr($_SESSION['user']['name'], 0, 1)) ?>
                    </div>
                    <div class="sidebar-user-details">
                        <p class="sidebar-user-name"><?= htmlspecialchars($_SESSION['user']['name']) ?></p>
                        <p class="sidebar-user-role"><?= ucfirst($_SESSION['user']['role']) ?></p>
                    </div>
                    <a href="/index.php?page=logout" class="sidebar-logout-btn" title="Logout">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Topbar -->
            <header class="topbar">
                <div class="topbar-left">
                    <button class="sidebar-toggle" id="sidebarToggle">
                        <i class="bi bi-list"></i>
                    </button>
                    <h1 class="topbar-title"><?= $pageTitle ?? 'Dashboard' ?></h1>
                    <form action="/index.php" method="GET" class="topbar-search">
                        <input type="hidden" name="page" value="catalog">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" placeholder="Cari barang, transaksi..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                    </form>
                </div>
                <div class="topbar-right">
                    <?php if (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin'): ?>
                    <!-- Admin notification - only material suggestions -->
                    <a href="/index.php?page=admin_notifications" class="topbar-icon-btn" title="Notifikasi Usulan Material">
                        <i class="bi bi-bell"></i>
                        <?php 
                        if (($unreadSuggestionsCount ?? 0) > 0): 
                        ?>
                        <span class="notification-badge"><?= $unreadSuggestionsCount ?></span>
                        <?php endif; ?>
                    </a>
                    <?php else: ?>
                    <!-- Employee notification - all notifications -->
                    <?php
                    $userNotifCount = 0;
                    if (isset($_SESSION['user'])) {
                        $userNotifCount = $pdo_temp->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . (int)$_SESSION['user']['id'] . " AND is_read = 0")->fetchColumn();
                    }
                    ?>
                    <a href="/index.php?page=user_notifications" class="topbar-icon-btn" title="Notifikasi">
                        <i class="bi bi-bell"></i>
                        <?php if ($userNotifCount > 0): ?>
                        <span class="notification-badge"><?= $userNotifCount ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <div class="dropdown">
                        <div class="topbar-user" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="topbar-avatar">
                                <?= isset($_SESSION['user']) ? strtoupper(substr($_SESSION['user']['name'], 0, 1)) : 'G' ?>
                            </div>
                        </div>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if (isset($_SESSION['user'])): ?>
                            <li><span class="dropdown-item-text fw-semibold"><?= htmlspecialchars($_SESSION['user']['name']) ?></span></li>
                            <li><span class="dropdown-item-text small"><?= htmlspecialchars($_SESSION['user']['email']) ?></span></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="/index.php?page=logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                            <?php else: ?>
                            <li><a class="dropdown-item" href="/index.php?page=login"><i class="bi bi-box-arrow-in-right me-2"></i>Login</a></li>
                            <li><a class="dropdown-item" href="/index.php?page=register"><i class="bi bi-person-plus me-2"></i>Register</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </header>
            
            <!-- Page Content -->
            <div class="page-content">
