<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

$data = json_decode(file_get_contents("php://input"), true);

$requestId    = $data['request_id'] ?? null;
$action       = $data['action'] ?? null;
$alasanSelesai= trim($data['alasan_selesai'] ?? '');
$userId       = $data['user_id'] ?? null;

if (!$requestId || !$action) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap']);
    exit;
}

if (!in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmtRequest = $pdo->prepare(
        "SELECT * FROM trequest_jadwal WHERE id = :request_id"
    );
    $stmtRequest->execute([':request_id' => $requestId]);
    $request = $stmtRequest->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        throw new Exception('Request tidak ditemukan');
    }

    if ($request['status'] !== 'pending') {
        throw new Exception('Request sudah diproses sebelumnya (status: ' . $request['status'] . ')');
    }

    $now = date('Y-m-d H:i:s');
    $newStatus = ($action === 'approve') ? 'approved' : 'rejected';

    $stmtUpdate = $pdo->prepare(
        "UPDATE trequest_jadwal 
         SET status = :status, alasan_selesai = :alasan_selesai, updated_at = :updated_at 
         WHERE id = :request_id"
    );
    $stmtUpdate->execute([
        ':status'        => $newStatus,
        ':alasan_selesai'=> $alasanSelesai ?: null,
        ':updated_at'    => $now,
        ':request_id'    => $requestId
    ]);

    $jenis       = $request['jenis'];
    $muridId     = $request['murid_id'];
    $jadwalLesId = $request['jadwal_les_id'];
    $tanggal     = $request['tanggal'];
    $jamMulai    = $request['jam_mulai'];
    $jamSelesai  = $request['jam_selesai'];

    $stmtMuridGuru = $pdo->prepare("
        SELECT 
            um.id as murid_user_id,
            um.nama as nama_murid,
            ug.id as guru_user_id,
            ug.nama as nama_guru
        FROM tmurid m
        INNER JOIN tuser um ON um.id = m.user_id
        LEFT JOIN tjadwal_les j ON j.murid_id = m.id AND j.status_aktif = 1
        LEFT JOIN tguru g ON g.id = j.guru_id
        LEFT JOIN tuser ug ON ug.id = g.user_id
        WHERE m.id = :murid_id
        LIMIT 1
    ");
    $stmtMuridGuru->execute([':murid_id' => $muridId]);
    $info = $stmtMuridGuru->fetch(PDO::FETCH_ASSOC);

    if (!$info) {
        throw new Exception('Data murid/guru tidak ditemukan untuk notifikasi');
    }

    if ($action === 'approve') {
        $stmtCekRutin = $pdo->prepare("
            SELECT p.id
            FROM tpresensi_les p
            INNER JOIN tjadwal_les j  ON j.id  = p.jadwal_id
            INNER JOIN tslot_jadwal s ON s.id  = j.jadwal_slot_id
            WHERE j.murid_id    = :murid_id
              AND p.tanggal     = :tanggal
              AND p.status      = 'aktif'
              AND p.jenis       = 'rutin'
              AND s.jam_mulai   < :jam_selesai
              AND s.jam_selesai > :jam_mulai
            LIMIT 1
        ");
        $stmtCekRutin->execute([
            ':murid_id'    => $muridId,
            ':tanggal'     => $tanggal,
            ':jam_mulai'   => $jamMulai,
            ':jam_selesai' => $jamSelesai
        ]);
        if ($stmtCekRutin->fetch()) {
            throw new Exception('Tidak dapat disetujui: murid sudah memiliki jadwal rutin aktif pada waktu tersebut');
        }

        $stmtCekNonRutin = $pdo->prepare("
            SELECT id FROM trequest_jadwal
            WHERE murid_id    = :murid_id
              AND tanggal     = :tanggal
              AND status      = 'approved'
              AND id         != :request_id
              AND jam_mulai   < :jam_selesai
              AND jam_selesai > :jam_mulai
            LIMIT 1
        ");
        $stmtCekNonRutin->execute([
            ':murid_id'    => $muridId,
            ':tanggal'     => $tanggal,
            ':request_id'  => $requestId,
            ':jam_mulai'   => $jamMulai,
            ':jam_selesai' => $jamSelesai
        ]);
        if ($stmtCekNonRutin->fetch()) {
            throw new Exception('Tidak dapat disetujui: murid sudah memiliki jadwal tambahan/pengganti pada waktu tersebut');
        }

        $stmtCekGuru = $pdo->prepare("
            SELECT p.id
            FROM tpresensi_les p
            INNER JOIN tjadwal_les j  ON j.id  = p.jadwal_id
            INNER JOIN tslot_jadwal s ON s.id  = j.jadwal_slot_id
            INNER JOIN tguru g        ON g.id  = j.guru_id
            INNER JOIN tjadwal_les jm ON jm.guru_id = g.id AND jm.murid_id = :murid_id AND jm.status_aktif = 1
            WHERE p.tanggal       = :tanggal
              AND p.status        = 'aktif'
              AND p.jenis         = 'rutin'
              AND s.jam_mulai     < :jam_selesai
              AND s.jam_selesai   > :jam_mulai
              AND j.murid_id     != :murid_id
            LIMIT 1
        ");
        $stmtCekGuru->execute([
            ':murid_id'    => $muridId,
            ':tanggal'     => $tanggal,
            ':jam_mulai'   => $jamMulai,
            ':jam_selesai' => $jamSelesai
        ]);
        if ($stmtCekGuru->fetch()) {
            throw new Exception('Tidak dapat disetujui: guru sedang mengajar murid lain pada waktu tersebut');
        }

        $stmtCekGuruNonRutin = $pdo->prepare("
            SELECT rj.id
            FROM trequest_jadwal rj
            INNER JOIN tmurid m       ON m.id  = rj.murid_id
            INNER JOIN tjadwal_les j  ON j.murid_id = m.id AND j.status_aktif = 1
            INNER JOIN tguru g        ON g.id  = j.guru_id
            INNER JOIN tjadwal_les jm ON jm.guru_id = g.id AND jm.murid_id = :murid_id AND jm.status_aktif = 1
            WHERE rj.tanggal     = :tanggal
              AND rj.status      = 'approved'
              AND rj.jam_mulai   < :jam_selesai
              AND rj.jam_selesai > :jam_mulai
              AND rj.murid_id   != :murid_id
            LIMIT 1
        ");
        $stmtCekGuruNonRutin->execute([
            ':murid_id'    => $muridId,
            ':tanggal'     => $tanggal,
            ':jam_mulai'   => $jamMulai,
            ':jam_selesai' => $jamSelesai
        ]);
        if ($stmtCekGuruNonRutin->fetch()) {
            throw new Exception('Tidak dapat disetujui: guru sedang mengajar murid lain (jadwal tambahan/pengganti) pada waktu tersebut');
        }

        $targetJadwalId = $jadwalLesId;

        if ($jenis === 'tambahan') {
            $stmtFind = $pdo->prepare(
                "SELECT id FROM tjadwal_les WHERE murid_id = :murid_id AND status_aktif = 1 LIMIT 1"
            );
            $stmtFind->execute([':murid_id' => $muridId]);
            $jadwalFound = $stmtFind->fetch(PDO::FETCH_ASSOC);

            if (!$jadwalFound) {
                throw new Exception('Murid belum memiliki jadwal les aktif');
            }

            $targetJadwalId = (int)$jadwalFound['id'];
        }

        $stmtPresensi = $pdo->prepare(
            "INSERT INTO tpresensi_les (jadwal_id, pengganti_id, tanggal, jenis, status, created_at)
             VALUES (:jadwal_id, NULL, :tanggal, :jenis, 'aktif', :created_at)"
        );
        $stmtPresensi->execute([
            ':jadwal_id'  => $targetJadwalId,
            ':tanggal'    => $tanggal,
            ':jenis'      => $jenis,
            ':created_at' => $now
        ]);

        $newPresensiId = (int)$pdo->lastInsertId();

        if ($jenis === 'pengganti' && $jadwalLesId) {

            $stmtOriginal = $pdo->prepare(
                "SELECT id FROM tpresensi_les 
                 WHERE jadwal_id = :jadwal_id 
                   AND status   = 'aktif'
                   AND jenis    = 'rutin'
                   AND id      != :new_presensi_id
                 ORDER BY ABS(DATEDIFF(tanggal, CURDATE()))
                 LIMIT 1"
            );
            $stmtOriginal->execute([
                ':jadwal_id'      => $jadwalLesId,
                ':new_presensi_id' => $newPresensiId
            ]);
            $original = $stmtOriginal->fetch(PDO::FETCH_ASSOC);

            if ($original) {
                $originalPresensiId = (int)$original['id'];
                $pdo->prepare(
                    "UPDATE tpresensi_les 
                     SET status = 'diganti', pengganti_id = :pengganti_id, updated_at = :updated_at 
                     WHERE id = :presensi_id"
                )->execute([
                    ':pengganti_id' => $newPresensiId,
                    ':presensi_id'  => $originalPresensiId,
                    ':updated_at'   => $now
                ]);
            }
        }

        createNotifikasi($pdo, [
            'jenis' => 'request_diterima',
            'reference_type' => 'request',
            'reference_id' => $requestId,
            'role_pengirim' => 'admin',
            'user_pengirim_id' => $userId,
            'context' => [
                'nama_murid'     => $info['nama_murid'],
                'jenis_request'  => $jenis,
                'tanggal'        => date('d/m/Y', strtotime($tanggal)),
                'jam_mulai'      => substr($jamMulai, 0, 5),
                'jam_selesai'    => substr($jamSelesai, 0, 5),
                'alasan_selesai' => $alasanSelesai ?: null,
                'guru_user_id'   => $info['guru_user_id'],
                'murid_user_id'  => $info['murid_user_id']
            ]
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Request berhasil disetujui dan jadwal telah dibuat',
            'data'    => [
                'request_id'     => $requestId,
                'new_presensi_id' => $newPresensiId,
                'status'         => 'approved'
            ]
        ]);

    } else {
        createNotifikasi($pdo, [
            'jenis' => 'request_ditolak',
            'reference_type' => 'request',
            'reference_id' => $requestId,
            'role_pengirim' => 'admin',
            'user_pengirim_id' => $userId,
            'context' => [
                'nama_murid'     => $info['nama_murid'],
                'jenis_request'  => $jenis,
                'alasan_selesai' => $alasanSelesai ?: null,
                'guru_user_id'   => $info['guru_user_id'],
                'murid_user_id'  => $info['murid_user_id']
            ]
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Request berhasil ditolak',
            'data'    => [
                'request_id' => $requestId,
                'status'     => 'rejected'
            ]
        ]);
    }

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("DB error selesai_request: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}