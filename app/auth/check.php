<?php
// app/auth/check.php - Check if user is logged in, redirect to login if not
if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}
?>