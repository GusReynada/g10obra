<?php
require_once __DIR__ . '/../includes/auth.php';
auth_start_session();
if (auth_check()) {
    header('Location: dashboard.php');
} else {
    header('Location: ../index.php');
}
exit;
