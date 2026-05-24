<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$guru_id = $data['guru_id'] ?? null;
$user_id = $data['user_id'] ?? null;

if (!$guru_id && !$user_id) {
    echo json_encode(['success' => false, 'message' => 'guru_id atau user_id wajib diisi']);
    exit;
}

try {
    if (!$guru_id && $user_id) {
        $stmtGuru = $pdo->prepare("SELECT id FROM tguru WHERE user_id = ?");
        $stmtGuru->execute([$user_id]);
        $guruRow = $stmtGuru->fetch(PDO::FETCH_ASSOC);
        if (!$guruRow) {
            echo json_encode(['success' => false, 'message' => 'Data guru tidak ditemukan untuk user ini']);
            exit;
        }
        $guru_id = $guruRow['id'];
    }
    // ketersediaan guru dengan join ke slot jadwal
    $query = "SELECT 
                kg.id as ketersediaan_id,
                kg.guru_id,
                kg.slot_jadwal_id,
                kg.status_aktif,
                s.hari,
                s.jam_mulai,
                s.jam_selesai
            FROM tketersediaan_guru kg
            INNER JOIN tslot_jadwal s ON s.id = kg.slot_jadwal_id
            WHERE kg.guru_id = :guru_id
            ORDER BY s.hari, s.jam_mulai";

    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':guru_id', $guru_id, PDO::PARAM_STR);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    foreach ($results as $row) {
        $hari = $row['hari'];
        
        if (!isset($groupedByDay[$hari])) {
            $groupedByDay[$hari] = [
                'hari' => $hari,
                'nama_hari' => $dayNames[$hari] ?? 'Unknown',
                'slots' => []
            ];
        }

        $groupedByDay[$hari]['slots'][] = [
            'ketersediaan_id' => $row['ketersediaan_id'],
            'slot_jadwal_id' => $row['slot_jadwal_id'],
            'jam_mulai' => $row['jam_mulai'],
            'jam_selesai' => $row['jam_selesai'],
            'status_aktif' => (bool)$row['status_aktif']
        ];
    }

    ksort($groupedByDay);
    $responseData = array_values($groupedByDay);


    echo json_encode([
        'success'    => true,
        'data'       => $responseData,
        'total_days' => count($responseData)
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}