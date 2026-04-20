<?php
require 'auth_check.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
</head>
<body>
<style>
    body {
        min-height: 100vh;
        margin: 0;
        display: flex;
        flex-direction: column;
    }
</style>
<?php
// Only show nav bar if not on dashboard page (for employee dashboard, nav is handled by dashboard UI)
if (basename($_SERVER['PHP_SELF']) !== 'dashboard.php' || strpos($_SERVER['PHP_SELF'], 'admin') !== false) {
?>
<?php } ?>