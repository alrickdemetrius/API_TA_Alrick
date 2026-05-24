<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$slots_to_delete = $data['slots_to_delete'] ?? [];
$slots_to_insert = $data['slots_to_insert'] ?? [];

if (!is_array($slots_to_delete) || !is_array($slots_to_insert)) {
    echo json_encode([
        'success' => false,
        'message' => 'Data tidak valid'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $deleted = 0;
    $inserted = 0;
    $errors = [];

    foreach ($slots_to_delete as $slot_id) {
        if (!is_numeric($slot_id)) {
            $errors[] = "Invalid slot_id: $slot_id";
            continue;
        }
        $infoStmt = $pdo->prepare("SELECT hari, jam_mulai, jam_selesai FROM tslot_jadwal WHERE id = :slot_id");
        $infoStmt->execute([':slot_id' => $slot_id]);
        $slotInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);

        $hariMap = ['1' => 'Senin', '2' => 'Selasa', '3' => 'Rabu', '4' => 'Kamis', '5' => 'Jumat', '6' => 'Sabtu', '7' => 'Minggu'];
        $namaHari = $slotInfo ? ($hariMap[$slotInfo['hari']] ?? 'Hari ' . $slotInfo['hari']) : "ID $slot_id";
        $jamMulai = $slotInfo ? substr($slotInfo['jam_mulai'], 0, 5) : '-';
        $jamSelesai = $slotInfo ? substr($slotInfo['jam_selesai'], 0, 5) : '-';

        $checkJadwal = $pdo->prepare("SELECT COUNT(*) as count FROM tjadwal_les WHERE jadwal_slot_id = :slot_id");
        $checkJadwal->execute([':slot_id' => $slot_id]);
        $countJadwal = (int) $checkJadwal->fetch(PDO::FETCH_ASSOC)['count'];

        if ($countJadwal > 0) {
            $errors[] = "Slot $namaHari $jamMulai - $jamSelesai masih digunakan oleh $countJadwal jadwal aktif, tidak dapat dihapus";
            continue;
        }

        $deleteQuery = "DELETE FROM tslot_jadwal WHERE id = :slot_id";
        $deleteStmt = $pdo->prepare($deleteQuery);
        $deleteStmt->bindParam(':slot_id', $slot_id, PDO::PARAM_INT);

        if ($deleteStmt->execute()) {
            $deleted += $deleteStmt->rowCount();
        } else {
            $errors[] = "Failed to delete slot_id: $slot_id";
        }
    }

    foreach ($slots_to_insert as $slot) {
        $hari = $slot['hari'] ?? null;
        $jam_mulai = $slot['jam_mulai'] ?? null;
        $jam_selesai = $slot['jam_selesai'] ?? null;

        if (!$hari || !$jam_mulai || !$jam_selesai) {
            $errors[] = "Incomplete slot data";
            continue;
        }

        if (
            !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $jam_mulai) ||
            !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $jam_selesai)
        ) {
            $errors[] = "Invalid time format for $jam_mulai - $jam_selesai";
            continue;
        }

        if (strlen($jam_mulai) == 5) {
            $jam_mulai .= ':00';
        }
        if (strlen($jam_selesai) == 5) {
            $jam_selesai .= ':00';
        }

        $dupQuery = "SELECT COUNT(*) as count FROM tslot_jadwal 
                     WHERE hari = :hari 
                     AND jam_mulai = :jam_mulai 
                     AND jam_selesai = :jam_selesai";

        $dupStmt = $pdo->prepare($dupQuery);
        $dupStmt->bindParam(':hari', $hari);
        $dupStmt->bindParam(':jam_mulai', $jam_mulai);
        $dupStmt->bindParam(':jam_selesai', $jam_selesai);
        $dupStmt->execute();
        $dupResult = $dupStmt->fetch(PDO::FETCH_ASSOC);

        if ($dupResult['count'] > 0) {
            $errors[] = "Duplicate slot: $jam_mulai - $jam_selesai already exists for this day";
            continue;
        }

        $insertQuery = "INSERT INTO tslot_jadwal (hari, jam_mulai, jam_selesai, created_at) 
                        VALUES (:hari, :jam_mulai, :jam_selesai, NOW())";

        $insertStmt = $pdo->prepare($insertQuery);
        $insertStmt->bindParam(':hari', $hari);
        $insertStmt->bindParam(':jam_mulai', $jam_mulai);
        $insertStmt->bindParam(':jam_selesai', $jam_selesai);

        if ($insertStmt->execute()) {
            $inserted++;
        } else {
            $errors[] = "Failed to insert slot: $jam_mulai - $jam_selesai";
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Perubahan slot jadwal berhasil disimpan',
        'deleted' => $deleted,
        'inserted' => $inserted,
        'errors' => $errors
    ]);

} catch (Exception $e) {
    $pdo->rollBack();

    echo json_encode([
        'success' => false,
        'message' => 'Gagal menyimpan perubahan: ' . $e->getMessage()
    ]);
}