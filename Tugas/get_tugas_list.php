<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

// Include terlambat_tugas.php hanya untuk menggunakan function-nya,
// bukan untuk menjalankan kode endpoint-nya langsung.
require_once __DIR__ . '/terlambat_tugas.php';

$data    = json_decode(file_get_contents("php://input"), true);
$role    = $data['role']    ?? null;
$user_id = $data['user_id'] ?? null;

if (!$user_id || !$role) {
    echo json_encode([
        'success' => false,
        'message' => 'Parameter tidak lengkap',
    ]);
    exit;
}

try {
    try {
        $pdo->beginTransaction();
        CekTerlambat($pdo);
        $pdo->commit();
    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Cek Terlambat error: " . $e->getMessage());
    }

    // ── Query list tugas berdasarkan role ────────────────────────────────────
    $baseSelect = "SELECT t.id,
                          um.nama  AS nama_murid,
                          ug.nama  AS nama_guru,
                          t.judul  AS judul,
                          t.deskripsi_tugas AS deskripsi,
                          t.deadline        AS deadline,
                          t.status          AS status_tugas,
                          t.waktu_pengumpulan AS submit_time
                   FROM ttugas t
                   INNER JOIN tjadwal_les j ON j.id  = t.jadwal_id
                   INNER JOIN tmurid m      ON m.id  = j.murid_id
                   INNER JOIN tuser um      ON um.id = m.user_id
                   INNER JOIN tguru g       ON g.id  = j.guru_id
                   INNER JOIN tuser ug      ON ug.id = g.user_id";

    if ($role === 'admin') {
        $query = $baseSelect . "
                   WHERE t.status NOT IN ('selesai', 'batal')
                   ORDER BY t.deadline ASC";
        $stmt  = $pdo->prepare($query);
        $stmt->execute();

    } elseif ($role === 'guru') {
        $query = $baseSelect . "
                   WHERE t.status NOT IN ('selesai', 'batal')
                     AND g.user_id = :userId
                   ORDER BY t.deadline ASC";
        $stmt  = $pdo->prepare($query);
        $stmt->bindParam(':userId', $user_id, PDO::PARAM_STR);
        $stmt->execute();

    } elseif ($role === 'murid') {
        $query = $baseSelect . "
                   WHERE t.status NOT IN ('selesai', 'batal')
                     AND m.user_id = :userId
                   ORDER BY t.deadline ASC";
        $stmt  = $pdo->prepare($query);
        $stmt->bindParam(':userId', $user_id, PDO::PARAM_STR);
        $stmt->execute();

    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Role tidak valid',
        ]);
        exit;
    }

    $tugasBerjalan = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'role'    => $role,
        'data'    => $tugasBerjalan,
        'count'   => count($tugasBerjalan),
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}