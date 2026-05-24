<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data    = json_decode(file_get_contents("php://input"), true);
$tugasId = isset($data['tugas_id']) ? (int)$data['tugas_id'] : null;

if (!$tugasId) {
    echo json_encode(['success' => false, 'message' => 'tugas_id wajib diisi']);
    exit;
}

try {
    $stmtCheck = $pdo->prepare("
        SELECT t.id, t.status, t.judul, ug.id AS guru_user_id, um.id AS murid_user_id
        FROM ttugas t
        INNER JOIN tjadwal_les jl ON jl.id = t.jadwal_id
        INNER JOIN tguru g ON g.id  = jl.guru_id
        INNER JOIN tuser ug ON ug.id = g.user_id
        INNER JOIN tmurid m ON m.id  = jl.murid_id
        INNER JOIN tuser um ON um.id = m.user_id
        WHERE t.id = ?
    ");
    $stmtCheck->execute([$tugasId]);
    $tugas = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$tugas) {
        echo json_encode(['success' => false, 'message' => 'Tugas tidak ditemukan']);
        exit;
    }

    if (in_array($tugas['status'], ['selesai', 'batal'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Tugas dengan status "' . $tugas['status'] . '" tidak dapat dibatalkan'
        ]);
        exit;
    }

    $pdo->prepare("
        UPDATE ttugas SET status = 'batal', updated_at = NOW() WHERE id = ?
    ")->execute([$tugasId]);

    echo json_encode([
        'success' => true,
        'message' => 'Tugas berhasil dibatalkan',
        'data'    => ['tugas_id' => $tugasId, 'status' => 'batal']
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
