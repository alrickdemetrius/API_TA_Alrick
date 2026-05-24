<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$userId = $data['user_id'] ?? null;

if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'User ID wajib diisi']);
    exit;
}

try {
    $query = "SELECT COUNT(*) as unread_count
              FROM tnotifikasi_penerima
              WHERE user_id = :user_id 
                AND is_read = 0";

    $stmt = $pdo->prepare($query);
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'count' => (int)$result['unread_count']
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}