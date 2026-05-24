<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data   = json_decode(file_get_contents("php://input"), true);
$userId = $data['user_id'] ?? null;

if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'user_id wajib diisi']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            n.id,
            n.judul,
            n.pesan,
            n.jenis,
            n.created_at
        FROM tnotifikasi n
        INNER JOIN tnotifikasi_penerima np ON np.notifikasi_id = n.id
        WHERE np.user_id    = :user_id
          AND n.jenis       = 'reminder_kelas'
          AND np.is_read    = 0
          AND n.created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([':user_id' => $userId]);
    $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => array_map(fn($r) => [
            'id'         => (int)$r['id'],
            'judul'      => $r['judul'],
            'pesan'      => $r['pesan'],
            'created_at' => $r['created_at']
        ], $reminders)
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
