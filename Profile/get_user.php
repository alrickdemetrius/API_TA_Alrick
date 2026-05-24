<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$input = json_decode(file_get_contents("php://input"), true);

$username = $input['username'] ?? '';

if (!$username) {
    echo json_encode([
        'success' => false,
        'message' => 'Username tidak diterima'
    ]);
    exit;
}

try {
    $sql = "SELECT id as user_id,tanggal_lahir, nama, username, email, no_telp,
            profile_picture, alamat, role FROM tUser WHERE username = :username LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'user_id' => $user['user_id'],
            'username' => $user['username'],
            'nama' => $user['nama'],
            "foto" => $user['profile_picture'],
            "email" => $user["email"],
            "no_telp" => $user["no_telp"],
            "alamat" => $user["alamat"],
            "tanggal_lahir" => $user["tanggal_lahir"],
        ],
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}
