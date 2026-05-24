<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['jadwal_id'])) {
    echo json_encode(['success' => false, 'message' => 'jadwal_id wajib diisi']);
    exit;
}
if (!isset($data['judul']) || !isset($data['deskripsi']) || !isset($data['deadline'])) {
    echo json_encode(['success' => false, 'message' => 'Judul, deskripsi, dan deadline wajib diisi']);
    exit;
}

$jadwalId  = (int)$data['jadwal_id'];
$judul     = trim($data['judul']);
$deskripsi = trim($data['deskripsi']);
$deadline  = $data['deadline'];
$isDinilai = isset($data['goal_id']) && !empty($data['goal_id']) ? 1 : 0;
$goalId    = $isDinilai ? (int)$data['goal_id'] : null;
$rubrikId  = $isDinilai ? ($data['rubrik_id'] ?? null) : null;

$materiIds = [];
if (isset($data['materi_ids']) && is_array($data['materi_ids'])) {
    $materiIds = array_values(array_filter(array_map('intval', $data['materi_ids'])));
}

try {
    $pdo->beginTransaction();
    $now = date('Y-m-d H:i:s');

    $stmtTugas = $pdo->prepare("
        INSERT INTO ttugas (jadwal_id, judul, deskripsi_tugas, deadline, is_dinilai, goal_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtTugas->execute([$jadwalId, $judul, $deskripsi, $deadline, $isDinilai, $goalId, $now]);
    $tugasId = (int)$pdo->lastInsertId();

    if (!empty($materiIds)) {
        $stmtMateri = $pdo->prepare("
            INSERT INTO ttugas_materi (tugas_id, materi_id, created_at) VALUES (?, ?, ?)
        ");
        foreach ($materiIds as $materiId) {
            $stmtMateri->execute([$tugasId, $materiId, $now]);
        }
    }

    if ($isDinilai && $goalId && $rubrikId) {
        $stmtP = $pdo->prepare("
            INSERT INTO tpenilaian (goal_id, tugas_id, created_at) VALUES (?, ?, ?)
        ");
        $stmtP->execute([$goalId, $tugasId, $now]);
        $penilaianId = (int)$pdo->lastInsertId();

        $stmtDn = $pdo->prepare("
            INSERT INTO tdetail_nilai (penilaian_id, rubrik_goal_id, sumber, created_at)
            VALUES (?, ?, 'tugas', ?)
        ");
        $stmtDn->execute([$penilaianId, (int)$rubrikId, $now]);
    }

    $stmtInfo = $pdo->prepare("
        SELECT ug.id AS guru_user_id, ug.nama AS nama_guru,
               um.id AS murid_user_id, um.nama AS nama_murid
        FROM tjadwal_les j
        INNER JOIN tguru g  ON g.id  = j.guru_id
        INNER JOIN tuser ug ON ug.id = g.user_id
        INNER JOIN tmurid m ON m.id  = j.murid_id
        INNER JOIN tuser um ON um.id = m.user_id
        WHERE j.id = ?
    ");
    $stmtInfo->execute([$jadwalId]);
    $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

    if ($info) {
        createNotifikasi($pdo, [
            'jenis'            => 'tugas_baru',
            'reference_type'   => 'tugas',
            'reference_id'     => $tugasId,
            'role_pengirim'    => 'guru',
            'user_pengirim_id' => $info['guru_user_id'],
            'context' => [
                'nama_tugas'    => $judul,
                'nama_guru'     => $info['nama_guru'],
                'nama_murid'    => $info['nama_murid'],
                'deadline'      => date('d/m/Y H:i', strtotime($deadline)),
                'guru_user_id'  => $info['guru_user_id'],
                'murid_user_id' => $info['murid_user_id']
            ]
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Tugas berhasil dibuat',
        'data'    => [
            'tugas_id'     => $tugasId,
            'judul'        => $judul,
            'deadline'     => $deadline,
            'is_dinilai'   => (bool)$isDinilai,
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