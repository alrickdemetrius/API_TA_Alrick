<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

$data = json_decode(file_get_contents("php://input"), true);

$guru_id = $data['guru_id'] ?? null;
$slots = $data['slots'] ?? null;

if (!$guru_id || !$slots || !is_array($slots)) {
    echo json_encode([
        'success' => false,
        'message' => 'Data tidak lengkap'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $updated = 0;
    $errors = [];
    $jadwalNonaktifList = [];

    foreach ($slots as $slot) {
        $ketersediaan_id = $slot['ketersediaan_id'] ?? null;
        $status_aktif = isset($slot['status_aktif']) ? (int) $slot['status_aktif'] : 0;

        if (!$ketersediaan_id) {
            $errors[] = "Missing ketersediaan_id for slot";
            continue;
        }
        $stmtInfo = $pdo->prepare("
            SELECT kg.slot_jadwal_id, kg.status_aktif AS status_lama,
                   s.hari, DATE_FORMAT(s.jam_mulai,'%H:%i') AS jam_mulai,
                   DATE_FORMAT(s.jam_selesai,'%H:%i') AS jam_selesai
            FROM tketersediaan_guru kg
            INNER JOIN tslot_jadwal s ON s.id = kg.slot_jadwal_id
            WHERE kg.id = ? AND kg.guru_id = ?
        ");
        $stmtInfo->execute([$ketersediaan_id, $guru_id]);
        $infoSlot = $stmtInfo->fetch(PDO::FETCH_ASSOC);

        $query = "UPDATE tketersediaan_guru 
                  SET status_aktif = :status_aktif, updated_at = NOW()
                  WHERE id = :ketersediaan_id AND guru_id = :guru_id";

        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':status_aktif', $status_aktif, PDO::PARAM_INT);
        $stmt->bindParam(':ketersediaan_id', $ketersediaan_id, PDO::PARAM_INT);
        $stmt->bindParam(':guru_id', $guru_id, PDO::PARAM_STR);

        if ($stmt->execute()) {
            $updated += $stmt->rowCount();
        } else {
            $errors[] = "Failed to update ketersediaan_id: $ketersediaan_id";
            continue;
        }

        if ($status_aktif === 0 && $infoSlot && (int) $infoSlot['status_lama'] === 1) {
            $slotId = (int) $infoSlot['slot_jadwal_id'];

            $stmtJadwal = $pdo->prepare("
                SELECT jl.id AS jadwal_id, jl.murid_id,
                       um.id AS murid_user_id, um.nama AS nama_murid,
                       ug.id AS guru_user_id, ug.nama AS nama_guru
                FROM tjadwal_les jl
                INNER JOIN tmurid m  ON m.id  = jl.murid_id
                INNER JOIN tuser um  ON um.id = m.user_id
                INNER JOIN tguru g   ON g.id  = jl.guru_id
                INNER JOIN tuser ug  ON ug.id = g.user_id
                WHERE jl.guru_id        = ?
                  AND jl.jadwal_slot_id = ?
                  AND jl.status_aktif   = 1
            ");
            $stmtJadwal->execute([$guru_id, $slotId]);
            $terdampak = $stmtJadwal->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($terdampak)) {
                $pdo->prepare("
                    UPDATE tjadwal_les SET status_aktif = 0, updated_at = NOW()
                    WHERE guru_id = ? AND jadwal_slot_id = ? AND status_aktif = 1
                ")->execute([$guru_id, $slotId]);

                $hariLabel = [
                    '1' => 'Senin',
                    '2' => 'Selasa',
                    '3' => 'Rabu',
                    '4' => 'Kamis',
                    '5' => 'Jumat',
                    '6' => 'Sabtu',
                    '7' => 'Minggu'
                ];
                foreach ($terdampak as $j) {
                    $jadwalNonaktifList[] = [
                        'nama_murid' => $j['nama_murid'],
                        'nama_guru' => $j['nama_guru'],
                        'hari_label' => $hariLabel[$infoSlot['hari']] ?? $infoSlot['hari'],
                        'jam_mulai' => $infoSlot['jam_mulai'],
                        'jam_selesai' => $infoSlot['jam_selesai'],
                        'guru_user_id' => $j['guru_user_id'],
                        'murid_user_id' => $j['murid_user_id'],
                        'murid_id' => $j['murid_id'],
                    ];
                }
            }
        }
    }

    $pdo->commit();

    foreach ($jadwalNonaktifList as $ctx) {
        try {
            createNotifikasi($pdo, [
                'jenis' => 'jadwal_nonaktif',
                'reference_type' => 'murid',
                'reference_id' => $ctx['murid_id'],
                'role_pengirim' => 'sistem',
                'user_pengirim_id' => null,
                'context' => $ctx
            ]);
        } catch (Exception $eNotif) {
            error_log('[simpan_ketersediaan] Notifikasi gagal: ' . $eNotif->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Ketersediaan berhasil disimpan',
        'updated' => $updated,
        'total_slots' => count($slots),
        'jadwal_nonaktif' => count($jadwalNonaktifList),
        'errors' => $errors
    ]);

} catch (Exception $e) {
    $pdo->rollBack();

    echo json_encode([
        'success' => false,
        'message' => 'Gagal menyimpan ketersediaan: ' . $e->getMessage()
    ]);
}