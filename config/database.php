<?php
date_default_timezone_set('Asia/Bangkok');
$host = 'localhost';
$dbname = 'dms_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Force UTF-8 encoding
    $pdo->exec("SET NAMES utf8mb4");
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
