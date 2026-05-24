<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

try {
    $query = "SELECT 
                id,
                hari,
                jam_mulai,
                jam_selesai,
                created_at
            FROM tslot_jadwal
            ORDER BY hari, jam_mulai";

    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by day
    $groupedByDay = [];
    $dayNames = [
        '1' => 'Senin',
        '2' => 'Selasa',
        '3' => 'Rabu',
        '4' => 'Kamis',
        '5' => 'Jumat',
        '6' => 'Sabtu',
        '7' => 'Minggu'
    ];

    for ($i = 1; $i <= 7; $i++) {
        $groupedByDay[$i] = [
            'hari' => (string)$i,
            'nama_hari' => $dayNames[$i],
            'slots' => []
        ];
    }

    foreach ($results as $row) {
        $hari = (int)$row['hari'];
        
        $groupedByDay[$hari]['slots'][] = [
            'id' => (int)$row['id'],
            'jam_mulai' => $row['jam_mulai'],
            'jam_selesai' => $row['jam_selesai'],
            'is_new' => false 
        ];
    }

    $data = array_values($groupedByDay);

    echo json_encode([
        'success' => true,
        'data' => $data,
        'total_days' => count($data)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}