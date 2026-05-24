<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);
$jenisGoalId = $data['jenis_goal_id'] ?? null;

if (!$jenisGoalId) {
    echo json_encode(['success' => false, 'message' => 'jenis_goal_id wajib diisi']);
    exit;
}

try {
    $stmtJenis = $pdo->prepare("
        SELECT id AS jenis_goal_id, nama, deskripsi, tipe_penilaian
        FROM tjenis_goal WHERE id = ?
    ");
    $stmtJenis->execute([$jenisGoalId]);
    $jenis = $stmtJenis->fetch(PDO::FETCH_ASSOC);

    if (!$jenis) {
        echo json_encode(['success' => false, 'message' => 'Jenis target tidak ditemukan']);
        exit;
    }

    $stmtRubrik = $pdo->prepare("
        SELECT id AS rubrik_id, kategori, deskripsi, urutan_ke, nilai_max
        FROM trubrik_goal WHERE jenis_goal_id = ?
        ORDER BY urutan_ke
    ");
    $stmtRubrik->execute([$jenisGoalId]);
    $rubrikRows = $stmtRubrik->fetchAll(PDO::FETCH_ASSOC);

    $stmtSub = $pdo->prepare("
        SELECT s.id AS sub_id, s.rubrik_goal_id, s.nama, s.nilai_max, s.urutan_ke
        FROM trubrik_subkategori s
        INNER JOIN trubrik_goal rg ON rg.id = s.rubrik_goal_id
        WHERE rg.jenis_goal_id = ?
        ORDER BY s.rubrik_goal_id, s.urutan_ke
    ");
    $stmtSub->execute([$jenisGoalId]);
    $subRows = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

    $subByRubrik = [];
    foreach ($subRows as $s) {
        $subByRubrik[(int)$s['rubrik_goal_id']][] = [
            'sub_id'    => (int)$s['sub_id'],
            'nama'      => $s['nama'],
            'nilai_max' => (float)$s['nilai_max'],
            'urutan_ke' => (int)$s['urutan_ke']
        ];
    }

    $stmtOpsi = $pdo->prepare("
        SELECT label, urutan_ke
        FROM trubrik_opsi_nilai
        WHERE jenis_goal_id = ? ORDER BY urutan_ke
    ");
    $stmtOpsi->execute([$jenisGoalId]);
    $opsi = array_map(fn($o) => [
        'label'     => $o['label'],
        'urutan_ke' => (int)$o['urutan_ke']
    ], $stmtOpsi->fetchAll(PDO::FETCH_ASSOC));

    $kategoris = array_map(fn($r) => [
        'rubrik_id'    => (int)$r['rubrik_id'],
        'kategori'     => $r['kategori'],
        'deskripsi'    => $r['deskripsi'],
        'urutan_ke'    => (int)$r['urutan_ke'],
        'nilai_max'    => (float)$r['nilai_max'],
        'sub_kategori' => $subByRubrik[(int)$r['rubrik_id']] ?? []
    ], $rubrikRows);

    echo json_encode([
        'success' => true,
        'data'    => [
            'jenis_goal_id'  => (int)$jenis['jenis_goal_id'],
            'nama'           => $jenis['nama'],
            'deskripsi'      => $jenis['deskripsi'],
            'tipe_penilaian' => $jenis['tipe_penilaian'],
            'opsi_nilai'     => $opsi,
            'kategoris'      => $kategoris
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}