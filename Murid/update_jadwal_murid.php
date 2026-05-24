<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['murid_id']) || empty($data['murid_id'])) {
    echo json_encode(['success' => false, 'message' => 'Murid ID wajib diisi']);
    exit;
}

$muridId   = trim($data['murid_id']);
$guruId    = isset($data['guru_id']) && trim($data['guru_id']) !== '' ? trim($data['guru_id']) : null;
$slotId    = isset($data['slot_id']) && $data['slot_id'] !== null ? (int)$data['slot_id'] : null;


try {
    $pdo->beginTransaction();

    $stmtMurid = $pdo->prepare("SELECT id FROM tmurid WHERE id = :murid_id");
    $stmtMurid->execute([':murid_id' => $muridId]);
    if ($stmtMurid->rowCount() === 0) {
        throw new Exception('Data murid tidak ditemukan');
    }

    $stmtCheck = $pdo->prepare(
        "SELECT id FROM tjadwal_les WHERE murid_id = :murid_id AND status_aktif = 1"
    );
    $stmtCheck->execute([':murid_id' => $muridId]);
    $existingJadwal = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    $now = date('Y-m-d H:i:s');

    if ($guruId === null && $slotId === null) {
        if ($existingJadwal) {
            $stmtDelete = $pdo->prepare(
                "UPDATE tjadwal_les SET status_aktif = 0, updated_at = :updated_at 
                 WHERE id = :jadwal_id"
            );
            $stmtDelete->execute([
                ':jadwal_id'  => $existingJadwal['id'],
                ':updated_at' => $now
            ]);
            
            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Jadwal berhasil dihapus',
                'action'  => 'deleted'
            ]);
        } else {
            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Tidak ada jadwal yang perlu dihapus',
                'action'  => 'none'
            ]);
        }
        exit;
    }

    if ($guruId === null || $slotId === null) {
        throw new Exception('Guru dan slot harus dipilih keduanya atau kosong keduanya');
    }

    $stmtGuru = $pdo->prepare(
        "SELECT g.id FROM tguru g INNER JOIN tmurid m ON m.id = :murid_id
         WHERE g.id = :guru_id AND g.min_grade <= m.grade AND g.max_grade >= m.grade"
    );
    $stmtGuru->execute([':guru_id' => $guruId, ':murid_id' => $muridId]);
    if ($stmtGuru->rowCount() === 0) {
        throw new Exception('Guru tidak valid atau tidak sesuai grade murid');
    }

    $stmtSlot = $pdo->prepare("SELECT id FROM tslot_jadwal WHERE id = :slot_id");
    $stmtSlot->execute([':slot_id' => $slotId]);
    if ($stmtSlot->rowCount() === 0) {
        throw new Exception('Slot jadwal tidak valid');
    }

    $stmtConflict = $pdo->prepare(
        "SELECT id FROM tjadwal_les 
         WHERE guru_id = :guru_id 
           AND jadwal_slot_id = :slot_id 
           AND status_aktif = 1
           AND murid_id != :murid_id"
    );
    $stmtConflict->execute([
        ':guru_id'  => $guruId,
        ':slot_id'  => $slotId,
        ':murid_id' => $muridId
    ]);
    if ($stmtConflict->rowCount() > 0) {
        throw new Exception('Guru sudah memiliki jadwal pada slot tersebut');
    }

    if ($existingJadwal) {
        $stmtUpdate = $pdo->prepare(
            "UPDATE tjadwal_les 
             SET guru_id = :guru_id, 
                 jadwal_slot_id = :slot_id,
                 updated_at = :updated_at
             WHERE id = :jadwal_id"
        );
        $stmtUpdate->execute([
            ':guru_id'    => $guruId,
            ':slot_id'    => $slotId,
            ':updated_at' => $now,
            ':jadwal_id'  => $existingJadwal['id']
        ]);

        $pdo->commit();

        try {
            $stmtInfo = $pdo->prepare("
                SELECT um.id AS murid_user_id, um.nama AS nama_murid,
                       ug.id AS guru_user_id, ug.nama AS nama_guru,
                       s.hari,
                       DATE_FORMAT(s.jam_mulai,'%H:%i')   AS jam_mulai,
                       DATE_FORMAT(s.jam_selesai,'%H:%i') AS jam_selesai
                FROM tmurid m
                INNER JOIN tuser um       ON um.id = m.user_id
                INNER JOIN tguru g        ON g.id  = :guru_id
                INNER JOIN tuser ug       ON ug.id = g.user_id
                INNER JOIN tslot_jadwal s ON s.id  = :slot_id
                WHERE m.id = :murid_id
            ");
            $stmtInfo->execute([':guru_id'=>$guruId, ':slot_id'=>$slotId, ':murid_id'=>$muridId]);
            $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
            if ($info) {
                $hariLabel = ['1'=>'Senin','2'=>'Selasa','3'=>'Rabu','4'=>'Kamis','5'=>'Jumat','6'=>'Sabtu','7'=>'Minggu'];
                createNotifikasi($pdo, [
                    'jenis' => 'jadwal_diubah',
                    'reference_type' => 'murid',
                    'reference_id' => $muridId,
                    'role_pengirim' => 'admin',
                    'user_pengirim_id' => null,
                    'context' => [
                        'nama_murid' => $info['nama_murid'],
                        'nama_guru' => $info['nama_guru'],
                        'hari_label' => $hariLabel[$info['hari']] ?? $info['hari'],
                        'jam_mulai' => $info['jam_mulai'],
                        'jam_selesai' => $info['jam_selesai'],
                        'guru_user_id' => $info['guru_user_id'],
                        'murid_user_id' => $info['murid_user_id']
                    ]
                ]);
            }
        } catch (Exception $eNotif) {
            error_log('[update_jadwal] Notifikasi gagal: ' . $eNotif->getMessage());
        }

        echo json_encode([
            'success'   => true,
            'message'   => 'Jadwal berhasil diupdate',
            'action'    => 'updated',
            'jadwal_id' => (int)$existingJadwal['id']
        ]);

    } else {
        $stmtInsert = $pdo->prepare(
            "INSERT INTO tjadwal_les (status_aktif, guru_id, jadwal_slot_id, murid_id, created_at)
             VALUES (1, :guru_id, :slot_id, :murid_id, :created_at)"
        );
        $stmtInsert->execute([
            ':guru_id'    => $guruId,
            ':slot_id'    => $slotId,
            ':murid_id'   => $muridId,
            ':created_at' => $now
        ]);

        $jadwalId = (int)$pdo->lastInsertId();

        $pdo->commit();

        try {
            $stmtInfo = $pdo->prepare("
                SELECT um.id AS murid_user_id, um.nama AS nama_murid,
                       ug.id AS guru_user_id, ug.nama AS nama_guru,
                       s.hari,
                       DATE_FORMAT(s.jam_mulai,'%H:%i')   AS jam_mulai,
                       DATE_FORMAT(s.jam_selesai,'%H:%i') AS jam_selesai
                FROM tmurid m
                INNER JOIN tuser um       ON um.id = m.user_id
                INNER JOIN tguru g        ON g.id  = :guru_id
                INNER JOIN tuser ug       ON ug.id = g.user_id
                INNER JOIN tslot_jadwal s ON s.id  = :slot_id
                WHERE m.id = :murid_id
            ");
            $stmtInfo->execute([':guru_id'=>$guruId, ':slot_id'=>$slotId, ':murid_id'=>$muridId]);
            $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
            if ($info) {
                $hariLabel = ['1'=>'Senin','2'=>'Selasa','3'=>'Rabu','4'=>'Kamis','5'=>'Jumat','6'=>'Sabtu','7'=>'Minggu'];
                createNotifikasi($pdo, [
                    'jenis' => 'jadwal_diubah',
                    'reference_type' => 'murid',
                    'reference_id' => $muridId,
                    'role_pengirim' => 'admin',
                    'user_pengirim_id' => null,
                    'context'  => [
                        'nama_murid' => $info['nama_murid'],
                        'nama_guru' => $info['nama_guru'],
                        'hari_label' => $hariLabel[$info['hari']] ?? $info['hari'],
                        'jam_mulai' => $info['jam_mulai'],
                        'jam_selesai' => $info['jam_selesai'],
                        'guru_user_id' => $info['guru_user_id'],
                        'murid_user_id' => $info['murid_user_id']
                    ]
                ]);
            }
        } catch (Exception $eNotif) {
            error_log('[update_jadwal] Notifikasi gagal: ' . $eNotif->getMessage());
        }

        echo json_encode([
            'success' => true,
            'message' => 'Jadwal berhasil dibuat',
            'action' => 'inserted',
            'jadwal_id' => $jadwalId
        ]);
    }

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("DB error update_jadwal_murid: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}