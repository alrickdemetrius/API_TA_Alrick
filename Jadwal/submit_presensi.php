<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['presensi_id']) || !isset($data['kegiatan']) || !isset($data['catatan'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Data tidak lengkap. Presensi ID, kegiatan, dan catatan wajib diisi.'
        ]);
        exit;
    }
    
    $presensi_id = $data['presensi_id'];
    $kegiatan = $data['kegiatan'];
    $catatan = $data['catatan'];

    $sqlCheckPresensi = "SELECT id FROM tPresensi_les WHERE id = :presensi_id";
    $stmtCheckPresensi = $pdo->prepare($sqlCheckPresensi);
    $stmtCheckPresensi->execute([':presensi_id' => $presensi_id]);
    $existingPresensi = $stmtCheckPresensi->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingPresensi) {
        echo json_encode([
            'success' => false,
            'message' => 'Data presensi tidak ditemukan'
        ]);
        exit;
    }
    
    $waktu_presensi = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    
    try {
        $stmtUpdatePresensi = $pdo->prepare("
            UPDATE tpresensi_les
            SET waktu_presensi = :waktu_presensi,
                kegiatan       = :kegiatan,
                catatan_guru   = :catatan_guru,
                updated_at     = :updated_at
            WHERE id = :id
        ");
        $stmtUpdatePresensi->execute([
            ':waktu_presensi' => $waktu_presensi,
            ':kegiatan'       => $kegiatan,
            ':catatan_guru'   => $catatan,
            ':updated_at'     => $waktu_presensi,
            ':id'             => $presensi_id,
        ]);

        $sqlGetPresensiInfo = "
            SELECT 
                p.id as presensi_id,
                p.tanggal,
                s.jam_mulai,
                s.jam_selesai,
                ug.id as guru_user_id,
                ug.nama as nama_guru,
                um.id as murid_user_id,
                um.nama as nama_murid
            FROM tpresensi_les p
            INNER JOIN tjadwal_les j ON j.id = p.jadwal_id
            INNER JOIN tslot_jadwal s ON s.id = j.jadwal_slot_id
            INNER JOIN tguru g ON g.id = j.guru_id
            INNER JOIN tuser ug ON ug.id = g.user_id
            INNER JOIN tmurid m ON m.id = j.murid_id
            INNER JOIN tuser um ON um.id = m.user_id
            WHERE p.id = :presensi_id
        ";
        
        $stmtInfo = $pdo->prepare($sqlGetPresensiInfo);
        $stmtInfo->execute([':presensi_id' => $presensi_id]);
        $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
        
        if ($info) {
            // Create notification
            createNotifikasi($pdo, [
                'jenis' => 'presensi_submit',
                'reference_type' => 'presensi',
                'reference_id' => $presensi_id,
                'role_pengirim' => 'guru',
                'user_pengirim_id' => $info['guru_user_id'],
                'context' => [
                    'nama_guru' => $info['nama_guru'],
                    'nama_murid' => $info['nama_murid'],
                    'tanggal' => date('d/m/Y', strtotime($info['tanggal'])),
                    'jam_mulai' => substr($info['jam_mulai'], 0, 5), // HH:mm
                    'jam_selesai' => substr($info['jam_selesai'], 0, 5), // HH:mm
                    'guru_user_id' => $info['guru_user_id'],
                    'murid_user_id' => $info['murid_user_id']
                ]
            ]);
        }
        
        // Commit transaksi
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Presensi berhasil disimpan',
            'data' => [
                'presensi_id'    => $presensi_id,
                'waktu_presensi' => $waktu_presensi
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}