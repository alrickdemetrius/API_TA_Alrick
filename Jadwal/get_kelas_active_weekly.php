<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$date = $data['date'] ?? null;
$role = $data['role'] ?? null;
$user_id = $data['user_id'] ?? null;

if (!$date || !$role) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap']);
    exit;
}

try {
    $startDate = new DateTime($date);
    $dayOfWeek = $startDate->format('N');
    $daysUntilSunday = 7 - $dayOfWeek;
    $endDate = clone $startDate;
    $endDate->modify("+{$daysUntilSunday} days");

    $startDateStr = $startDate->format('Y-m-d');
    $endDateStr = $endDate->format('Y-m-d');

    if ($role === 'admin') {
        $query = "SELECT p.id, 
                        p.jenis,
                        p.status,
                        ug.nama as nama_guru, 
                        um.nama as nama_murid,
                        p.tanggal,
                        DATE_FORMAT(s.jam_mulai, '%H:%i') as jam_mulai,
                        DATE_FORMAT(s.jam_selesai, '%H:%i') as jam_selesai,
                        p.waktu_presensi,
                        CASE 
                            WHEN p.waktu_presensi IS NOT NULL THEN 1
                            ELSE 0
                        END as sudah_presensi
                    FROM tpresensi_les p 
                    INNER JOIN tjadwal_les j ON j.id = p.jadwal_id
                    INNER JOIN tslot_jadwal s ON s.id = j.jadwal_slot_id
                    INNER JOIN tmurid m ON m.id = j.murid_id
                    INNER JOIN tuser um ON m.user_id = um.id
                    INNER JOIN tguru g ON g.id = j.guru_id
                    INNER JOIN tuser ug ON ug.id = g.user_id
                    WHERE p.tanggal BETWEEN :startDate AND :endDate AND p.status = 'aktif'
                    ORDER BY p.tanggal, s.jam_mulai";

        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':startDate', $startDateStr, PDO::PARAM_STR);
        $stmt->bindParam(':endDate', $endDateStr, PDO::PARAM_STR);
        $stmt->execute();
        $kelas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($kelas as &$k) {
            $k['sudah_presensi'] = (bool)$k['sudah_presensi'];
        }

        echo json_encode([
            'success' => true,
            'role' => 'admin',
            'data' => $kelas,
            'count' => count($kelas),
            'start_date' => $startDateStr,
            'end_date' => $endDateStr
        ]);
        exit;
    }

    if ($role === 'guru') {
        $query = "SELECT p.id, 
                        p.jenis,
                        p.status,
                        ug.nama as nama_guru, 
                        um.nama as nama_murid,
                        p.tanggal,
                        DATE_FORMAT(s.jam_mulai, '%H:%i') as jam_mulai,
                        DATE_FORMAT(s.jam_selesai, '%H:%i') as jam_selesai,
                        p.waktu_presensi,
                        CASE 
                            WHEN p.waktu_presensi IS NOT NULL THEN 1
                            ELSE 0
                        END as sudah_presensi
                    FROM tpresensi_les p 
                    INNER JOIN tjadwal_les j ON j.id = p.jadwal_id
                    INNER JOIN tslot_jadwal s ON s.id = j.jadwal_slot_id
                    INNER JOIN tmurid m ON m.id = j.murid_id
                    INNER JOIN tuser um ON m.user_id = um.id
                    INNER JOIN tguru g ON g.id = j.guru_id
                    INNER JOIN tuser ug ON ug.id = g.user_id
                    WHERE g.user_id = :uId AND p.tanggal BETWEEN :startDate AND :endDate AND p.status = 'aktif'
                    ORDER BY p.tanggal, s.jam_mulai";

        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':uId', $user_id, PDO::PARAM_STR);
        $stmt->bindParam(':startDate', $startDateStr, PDO::PARAM_STR);
        $stmt->bindParam(':endDate', $endDateStr, PDO::PARAM_STR);
        $stmt->execute();
        $kelas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($kelas as &$k) {
            $k['sudah_presensi'] = (bool)$k['sudah_presensi'];
        }

        echo json_encode([
            'success' => true,
            'role' => 'guru',
            'data' => $kelas,
            'count' => count($kelas),
            'start_date' => $startDateStr,
            'end_date' => $endDateStr
        ]);
        exit;
    }

    if ($role === 'murid') {
        $query = "SELECT p.id, 
                        p.jenis,
                        p.status,
                        ug.nama as nama_guru, 
                        um.nama as nama_murid,
                        p.tanggal,
                        DATE_FORMAT(s.jam_mulai, '%H:%i') as jam_mulai,
                        DATE_FORMAT(s.jam_selesai, '%H:%i') as jam_selesai,
                        p.waktu_presensi,
                        CASE 
                            WHEN p.waktu_presensi IS NOT NULL THEN 1
                            ELSE 0
                        END as sudah_presensi
                    FROM tpresensi_les p 
                    INNER JOIN tjadwal_les j ON j.id = p.jadwal_id
                    INNER JOIN tslot_jadwal s ON s.id = j.jadwal_slot_id
                    INNER JOIN tmurid m ON m.id = j.murid_id
                    INNER JOIN tuser um ON m.user_id = um.id
                    INNER JOIN tguru g ON g.id = j.guru_id
                    INNER JOIN tuser ug ON ug.id = g.user_id
                    WHERE m.user_id = :uId AND p.tanggal BETWEEN :startDate AND :endDate AND p.status = 'aktif'
                    ORDER BY p.tanggal, s.jam_mulai";

        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':uId', $user_id, PDO::PARAM_STR);
        $stmt->bindParam(':startDate', $startDateStr, PDO::PARAM_STR);
        $stmt->bindParam(':endDate', $endDateStr, PDO::PARAM_STR);
        $stmt->execute();
        $kelas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($kelas as &$k) {
            $k['sudah_presensi'] = (bool)$k['sudah_presensi'];
        }

        echo json_encode([
            'success' => true,
            'role' => 'murid',
            'data' => $kelas,
            'count' => count($kelas),
            'start_date' => $startDateStr,
            'end_date' => $endDateStr
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Role tidak valid']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}