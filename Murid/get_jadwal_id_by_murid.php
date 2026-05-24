<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$muridId = $data['murid_id'] ?? null;

if (!$muridId) {
    echo json_encode([
        'success' => false,
        'message' => 'murid_id wajib diisi'
    ]);
    exit;
}

try {
    $query = "SELECT j.id as jadwal_id
              FROM tjadwal_les j
              WHERE j.murid_id = :murid_id
                AND j.status_aktif = 1
              LIMIT 1";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':murid_id', $muridId);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        echo json_encode([
            'success' => true,
            'jadwal_id' => (int)$result['jadwal_id']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Jadwal tidak ditemukan untuk murid ini'
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}