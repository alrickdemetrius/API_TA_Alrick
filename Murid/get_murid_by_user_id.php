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
    echo json_encode([
        'success' => false,
        'message' => 'user_id is required'
    ]);
    exit;
}

try {
    $query = "SELECT 
                m.id as murid_id,
                m.grade,
                m.user_id,
                u.nik,
                u.nama,
                u.tanggal_lahir,
                u.username,
                u.email,
                u.alamat,
                u.no_telp,
                u.profile_picture as foto
            FROM tmurid m
            INNER JOIN tuser u ON m.user_id = u.id
            WHERE u.id = :user_id";

    $stmt = $pdo->prepare($query);
    $stmt->execute([':user_id' => $userId]);
    $murid = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($murid) {
        echo json_encode([
            'success' => true,
            'data' => $murid
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Data murid tidak ditemukan untuk user_id: ' . $userId
        ]);
    }

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}