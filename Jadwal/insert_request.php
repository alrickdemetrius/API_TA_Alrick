<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

$data = json_decode(file_get_contents("php://input"), true);

$jenis        = $data['jenis'] ?? null;
$requestDari  = $data['request_dari'] ?? null;
$role         = $data['role'] ?? null;
$tanggal      = $data['tanggal'] ?? null;
$jamMulai     = $data['jam_mulai'] ?? null;
$jamSelesai   = $data['jam_selesai'] ?? null;

if (!$jenis || !$requestDari || !$role || !$tanggal || !$jamMulai || !$jamSelesai) {
    echo json_encode(['success' => false, 'message' => 'Parameter wajib tidak lengkap']);
    exit;
}
if (!in_array($jenis, ['pengganti', 'tambahan'])) {
    echo json_encode(['success' => false, 'message' => 'Jenis request tidak valid']);
    exit;
}
if (!in_array($role, ['murid', 'guru', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Role tidak valid']);
    exit;
}
if ($jamMulai >= $jamSelesai) {
    echo json_encode(['success' => false, 'message' => 'Jam selesai harus lebih besar dari jam mulai']);
    exit;
}

// Validasi durasi minimal 1 jam
$mulai   = strtotime("1970-01-01 $jamMulai");
$selesai = strtotime("1970-01-01 $jamSelesai");
if (($selesai - $mulai) < 3600) {
    echo json_encode(['success' => false, 'message' => 'Durasi jadwal minimal 1 jam']);
    exit;
}

try {
    $pdo->beginTransaction();

    $now = date('Y-m-d H:i:s');
    $muridId = null;
    $jadwalLesId = null;
    $presensiIdOriginal = null;
    $tanggalLama = null;

    if ($jenis === 'pengganti') {
        $presensiIdOriginal = $data['presensi_id'] ?? null;
        
        if (!$presensiIdOriginal) {
            throw new Exception('Presensi ID wajib diisi untuk jadwal pengganti');
        }

        $stmtPresensi = $pdo->prepare(
            "SELECT p.jadwal_id, p.tanggal, j.murid_id 
             FROM tpresensi_les p
             INNER JOIN tjadwal_les j ON j.id = p.jadwal_id
             WHERE p.id = :presensi_id"
        );
        $stmtPresensi->execute([':presensi_id' => $presensiIdOriginal]);
        $presensi = $stmtPresensi->fetch(PDO::FETCH_ASSOC);

        if (!$presensi) {
            throw new Exception('Data presensi tidak ditemukan');
        }

        $jadwalLesId = (int)$presensi['jadwal_id'];
        $muridId = $presensi['murid_id'];
        $tanggalLama = $presensi['tanggal'];

    } else if ($jenis === 'tambahan') {
        if ($role === 'murid') {
            $stmtMurid = $pdo->prepare("SELECT id FROM tmurid WHERE user_id = :user_id");
            $stmtMurid->execute([':user_id' => $requestDari]);
            $muridData = $stmtMurid->fetch(PDO::FETCH_ASSOC);

            if (!$muridData) {
                throw new Exception('Data murid tidak ditemukan untuk user ini');
            }
            $muridId = $muridData['id'];

        } else {
            $muridId = $data['murid_id'] ?? null;

            if (!$muridId) {
                throw new Exception('Murid ID wajib dipilih');
            }

            $stmtCheck = $pdo->prepare("SELECT id FROM tmurid WHERE id = :murid_id");
            $stmtCheck->execute([':murid_id' => $muridId]);
            if ($stmtCheck->rowCount() === 0) {
                throw new Exception('Data murid tidak valid');
            }
        }
    }

    $status = ($role === 'admin') ? 'approved' : 'pending';

    $keterangan = $data['keterangan'] ?? null;

    $stmtCekRutin = $pdo->prepare("
        SELECT p.id
        FROM tpresensi_les p
        INNER JOIN tjadwal_les j  ON j.id  = p.jadwal_id
        INNER JOIN tslot_jadwal s ON s.id  = j.jadwal_slot_id
        WHERE j.murid_id      = :murid_id
          AND p.tanggal       = :tanggal
          AND p.status        = 'aktif'
          AND p.jenis         = 'rutin'
          AND s.jam_mulai     < :jam_selesai
          AND s.jam_selesai   > :jam_mulai
        LIMIT 1
    ");
    $stmtCekRutin->execute([
        ':murid_id'    => $muridId,
        ':tanggal'     => $tanggal,
        ':jam_mulai'   => $jamMulai,
        ':jam_selesai' => $jamSelesai
    ]);
    if ($stmtCekRutin->fetch()) {
        throw new Exception('Murid sudah memiliki jadwal rutin aktif pada waktu tersebut');
    }

    $stmtCekNonRutin = $pdo->prepare("
        SELECT rj.id
        FROM trequest_jadwal rj
        WHERE rj.murid_id    = :murid_id
          AND rj.tanggal     = :tanggal
          AND rj.status      = 'approved'
          AND rj.jam_mulai   < :jam_selesai
          AND rj.jam_selesai > :jam_mulai
        LIMIT 1
    ");
    $stmtCekNonRutin->execute([
        ':murid_id'    => $muridId,
        ':tanggal'     => $tanggal,
        ':jam_mulai'   => $jamMulai,
        ':jam_selesai' => $jamSelesai
    ]);
    if ($stmtCekNonRutin->fetch()) {
        throw new Exception('Murid sudah memiliki jadwal tambahan/pengganti pada waktu tersebut');
    }

    $stmtCekPending = $pdo->prepare("
        SELECT id FROM trequest_jadwal
        WHERE murid_id    = :murid_id
          AND tanggal     = :tanggal
          AND status      = 'pending'
          AND jam_mulai   < :jam_selesai
          AND jam_selesai > :jam_mulai
        LIMIT 1
    ");
    $stmtCekPending->execute([
        ':murid_id'    => $muridId,
        ':tanggal'     => $tanggal,
        ':jam_mulai'   => $jamMulai,
        ':jam_selesai' => $jamSelesai
    ]);
    if ($stmtCekPending->fetch()) {
        throw new Exception('Sudah ada pengajuan pending untuk murid pada waktu tersebut');
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
        throw new Exception('Guru sedang mengajar murid lain pada waktu tersebut');
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
        throw new Exception('Guru sedang mengajar murid lain (jadwal tambahan/pengganti) pada waktu tersebut');
    }

    $stmtInsert = $pdo->prepare(
        "INSERT INTO trequest_jadwal 
         (request_dari, jenis, jadwal_les_id, murid_id, tanggal, jam_mulai, jam_selesai, status, keterangan, created_at)
         VALUES (:request_dari, :jenis, :jadwal_les_id, :murid_id, :tanggal, :jam_mulai, :jam_selesai, :status, :keterangan, :created_at)"
    );

    $stmtInsert->execute([
        ':request_dari'  => $requestDari,
        ':jenis'         => $jenis,
        ':jadwal_les_id' => $jadwalLesId,
        ':murid_id'      => $muridId,
        ':tanggal'       => $tanggal,
        ':jam_mulai'     => $jamMulai,
        ':jam_selesai'   => $jamSelesai,
        ':status'        => $status,
        ':keterangan'    => $keterangan,
        ':created_at'    => $now
    ]);

    $requestId = (int)$pdo->lastInsertId();

    $stmtPengirim = $pdo->prepare("SELECT nama FROM tuser WHERE id = :user_id");
    $stmtPengirim->execute([':user_id' => $requestDari]);
    $pengirim = $stmtPengirim->fetch(PDO::FETCH_ASSOC);
    $namaPengirim = $pengirim ? $pengirim['nama'] : 'Unknown';

    $stmtMuridGuru = $pdo->prepare("
        SELECT um.id as murid_user_id, um.nama as nama_murid, ug.id as guru_user_id, ug.nama as nama_guru
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

    if ($status === 'pending') {
        $notifJenis = ($jenis === 'tambahan') ? 'request_tambahan' : 'request_pengganti';
        
        $context = [
            'nama_pengirim' => $namaPengirim,
            'nama_murid'    => $info['nama_murid'],
            'tanggal'       => date('d/m/Y', strtotime($tanggal)),
            'jam_mulai'     => substr($jamMulai, 0, 5),
            'jam_selesai'   => substr($jamSelesai, 0, 5),
            'keterangan'    => $keterangan ?: null,
            'guru_user_id'  => $info['guru_user_id'],
            'murid_user_id' => $info['murid_user_id']
        ];

        if ($jenis === 'pengganti' && $tanggalLama) {
            $context['tanggal_lama'] = date('d/m/Y', strtotime($tanggalLama));
            $context['tanggal_baru'] = date('d/m/Y', strtotime($tanggal));
        }

        createNotifikasi($pdo, [
            'jenis' => $notifJenis,
            'reference_type' => 'request',
            'reference_id' => $requestId,
            'role_pengirim' => $role,
            'user_pengirim_id' => $requestDari,
            'context' => $context
        ]);
    }

    if ($role === 'admin' && $status === 'approved') {
        
        $targetJadwalId = $jadwalLesId;
        if ($jenis === 'tambahan') {
            $stmtFind = $pdo->prepare(
                "SELECT id FROM tjadwal_les WHERE murid_id = :murid_id AND status_aktif = 1 LIMIT 1"
            );
            $stmtFind->execute([':murid_id' => $muridId]);
            $jadwalFound = $stmtFind->fetch(PDO::FETCH_ASSOC);

            if (!$jadwalFound) {
                throw new Exception('Murid belum memiliki jadwal les aktif. Tidak dapat membuat jadwal tambahan.');
            }

            $targetJadwalId = (int)$jadwalFound['id'];
        }

        $stmtPresensiInsert = $pdo->prepare(
            "INSERT INTO tpresensi_les (jadwal_id, pengganti_id, tanggal, jenis, status, created_at)
             VALUES (:jadwal_id, NULL, :tanggal, :jenis, 'aktif', :created_at)"
        );
        $stmtPresensiInsert->execute([
            ':jadwal_id'  => $targetJadwalId,
            ':tanggal'    => $tanggal,
            ':jenis'      => $jenis,
            ':created_at' => $now
        ]);

        $newPresensiId = (int)$pdo->lastInsertId();

        if ($jenis === 'pengganti' && $presensiIdOriginal) {
            $stmtUpdate = $pdo->prepare(
                "UPDATE tpresensi_les 
                 SET status = 'diganti', pengganti_id = :pengganti_id, updated_at = :updated_at 
                 WHERE id = :presensi_id"
            );
            $stmtUpdate->execute([
                ':pengganti_id' => $newPresensiId,
                ':presensi_id'  => $presensiIdOriginal,
                ':updated_at'   => $now
            ]);
        }

        createNotifikasi($pdo, [
            'jenis' => 'request_diterima',
            'reference_type' => 'request',
            'reference_id' => $requestId,
            'role_pengirim' => 'admin',
            'user_pengirim_id' => $requestDari,
            'context' => [
                'nama_murid' => $info['nama_murid'],
                'jenis_request' => $jenis,
                'tanggal' => date('d/m/Y', strtotime($tanggal)),
                'jam_mulai' => substr($jamMulai, 0, 5),
                'jam_selesai' => substr($jamSelesai, 0, 5),
                'guru_user_id' => $info['guru_user_id'],
                'murid_user_id' => $info['murid_user_id']
            ]
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $role === 'admin' ? 'Request berhasil dibuat dan langsung disetujui' : 'Request berhasil dibuat',
        'data'    => [
            'request_id' => $requestId,
            'status'     => $status,
            'jenis'      => $jenis,
            'murid_id'   => $muridId
        ]
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("DB error insert_request: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}