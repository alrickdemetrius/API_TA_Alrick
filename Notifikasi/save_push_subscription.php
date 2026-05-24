<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';


$data   = json_decode(file_get_contents("php://input"), true);
$userId = $data['user_id']      ?? null;
$sub    = $data['subscription'] ?? null;

if (!$userId || !$sub) {
    echo json_encode(['success' => false, 'message' => 'user_id dan subscription wajib diisi']);
    exit;
}

if (empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
    echo json_encode(['success' => false, 'message' => 'Data subscription tidak lengkap']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "UPDATE tuser
         SET push_endpoint = :endpoint,
             push_p256dh   = :p256dh,
             push_auth     = :auth,
             updated_at    = NOW()
         WHERE id = :user_id"
    );
    $stmt->execute([
        ':user_id'  => $userId,
        ':endpoint' => $sub['endpoint'],
        ':p256dh'   => $sub['keys']['p256dh'],
        ':auth'     => $sub['keys']['auth']
    ]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Push subscription berhasil disimpan'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}