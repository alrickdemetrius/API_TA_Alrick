<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$hari = $data['hari'] ?? null;

if (!$hari) {
    echo json_encode(['success' => false, 'message' => 'Parameter hari wajib diisi']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT
            id,
            hari,
            TIME_FORMAT(jam_mulai,  '%H:%i') AS jam_mulai,
            TIME_FORMAT(jam_selesai,'%H:%i') AS jam_selesai,
            jam_mulai AS jam_mulai_raw
         FROM tslot_jadwal
         WHERE hari = :hari
         ORDER BY jam_mulai ASC"
    );
    $stmt->execute([':hari' => $hari]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $slots = array_map(function($row) {
        return [
            'id'           => (int)$row['id'],
            'hari'         => $row['hari'],
            'jam_mulai'    => $row['jam_mulai'],
            'jam_selesai'  => $row['jam_selesai'],
            'jam_mulai_raw'=> $row['jam_mulai_raw'],
        ];
    }, $rows);

    echo json_encode([
        'success' => true,
        'data'    => $slots,
        'total'   => count($slots)
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}