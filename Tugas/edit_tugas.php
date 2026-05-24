<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['tugas_id'])) {
    echo json_encode(['success' => false, 'message' => 'tugas_id wajib diisi']);
    exit;
}
if (!isset($data['judul']) || !isset($data['deskripsi']) || !isset($data['deadline'])) {
    echo json_encode(['success' => false, 'message' => 'Judul, deskripsi, dan deadline wajib diisi']);
    exit;
}

$tugasId = (int) $data['tugas_id'];
$judul = trim($data['judul']);
$deskripsi = trim($data['deskripsi']);
$deadline = $data['deadline'];
$isDinilai = isset($data['goal_id']) && !empty($data['goal_id']) ? 1 : 0;
$goalId = $isDinilai ? (int) $data['goal_id'] : null;
$rubrikId = $isDinilai ? ($data['rubrik_id'] ?? null) : null;
$guruUserId = $data['guru_user_id'] ?? null;

$materiIds = [];
if (isset($data['materi_ids']) && is_array($data['materi_ids'])) {
    $materiIds = array_values(array_filter(array_map('intval', $data['materi_ids'])));
}

try {
    $pdo->beginTransaction();

    $stmtCheck = $pdo->prepare("
        SELECT t.id, t.status, t.is_dinilai AS old_is_dinilai,
               ug.id AS guru_user_id, ug.nama AS nama_guru,
               um.id AS murid_user_id, um.nama AS nama_murid
        FROM ttugas t
        INNER JOIN tjadwal_les j ON j.id = t.jadwal_id
        INNER JOIN tguru g ON g.id = j.guru_id
        INNER JOIN tuser ug ON ug.id = g.user_id
        INNER JOIN tmurid m ON m.id  = j.murid_id
        INNER JOIN tuser um ON um.id = m.user_id
        WHERE t.id = ?
    ");
    $stmtCheck->execute([$tugasId]);
    $tugasInfo = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$tugasInfo)
        throw new Exception('Tugas tidak ditemukan');
    if ($tugasInfo['status'] !== 'belum_dikerjakan') {
        throw new Exception('Hanya tugas dengan status "Belum Dikerjakan" yang dapat diubah');
    }

    if (!$guruUserId)
        $guruUserId = $tugasInfo['guru_user_id'];
    $now = date('Y-m-d H:i:s');

    $stmtTugas = $pdo->prepare("
        UPDATE ttugas SET judul = ?, deskripsi_tugas = ?, deadline = ?,
        is_dinilai = ?, goal_id = ?, updated_at = ?
        WHERE id = ?
    ");
    $stmtTugas->execute([$judul, $deskripsi, $deadline, $isDinilai, $goalId, $now, $tugasId]);

    $pdo->prepare("DELETE FROM ttugas_materi WHERE tugas_id = ?")->execute([$tugasId]);

    if (!empty($materiIds)) {
        $stmtMateri = $pdo->prepare("
            INSERT INTO ttugas_materi (tugas_id, materi_id, created_at) VALUES (?, ?, ?)
        ");
        foreach ($materiIds as $materiId) {
            $stmtMateri->execute([$tugasId, $materiId, $now]);
        }
    }

    if ($isDinilai && $goalId && $rubrikId) {
        $stmtGetP = $pdo->prepare("SELECT id FROM tpenilaian WHERE tugas_id = ? LIMIT 1");
        $stmtGetP->execute([$tugasId]);
        $existingPId = $stmtGetP->fetchColumn();

        if ($existingPId) {
            $pdo->prepare("
                UPDATE tdetail_nilai SET rubrik_goal_id = ?, updated_at = ?
                WHERE penilaian_id = ? AND sumber = 'tugas'
            ")->execute([(int) $rubrikId, $now, $existingPId]);
        } else {
            $stmtP = $pdo->prepare("
                INSERT INTO tpenilaian (goal_id, tugas_id, created_at) VALUES (?, ?, ?)
            ");
            $stmtP->execute([$goalId, $tugasId, $now]);
            $penilaianId = (int) $pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO tdetail_nilai (penilaian_id, rubrik_goal_id, sumber, created_at)
                VALUES (?, ?, 'tugas', ?)
            ")->execute([$penilaianId, (int) $rubrikId, $now]);
        }

    } elseif ($tugasInfo['old_is_dinilai'] == 1 && $isDinilai == 0) {
        $stmtDelP = $pdo->prepare("SELECT id FROM tpenilaian WHERE tugas_id = ? LIMIT 1");
        $stmtDelP->execute([$tugasId]);
        $pId = $stmtDelP->fetchColumn();
        if ($pId) {
            $pdo->prepare("DELETE FROM tdetail_nilai WHERE penilaian_id = ? AND sumber = 'tugas'")->execute([$pId]);
            $pdo->prepare("DELETE FROM tpenilaian WHERE id = ?")->execute([$pId]);
        }
    }


    $pdo->commit();

    try {
        createNotifikasi($pdo, [
            'jenis' => 'tugas_ubah',
            'reference_type' => 'tugas',
            'reference_id' => $tugasId,
            'role_pengirim' => 'guru',
            'user_pengirim_id' => $guruUserId,
            'context' => [
                'nama_tugas' => $judul,
                'nama_murid' => $tugasInfo['nama_murid'],
                'nama_guru' => $tugasInfo['nama_guru'],
                'guru_user_id' => $tugasInfo['guru_user_id'],
                'murid_user_id' => $tugasInfo['murid_user_id']
            ]
        ]);
    } catch (Exception $eNotif) {
        error_log('[edit_tugas] Notifikasi gagal: ' . $eNotif->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Tugas berhasil diubah',
        'data' => [
            'tugas_id' => $tugasId,
            'judul' => $judul,
            'deadline' => $deadline,
            'is_dinilai' => (bool) $isDinilai,
            'materi_count' => count($materiIds)
        ]
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}