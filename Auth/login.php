<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$input = json_decode(file_get_contents("php://input"), true);

$username = $input['username'] ?? '';
$password = $input['password'] ?? '';

if (!$username || !$password) {
    echo json_encode([
        'success' => false,
        'message' => 'Username dan password wajib diisi'
    ]);
    exit;
}

try {
    $sql = "SELECT id,nama, profile_picture as foto, username, password, role FROM tUser WHERE username = :username LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {

        $token = bin2hex(random_bytes(32));

        echo json_encode([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'nama' => $user['nama'],
                'foto' => $user['foto'],
            ],
            'role' => $user['role'],
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Username atau password salah'
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}
