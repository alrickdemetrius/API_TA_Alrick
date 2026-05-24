<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data    = json_decode(file_get_contents("php://input"), true);
$muridId = $data['murid_id'] ?? null;
$callerId = $data['caller_id'] ?? null;

if (!$muridId) {
    echo json_encode(['success' => false, 'message' => 'murid_id wajib diisi']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT m.id AS murid_id, u.id AS user_id, u.nama, u.deleted_at
        FROM tmurid m
        INNER JOIN tuser u ON u.id = m.user_id
        WHERE m.id = :murid_id
    ");
    $stmt->execute([':murid_id' => $muridId]);
    $murid = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$murid) {
        echo json_encode(['success' => false, 'message' => 'Data murid tidak ditemukan']);
        exit;
    }

    if ($murid['deleted_at'] !== null) {
        echo json_encode(['success' => false, 'message' => 'Murid sudah dinonaktifkan sebelumnya']);
        exit;
    }

    // Soft delete — hanya update deleted_at di tuser
    $pdo->prepare("UPDATE tuser SET deleted_at = NOW() WHERE id = :user_id")
        ->execute([':user_id' => $murid['user_id']]);

    echo json_encode([
        'success' => true,
        'message' => 'Murid berhasil dinonaktifkan',
        'data'    => [
            'murid_id' => $muridId,
            'user_id'  => $murid['user_id'],
            'nama'     => $murid['nama']
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
