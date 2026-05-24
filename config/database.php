<?php
$mode = 'production';
// production or local

if ($mode === 'local') {
    $host     = 'localhost';
    $port     = '3306';
    $dbname   = 'ta_piano';
    $username = 'root';
    $password = '';
} else {
    $host     = 'metro.proxy.rlwy.net';
    $port     = '32538';
    $dbname   = 'railway';
    $username = 'root';
    $password = 'RYEiGqTAVpozMoSLhnYwGkpUYqRMiajf';
}

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $username, $password);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => false,
        "message" => "Database connection failed"
    ]);
    exit;
}