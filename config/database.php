<?php
date_default_timezone_set('Asia/Bangkok');

// Machine-specific overrides (git-ignored): copy config/local.example.php to config/local.php
if (is_file(__DIR__ . '/local.php')) require_once __DIR__ . '/local.php';

// Local XAMPP uses the defaults; hosted setups can use environment variables instead.
$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'dms_system';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
// Hosted MySQL (TiDB Cloud, Aiven, ...) requires TLS
if (getenv('DB_SSL') === '1') {
    foreach (['/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt', '/etc/ssl/cert.pem'] as $ca) {
        if (is_file($ca)) { $options[PDO::MYSQL_ATTR_SSL_CA] = $ca; break; }
    }
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
}

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password, $options);
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET time_zone = '+07:00'"); // hosted databases run in UTC
    // MySQL 8 enables ONLY_FULL_GROUP_BY; the reports group by folder ID and show its name
    $pdo->exec("SET SESSION sql_mode = REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', '')");
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    die("Database connection failed");
}

require_once __DIR__ . '/migrate.php';
edmsMigrate($pdo);
?>
