<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
} else {
    $data = $_GET;
}

$targetMonth = $data['target_month'] ?? date('Y-m'); // Default: current month
$autoMode = isset($data['auto']) && $data['auto'] === 'true'; // Auto mode (no checks)

function checkMonthGeneration($pdo, $targetMonth)
{
    list($year, $month) = explode('-', $targetMonth);
    $firstDay = "$year-$month-01";
    $lastDay = date('Y-m-t', strtotime($firstDay));

    $sql = "SELECT COUNT(*) as count 
            FROM tpresensi_les 
            WHERE tanggal BETWEEN :first AND :last 
              AND jenis = 'rutin'";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':first' => $firstDay, ':last' => $lastDay]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'generated' => $result['count'] > 0,
        'count' => (int) $result['count']
    ];
}

if (isset($data['check_only']) && $data['check_only'] === 'true') {
    $check = checkMonthGeneration($pdo, $targetMonth);

    echo json_encode([
        'success' => true,
        'target_month' => $targetMonth,
        'already_generated' => $check['generated'],
        'existing_count' => $check['count']
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $startTime = microtime(true);
    $now = date('Y-m-d H:i:s');

    if (!preg_match('/^\d{4}-\d{2}$/', $targetMonth)) {
        throw new Exception('Invalid target_month format. Use YYYY-MM');
    }

    list($year, $month) = explode('-', $targetMonth);
    $firstDay = "$year-$month-01";
    $lastDay = date('Y-m-t', strtotime($firstDay));

    $sqlGetSchedules = "
        SELECT 
            j.id as jadwal_id,
            j.guru_id,
            j.murid_id,
            s.hari,
            s.jam_mulai,
            s.jam_selesai
        FROM tjadwal_les j
        INNER JOIN tslot_jadwal s ON s.id = j.jadwal_slot_id
        WHERE j.status_aktif = 1
          AND j.murid_id IS NOT NULL
        ORDER BY j.id, s.hari
    ";

    $stmtSchedules = $pdo->prepare($sqlGetSchedules);
    $stmtSchedules->execute();
    $schedules = $stmtSchedules->fetchAll(PDO::FETCH_ASSOC);

    if (empty($schedules)) {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'No active schedules found',
            'data' => [
                'target_month' => $targetMonth,
                'schedules_processed' => 0,
                'presensi_generated' => 0
            ]
        ]);
        exit;
    }

    $presensiToInsert = [];
    $duplicateCheck = [];
    $bentrokList = [];

    $stmtNamaInfo = $pdo->prepare("
        SELECT j.id AS jadwal_id, um.nama AS nama_murid, ug.nama AS nama_guru
        FROM tjadwal_les j
        INNER JOIN tmurid m  ON m.id  = j.murid_id
        INNER JOIN tuser um  ON um.id = m.user_id
        INNER JOIN tguru g   ON g.id  = j.guru_id
        INNER JOIN tuser ug  ON ug.id = g.user_id
        WHERE j.status_aktif = 1
    ");
    $stmtNamaInfo->execute();
    $namaInfoMap = [];
    foreach ($stmtNamaInfo->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $namaInfoMap[$row['jadwal_id']] = [
            'nama_murid' => $row['nama_murid'],
            'nama_guru' => $row['nama_guru']
        ];
    }

    foreach ($schedules as $schedule) {
        $jadwalId = (int) $schedule['jadwal_id'];
        $hari = (int) $schedule['hari'];

        $dates = generateDatesForDayOfWeek($firstDay, $lastDay, $hari);

        foreach ($dates as $date) {
            $checkKey = "{$jadwalId}_{$date}";

            if (isset($duplicateCheck[$checkKey])) {
                continue;
            }

            $sqlCheck = "SELECT id FROM tpresensi_les 
                         WHERE jadwal_id = :jadwal_id 
                           AND tanggal = :tanggal 
                           AND jenis = 'rutin'";

            $stmtCheck = $pdo->prepare($sqlCheck);
            $stmtCheck->execute([
                ':jadwal_id' => $jadwalId,
                ':tanggal' => $date
            ]);

            if ($stmtCheck->rowCount() > 0) {
                continue;
            }

            $stmtCekRequest = $pdo->prepare("
                SELECT id FROM trequest_jadwal
                WHERE murid_id    = :murid_id
                  AND tanggal     = :tanggal
                  AND status      = 'approved'
                  AND jam_mulai   < :jam_selesai
                  AND jam_selesai > :jam_mulai
                LIMIT 1
            ");
            $stmtCekRequest->execute([
                ':murid_id' => $schedule['murid_id'],
                ':tanggal' => $date,
                ':jam_mulai' => $schedule['jam_mulai'],
                ':jam_selesai' => $schedule['jam_selesai']
            ]);
            $requestBentrok = $stmtCekRequest->fetch(PDO::FETCH_ASSOC);

            $statusInsert = $requestBentrok ? 'diganti' : 'aktif';
            $presensiToInsert[] = [
                'jadwal_id' => $jadwalId,
                'tanggal' => $date,
                'jenis' => 'rutin',
                'status' => $statusInsert,
                'created_at' => $now
            ];

            if ($requestBentrok) {
                $info = $namaInfoMap[$jadwalId] ?? ['nama_murid' => '-', 'nama_guru' => '-'];
                $bentrokList[] = [
                    'nama_murid' => $info['nama_murid'],
                    'nama_guru' => $info['nama_guru'],
                    'tanggal' => $date,
                    'jam_mulai' => substr($schedule['jam_mulai'], 0, 5),
                    'jam_selesai' => substr($schedule['jam_selesai'], 0, 5),
                ];
            }

            $duplicateCheck[$checkKey] = true;
        }
    }

    if (empty($presensiToInsert)) {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'All presensi already exist for this month',
            'data' => [
                'target_month' => $targetMonth,
                'schedules_processed' => count($schedules),
                'presensi_generated' => 0,
                'presensi_skipped' => 'All already exist'
            ]
        ]);
        exit;
    }

    $sqlInsert = "INSERT INTO tpresensi_les 
                  (jadwal_id, pengganti_id, tanggal, jenis, status, waktu_presensi, created_at)
                  VALUES (:jadwal_id, NULL, :tanggal, :jenis, :status, NULL, :created_at)";

    $stmtInsert = $pdo->prepare($sqlInsert);

    $insertedCount = 0;
    foreach ($presensiToInsert as $presensi) {
        $stmtInsert->execute([
            ':jadwal_id' => $presensi['jadwal_id'],
            ':tanggal' => $presensi['tanggal'],
            ':jenis' => $presensi['jenis'],
            ':status' => $presensi['status'],
            ':created_at' => $presensi['created_at']
        ]);
        $insertedCount++;
    }

    $pdo->commit();

    if (!empty($bentrokList)) {
        require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

        $bulanLabel = date('F Y', strtotime("$targetMonth-01"));
        $jumlahBentrok = count($bentrokList);

        $detailLines = [];
        foreach ($bentrokList as $b) {
            $tglFormatted = date('d/m/Y', strtotime($b['tanggal']));
            $detailLines[] = "• {$b['nama_murid']} (Guru: {$b['nama_guru']}) — {$tglFormatted} {$b['jam_mulai']}-{$b['jam_selesai']}";
        }

        try {
            createNotifikasi($pdo, [
                'jenis' => 'generate_presensi_bentrok',
                'reference_type' => 'system',
                'reference_id' => 0,
                'role_pengirim' => 'sistem',
                'user_pengirim_id' => null,
                'context' => [
                    'bulan' => $bulanLabel,
                    'jumlah' => $jumlahBentrok,
                    'detail_lines' => $detailLines,
                    'bentrok_list' => $bentrokList
                ]
            ]);
        } catch (Exception $eNotif) {
            error_log('[generate_presensi] Notifikasi bentrok gagal: ' . $eNotif->getMessage());
        }
    }

    $executionTime = round(microtime(true) - $startTime, 2);

    echo json_encode([
        'success' => true,
        'message' => "Successfully generated $insertedCount presensi records for $targetMonth",
        'data' => [
            'target_month' => $targetMonth,
            'date_range' => "$firstDay to $lastDay",
            'schedules_processed' => count($schedules),
            'presensi_generated' => $insertedCount,
            'presensi_bentrok' => count($bentrokList),
            'execution_time_seconds' => $executionTime,
            'generated_at' => $now
        ]
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("DB error generate_presensi: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error generate_presensi: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function generateDatesForDayOfWeek($firstDay, $lastDay, $dayOfWeek)
{
    $dates = [];

    $targetDayOfWeek = $dayOfWeek;

    $current = new DateTime($firstDay);
    $end = new DateTime($lastDay);

    $currentDayOfWeek = (int) $current->format('N');

    if ($currentDayOfWeek < $targetDayOfWeek) {
        $daysToAdd = $targetDayOfWeek - $currentDayOfWeek;
    } elseif ($currentDayOfWeek > $targetDayOfWeek) {
        $daysToAdd = 7 - ($currentDayOfWeek - $targetDayOfWeek);
    } else {
        $daysToAdd = 0;
    }

    $current->modify("+{$daysToAdd} days");

    while ($current <= $end) {
        $dates[] = $current->format('Y-m-d');
        $current->modify('+7 days');
    }

    return $dates;
}