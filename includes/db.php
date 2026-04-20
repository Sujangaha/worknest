<?php
// Set timezone to Nepal
date_default_timezone_set('Asia/Kathmandu');

// Database connection using PDO
$host = 'localhost';
$db = 'worknest';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:45'"  // Nepal timezone
];


function getPDO() {
    global $dsn, $user, $pass, $options;
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }
    return $pdo;
}
