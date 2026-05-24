<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$date = $data["date"] ?? null;
$role = $data['role'] ?? null;
$user_id = $data['user_id'] ?? null;

$action = $data['action'] ?? 'get_daily';

if ($action === 'get_monthly_dates') {
    $year = $data['year'] ?? null;
    $month = $data['month'] ?? null;
    
    if (!$year || !$month) {
        echo json_encode([
            'success' => false,
            'message' => 'Year and month required for monthly dates'
        ]);
        exit;
    }
    
    try {
        $firstDay = sprintf("%04d-%02d-01", $year, $month);
        $lastDay = date('Y-m-t', strtotime($firstDay));

        if ($role === 'admin') {
            $query = "SELECT DISTINCT DAY(p.tanggal) as day
                     FROM tpresensi_les p
                     WHERE p.tanggal BETWEEN :first_day AND :last_day
                       AND p.status = 'aktif'
                     ORDER BY day";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                ':first_day' => $firstDay,
                ':last_day' => $lastDay
            ]);
        } elseif ($role === 'guru') {
            if (!$user_id) {
                echo json_encode(['success' => false, 'message' => 'User ID required']);
                exit;
            }
            
            $query = "SELECT DISTINCT DAY(p.tanggal) as day
                     FROM tpresensi_les p
                     INNER JOIN tjadwal_les j ON j.id = p.jadwal_id
                     INNER JOIN tguru g ON g.id = j.guru_id
                     WHERE g.user_id = :user_id
                       AND p.tanggal BETWEEN :first_day AND :last_day
                       AND p.status = 'aktif'
                     ORDER BY day";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                ':user_id' => $user_id,
                ':first_day' => $firstDay,
                ':last_day' => $lastDay
            ]);
        } elseif ($role === 'murid') {
            if (!$user_id) {
                echo json_encode(['success' => false, 'message' => 'User ID required']);
                exit;
            }
            
            $query = "SELECT DISTINCT DAY(p.tanggal) as day
                     FROM tpresensi_les p
                     INNER JOIN tjadwal_les j ON j.id = p.jadwal_id
                     INNER JOIN tmurid m ON m.id = j.murid_id
                     WHERE m.user_id = :user_id
                       AND p.tanggal BETWEEN :first_day AND :last_day
                       AND p.status = 'aktif'
                     ORDER BY day";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                ':user_id' => $user_id,
                ':first_day' => $firstDay,
                ':last_day' => $lastDay
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid role']);
            exit;
        }
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dates = array_map(function($row) {
            return (int)$row['day'];
        }, $results);
        
        echo json_encode([
            'success' => true,
            'dates' => $dates,
            'count' => count($dates),
            'month_info' => [
                'year' => (int)$year,
                'month' => (int)$month,
                'date_range' => "$firstDay to $lastDay"
            ]
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

if (!$date || !$role) {
    echo json_encode([
        'success' => false,
        'message' => 'Parameter tidak lengkap'
    ]);
    exit;
}

try {
    if ($role === 'admin') {
        $query = "SELECT p.id, 
                        p.jenis,
                        p.status,
                        ug.nama as nama_guru, 
                        um.nama as nama_murid,
                        DATE_FORMAT(
                            CASE WHEN p.jenis = 'rutin' THEN s.jam_mulai ELSE rj.jam_mulai END,
                            '%H:%i'
                        ) as jam_mulai,
                        DATE_FORMAT(
                            CASE WHEN p.jenis = 'rutin' THEN s.jam_selesai ELSE rj.jam_selesai END,
                            '%H:%i'
                        ) as jam_selesai,
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
                    LEFT JOIN trequest_jadwal rj
                        ON rj.murid_id = m.id
                       AND rj.tanggal  = p.tanggal
                       AND rj.jenis    = p.jenis
                       AND rj.status   = 'approved'
                    WHERE p.tanggal = :date AND p.status = 'aktif'
                    ORDER BY jam_mulai";

        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':date', $date, PDO::PARAM_STR);
        $stmt->execute();
        $kelas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($kelas as &$k) {
            $k['sudah_presensi'] = (bool)$k['sudah_presensi'];
        }

        echo json_encode(['success' => true, 'data' => $kelas, 'count' => count($kelas)]);
        exit;
    }

    if ($role === 'guru') {
        $query = "SELECT p.id, 
                        p.jenis,
                        p.status,
                        ug.nama as nama_guru, 
                        um.nama as nama_murid,
                        DATE_FORMAT(
                            CASE WHEN p.jenis = 'rutin' THEN s.jam_mulai ELSE rj.jam_mulai END,
                            '%H:%i'
                        ) as jam_mulai,
                        DATE_FORMAT(
                            CASE WHEN p.jenis = 'rutin' THEN s.jam_selesai ELSE rj.jam_selesai END,
                            '%H:%i'
                        ) as jam_selesai,
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
                    LEFT JOIN trequest_jadwal rj
                        ON rj.murid_id = m.id
                       AND rj.tanggal  = p.tanggal
                       AND rj.jenis    = p.jenis
                       AND rj.status   = 'approved'
                    WHERE g.user_id = :uId AND p.tanggal = :date AND p.status = 'aktif'
                    ORDER BY jam_mulai";

        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':uId', $user_id, PDO::PARAM_STR);
        $stmt->bindParam(':date', $date, PDO::PARAM_STR);
        $stmt->execute();
        $kelas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($kelas as &$k) {
            $k['sudah_presensi'] = (bool)$k['sudah_presensi'];
        }

        echo json_encode(['success' => true, 'data' => $kelas, 'count' => count($kelas)]);
        exit;
    }

    if ($role === 'murid') {
        $query = "SELECT p.id, 
                        p.jenis,
                        p.status,
                        ug.nama as nama_guru, 
                        um.nama as nama_murid,
                        DATE_FORMAT(
                            CASE WHEN p.jenis = 'rutin' THEN s.jam_mulai ELSE rj.jam_mulai END,
                            '%H:%i'
                        ) as jam_mulai,
                        DATE_FORMAT(
                            CASE WHEN p.jenis = 'rutin' THEN s.jam_selesai ELSE rj.jam_selesai END,
                            '%H:%i'
                        ) as jam_selesai,
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
                    LEFT JOIN trequest_jadwal rj
                        ON rj.murid_id = m.id
                       AND rj.tanggal  = p.tanggal
                       AND rj.jenis    = p.jenis
                       AND rj.status   = 'approved'
                    WHERE m.user_id = :uId AND p.tanggal = :date AND p.status = 'aktif'
                    ORDER BY jam_mulai";

        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':uId', $user_id, PDO::PARAM_STR);
        $stmt->bindParam(':date', $date, PDO::PARAM_STR);
        $stmt->execute();
        $kelas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($kelas as &$k) {
            $k['sudah_presensi'] = (bool)$k['sudah_presensi'];
        }

        echo json_encode(['success' => true, 'data' => $kelas, 'count' => count($kelas)]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Role tidak valid']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}