<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$notifikasiId = $data['notifikasi_id'] ?? null;
$userId = $data['user_id'] ?? null;

if (!$notifikasiId || !$userId) {
    echo json_encode([
        'success' => false, 
        'message' => 'Notifikasi ID dan User ID wajib diisi'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "UPDATE tnotifikasi_penerima 
         SET is_read = 1, read_at = NOW() 
         WHERE notifikasi_id = :notifikasi_id 
           AND user_id = :user_id
           AND is_read = 0"
    );
    
    $stmt->execute([
        ':notifikasi_id' => $notifikasiId,
        ':user_id' => $userId
    ]);
    
    $rowsAffected = $stmt->rowCount();
    
    if ($rowsAffected > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Notifikasi berhasil ditandai sebagai dibaca'
        ]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT is_read 
             FROM tnotifikasi_penerima 
             WHERE notifikasi_id = :notifikasi_id 
               AND user_id = :user_id"
        );
        
        $stmt->execute([
            ':notifikasi_id' => $notifikasiId,
            ':user_id' => $userId
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Notifikasi sudah ditandai sebagai dibaca sebelumnya'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Notifikasi tidak ditemukan atau Anda bukan penerima notifikasi ini'
            ]);
        }
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}