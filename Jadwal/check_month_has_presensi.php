<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$year = $data['year'] ?? null;
$month = $data['month'] ?? null;

if (!$year || !$month) {
    echo json_encode([
        'success' => false,
        'message' => 'Year and month are required'
    ]);
    exit;
}

if ($month < 1 || $month > 12) {
    echo json_encode([
        'success' => false,
        'message' => 'Month must be between 1 and 12'
    ]);
    exit;
}

try {
    $firstDay = sprintf("%04d-%02d-01", $year, $month);
    $lastDay = date('Y-m-t', strtotime($firstDay));

    $stmtJadwal = $pdo->prepare("
        SELECT j.id AS jadwal_id, s.hari
        FROM tjadwal_les j
        INNER JOIN tslot_jadwal s ON s.id = j.jadwal_slot_id
        WHERE j.status_aktif = 1 AND j.murid_id IS NOT NULL
    ");
    $stmtJadwal->execute();
    $jadwals = $stmtJadwal->fetchAll(PDO::FETCH_ASSOC);

    if (empty($jadwals)) {
        echo json_encode([
            'success' => true,
            'all_generated' => true,
            'missing' => 0,
            'total_slots' => 0
        ]);
        exit;
    }

    $missingCount = 0;
    $totalSlots = 0;

    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) FROM tpresensi_les
        WHERE jadwal_id = ? AND tanggal = ? AND jenis = 'rutin'
    ");

    foreach ($jadwals as $jadwal) {
        $current  = new DateTime($firstDay);
        $end = new DateTime($lastDay);
        $targetDay = (int) $jadwal['hari'];

        $curDay = (int) $current->format('N');
        if ($curDay < $targetDay) {
            $current->modify('+' . ($targetDay - $curDay) . ' days');
        } elseif ($curDay > $targetDay) {
            $current->modify('+' . (7 - $curDay + $targetDay) . ' days');
        }

        while ($current <= $end) {
            $totalSlots++;
            $stmtCheck->execute([$jadwal['jadwal_id'], $current->format('Y-m-d')]);
            if ((int) $stmtCheck->fetchColumn() === 0) {
                $missingCount++;
            }
            $current->modify('+7 days');
        }
    }

    echo json_encode([
        'success' => true,
        'all_generated' => $missingCount === 0,
        'missing' => $missingCount,
        'total_slots' => $totalSlots
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}