<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data     = json_decode(file_get_contents("php://input"), true);
$userId   = $data['user_id']   ?? null;
$callerId = $data['caller_id'] ?? null;

if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'user_id wajib diisi']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, nama, role, deleted_at FROM tuser
        WHERE id = :id AND role != 'murid'
    ");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Data karyawan tidak ditemukan']);
        exit;
    }

    if ($user['deleted_at'] !== null) {
        echo json_encode(['success' => false, 'message' => 'Karyawan sudah dinonaktifkan sebelumnya']);
        exit;
    }

    if ($callerId && $callerId === $userId) {
        echo json_encode(['success' => false, 'message' => 'Tidak dapat menonaktifkan akun sendiri']);
        exit;
    }

    $pdo->prepare("UPDATE tuser SET deleted_at = NOW() WHERE id = :id")
        ->execute([':id' => $userId]);

    echo json_encode([
        'success' => true,
        'message' => 'Karyawan berhasil dinonaktifkan',
        'data'    => [
            'user_id' => $userId,
            'nama'    => $user['nama'],
            'role'    => $user['role']
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
