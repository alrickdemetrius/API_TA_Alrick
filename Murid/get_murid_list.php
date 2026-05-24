<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data['user_id'] ?? null;
$role = $data['role'] ?? null;
$forTugas = $data['for_tugas'] ?? false;

if (!$user_id || !$role) {
    echo json_encode([
        'success' => false,
        'message' => 'Parameter tidak lengkap'
    ]);
    exit;
}

try {
    if ($role === 'admin') {
        if ($forTugas) {
            $query = "SELECT DISTINCT
                        m.id as murid_id,
                        u.nama as nama_murid,
                        m.grade
                    FROM tuser u 
                    INNER JOIN tmurid m ON u.id = m.user_id
                    WHERE u.deleted_at IS NULL
                    ORDER BY u.nama ASC";
        } else {
            $query = "SELECT 
                        m.id as murid_id,
                        u.nama as nama,
                        u2.nama as nama_guru,
                        m.grade as grade,
                        u.tanggal_lahir as tanggal_lahir,
                        u.no_telp as no_telp,
                        u.alamat as alamat,
                        u.email as email,
                        u.profile_picture as foto,
                        s.hari as jadwal_hari,
                        TIME_FORMAT(s.jam_mulai, '%H:%i') as jadwal_mulai,
                        TIME_FORMAT(s.jam_selesai, '%H:%i') as jadwal_selesai
                    FROM tuser u 
                    INNER JOIN tmurid m ON u.id = m.user_id
                    INNER JOIN tjadwal_les j ON m.id = j.murid_id
                    INNER JOIN tguru g ON j.guru_id = g.id
                    INNER JOIN tuser u2 ON u2.id = g.user_id
                    LEFT JOIN tslot_jadwal s ON s.id = j.jadwal_slot_id
                    WHERE u.deleted_at IS NULL
                    ORDER BY u.nama ASC";
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $murid = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'role' => 'admin',
            'data' => $murid
        ]);
        exit;
    }

    if ($role === 'guru') {
        if ($forTugas) {
            $query = "SELECT DISTINCT
                        m.id as murid_id,
                        u.nama as nama_murid,
                        m.grade
                    FROM tuser u 
                    INNER JOIN tmurid m ON u.id = m.user_id
                    INNER JOIN tjadwal_les j ON m.id = j.murid_id
                    INNER JOIN tguru g ON j.guru_id = g.id
                    WHERE g.user_id = :uId
                      AND u.deleted_at IS NULL
                    ORDER BY u.nama ASC";
        } else {
            $query = "SELECT 
                        m.id as murid_id,
                        u.nama as nama,
                        u2.nama as nama_guru,
                        m.grade as grade,
                        u.tanggal_lahir as tanggal_lahir,
                        u.no_telp as no_telp,
                        u.alamat as alamat,
                        u.email as email,
                        u.profile_picture as foto,
                        s.hari as jadwal_hari,
                        TIME_FORMAT(s.jam_mulai, '%H:%i') as jadwal_mulai,
                        TIME_FORMAT(s.jam_selesai, '%H:%i') as jadwal_selesai
                    FROM tuser u 
                    INNER JOIN tmurid m ON u.id = m.user_id
                    INNER JOIN tjadwal_les j ON m.id = j.murid_id
                    INNER JOIN tguru g ON j.guru_id = g.id
                    INNER JOIN tuser u2 ON u2.id = g.user_id
                    LEFT JOIN tslot_jadwal s ON s.id = j.jadwal_slot_id
                    WHERE g.user_id = :uId
                      AND u.deleted_at IS NULL
                    ORDER BY u.nama ASC";
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute([':uId' => $user_id]);
        $murid = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'role' => $role,
            'data' => $murid
        ]);
        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => 'Role tidak valid'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}