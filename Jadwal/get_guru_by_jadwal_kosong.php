<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$hari = $data['hari'] ?? null;
$jam = $data['jam'] ?? null;
$grade = $data['grade'] ?? null;

if (!$hari || !$jam) {
    echo json_encode([
        'success' => false,
        'message' => 'Parameter hari dan jam wajib diisi'
    ]);
    exit;
}

try {
    $params = [
        ':hari' => $hari,
        ':jam' => $jam
    ];

    $gradeFilter = '';
    if ($grade !== null && $grade !== '') {
        $gradeFilter = 'AND g.min_grade <= :grade AND g.max_grade >= :grade';
        $params[':grade'] = (int) $grade;
    }

    $query = "
        SELECT
            g.id AS guru_id,
            u.nama AS nama,
            g.min_grade,
            g.max_grade,
            s.id AS slot_id,
            s.hari,
            TIME_FORMAT(s.jam_mulai,  '%H:%i') AS jam_mulai,
            TIME_FORMAT(s.jam_selesai,'%H:%i') AS jam_selesai
        FROM tslot_jadwal s
        INNER JOIN tketersediaan_guru kg
            ON kg.slot_jadwal_id = s.id
            AND kg.status_aktif = 1
        INNER JOIN tguru g
            ON g.id = kg.guru_id
            $gradeFilter
        INNER JOIN tuser u
            ON u.id = g.user_id
        WHERE s.hari = :hari
          AND s.jam_mulai = :jam
          AND NOT EXISTS (
              SELECT 1
              FROM tjadwal_les j
              WHERE j.guru_id       = g.id
                AND j.jadwal_slot_id = s.id
          )
        ORDER BY u.nama ASC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = array_map(function ($row) {
        return [
            'guru_id' => $row['guru_id'],
            'nama' => $row['nama'],
            'min_grade' => (int) $row['min_grade'],
            'max_grade' => (int) $row['max_grade'],
            'slot_id' => (int) $row['slot_id'],
            'hari' => $row['hari'],
            'jam_mulai' => $row['jam_mulai'],
            'jam_selesai' => $row['jam_selesai'],
            'label' => $row['nama'] . ' (Grade ' . $row['min_grade'] . ' - ' . $row['max_grade'] . ')'
        ];
    }, $rows);

    echo json_encode([
        'success' => true,
        'data' => $result,
        'total' => count($result)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}