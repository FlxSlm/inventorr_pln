<?php
// public/index.php
// Front controller / simple router for the PHP + Bootstrap inventory app

// Start session once
session_start();

// base path ke folder app
define('APP_PATH', __DIR__ . '/app/');

function view($file) {
    // wrapper: include modern header, content, footer
    require APP_PATH . 'includes/modern_header.php';
    require APP_PATH . $file;
    require APP_PATH . 'includes/modern_footer.php';
}

// Legacy view function (if needed)
function view_legacy($file) {
    require APP_PATH . 'includes/header.php';
    require APP_PATH . $file;
    require APP_PATH . 'includes/footer.php';
}

// get requested page (default home)
$page = $_GET['page'] ?? 'home';

// ----------------------
// Public pages (no auth)
// ----------------------
if ($page === 'login') {
    require APP_PATH . 'auth/login.php';
    exit;
}

if ($page === 'register') {
    require APP_PATH . 'auth/register.php';
    exit;
}

if ($page === 'logout') {
    require APP_PATH . 'auth/logout.php';
    exit;
}

// ----------------------
// Require authentication
// ----------------------
// All routes after this point require a logged-in user.
// auth/check.php will redirect to login if not authenticated.
require APP_PATH . 'auth/check.php';

// Helper: ensure current user exists in session (extra safety)
$currentUser = $_SESSION['user'] ?? null;
if (!$currentUser) {
    header('Location: /index.php?page=login');
    exit;
}

// ----------------------
// Authenticated routes
// ----------------------
switch ($page) {
    // ----- Admin inventory pages (views) -----
    case 'admin_inventory_list':
        view('admin/inventory_list.php');
        break;
    case 'admin_inventory_add':
        view('admin/inventory_add.php');
        break;
    case 'admin_inventory_edit':
        view('admin/inventory_edit.php');
        break;
    case 'admin_inventory_delete':
        // action file handles checks + redirect
        require APP_PATH . 'admin/inventory_delete.php';
        break;

    // ----- Loans (admin) -----
    case 'admin_loans':
        view('admin/loans.php');
        break;
    case 'loan_approve':
        // action: expects POST loan_id
        require APP_PATH . 'admin/loan_approve.php';
        break;
    case 'loan_reject':
        require APP_PATH . 'admin/loan_reject.php';
        break;

    // ----- Users (admin) -----
    case 'admin_users_list':
        view('admin/users_list.php');
        break;
    case 'toggle_blacklist':
        require APP_PATH . 'admin/toggle_blacklist.php';
        break;
    case 'admin_delete_user':
        require APP_PATH . 'admin/delete_user.php';
        break;
    case 'admin_edit_user':
        // optional: admin edit form handler/view
        // create a view('admin/user_edit.php');
        break;

    // ----- User pages -----
    case 'user_request_loan':
    case 'request_loan': // alias
        view('user/request_loan.php');
        break;
    case 'history':
        view('user/history.php');
        break;
    case 'catalog':
        view('user/catalog.php');
        break;

    // ----- Dashboard / home -----
    case 'admin_dashboard':
    case 'home':
    default:
        // show dashboard based on role
        if ($currentUser['role'] === 'admin') {
            view('admin/dashboard.php');
        } else {
            view('user/dashboard.php');
        }
        break;

    case 'upload_document':
        view('user/upload_document.php');
        break;

    case 'upload_return_document':
        view('user/upload_return_document.php');
        break;

    case 'final_approve':
        require APP_PATH . 'admin/final_approve.php';
        break;
        
    case 'final_reject':
        require APP_PATH . 'admin/final_reject.php';
        break;

    case 'admin_categories':
        view('admin/categories.php');
        break;

    case 'admin_returns':
        view('admin/returns.php');
        break;

    // ----- Loan Tracking (Admin) -----
    case 'admin_loan_tracking':
        view('admin/loan_tracking.php');
        break;

    // ----- Suggestions & Notifications -----
    case 'admin_suggestions':
        view('admin/suggestions.php');
        break;

    case 'admin_notifications':
        view('admin/notifications.php');
        break;

    case 'user_suggestions':
        view('user/suggestions.php');
        break;

    case 'user_notifications':
        view('user/notifications.php');
        break;

    // ----- Request Feature (Employee) -----
    case 'user_request_item':
        view('user/request_item.php');
        break;

    case 'user_request_history':
        view('user/request_history.php');
        break;

    case 'upload_request_document':
        view('user/upload_request_document.php');
        break;

    // ----- Request Feature (Admin) -----
    case 'admin_requests':
        view('admin/requests.php');
        break;

    // ----- Template Management (Admin) -----
    case 'admin_templates':
        view('admin/templates.php');
        break;

    // ----- Document Generation (Admin) -----
    case 'admin_generate_document':
        view('admin/generate_document.php');
        break;

    case 'upload_generated_document':
        require APP_PATH . 'admin/upload_generated_document.php';
        break;

    case 'save_generated_document':
        require APP_PATH . 'admin/save_generated_document.php';
        break;

    // ----- Saved Documents (Admin) -----
    case 'admin_saved_documents':
        view('admin/saved_documents.php');
        break;

    // ----- Settings (Admin) -----
    case 'admin_settings':
        view('admin/settings.php');
        break;

    // ----- Change Password (All Users) -----
    case 'change_password':
        view('user/change_password.php');
        break;

}
?>
