<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);
$jenisGoalId = $data["jenis_goal_id"] ?? null;

if (!$jenisGoalId) {
    echo json_encode(['success' => false, 'message' => 'Parameter jenis_goal_id tidak lengkap']);
    exit;
}

try {
    $stmtJenis = $pdo->prepare("SELECT tipe_penilaian FROM tjenis_goal WHERE id = :id");
    $stmtJenis->execute([':id' => $jenisGoalId]);
    $jenis = $stmtJenis->fetch(PDO::FETCH_ASSOC);

    if (!$jenis) {
        echo json_encode(['success' => false, 'message' => 'Jenis target tidak ditemukan']);
        exit;
    }

    $tipePenilaian = $jenis['tipe_penilaian'];

    $stmtRubrik = $pdo->prepare("
        SELECT id AS rubrik_id, kategori, deskripsi, urutan_ke, nilai_max
        FROM trubrik_goal
        WHERE jenis_goal_id = :id
        ORDER BY urutan_ke
    ");
    $stmtRubrik->execute([':id' => $jenisGoalId]);
    $rubrikList = $stmtRubrik->fetchAll(PDO::FETCH_ASSOC);

    $stmtOpsi = $pdo->prepare("
        SELECT label, urutan_ke
        FROM trubrik_opsi_nilai
        WHERE jenis_goal_id = :id
        ORDER BY urutan_ke
    ");
    $stmtOpsi->execute([':id' => $jenisGoalId]);
    $opsiList = $stmtOpsi->fetchAll(PDO::FETCH_ASSOC);

    $rubrik = array_map(fn($r) => [
        'rubrik_id'  => (int)$r['rubrik_id'],
        'kategori'   => $r['kategori'],
        'deskripsi'  => $r['deskripsi'],
        'urutan_ke'  => (int)$r['urutan_ke'],
        'nilai_max'  => (float)$r['nilai_max']
    ], $rubrikList);

    $opsi = array_map(fn($o) => [
        'label'        => $o['label'],
        'urutan_ke' => (int)$o['urutan_ke']
    ], $opsiList);

    echo json_encode([
        'success'        => true,
        'tipe_penilaian' => $tipePenilaian,
        'opsi_nilai'     => $opsi,
        'data'           => $rubrik
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}