<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';


$data   = json_decode(file_get_contents("php://input"), true);
$goalId = $data['goal_id'] ?? null;

if (!$goalId) {
    echo json_encode(['success' => false, 'message' => 'goal_id wajib diisi']);
    exit;
}

try {
    $stmtGoal = $pdo->prepare("
        SELECT g.id, g.nama, g.jenis_goal_id, jg.tipe_penilaian
        FROM tgoal g
        INNER JOIN tjenis_goal jg ON jg.id = g.jenis_goal_id
        WHERE g.id = ?
    ");
    $stmtGoal->execute([$goalId]);
    $goal = $stmtGoal->fetch(PDO::FETCH_ASSOC);

    if (!$goal) {
        echo json_encode(['success' => false, 'message' => 'Goal tidak ditemukan']);
        exit;
    }

    $namaGoal     = $goal['nama'];
    $jenisGoalId   = (int)$goal['jenis_goal_id'];
    $tipePenilaian = $goal['tipe_penilaian'];

    $stmtOpsiGoal = $pdo->prepare("
        SELECT label, urutan_ke, encode_value
        FROM tgoal_opsi_nilai WHERE goal_id = ? ORDER BY urutan_ke
    ");
    $stmtOpsiGoal->execute([$goalId]);
    $opsiNilai = $stmtOpsiGoal->fetchAll(PDO::FETCH_ASSOC);

    if (empty($opsiNilai) && $tipePenilaian === 'kategorik') {
        $stmtOpsiDef = $pdo->prepare("
            SELECT label, urutan_ke
            FROM trubrik_opsi_nilai WHERE jenis_goal_id = ? ORDER BY urutan_ke
        ");
        $stmtOpsiDef->execute([$jenisGoalId]);
        $defRows   = $stmtOpsiDef->fetchAll(PDO::FETCH_ASSOC);
        $totalDef  = count($defRows);
        $opsiNilai = array_map(fn($o) => [
            'label'        => $o['label'],
            'urutan_ke'    => (int)$o['urutan_ke'],
            'encode_value' => $totalDef > 1
                ? round(($o['urutan_ke'] - 1) / ($totalDef - 1) * 3, 3)
                : 0.0
        ], $defRows);
    }

    $stmtRubrik = $pdo->prepare("
        SELECT id AS rubrik_id, kategori, deskripsi, urutan_ke, nilai_max
        FROM trubrik_goal
        WHERE jenis_goal_id = ?
        ORDER BY urutan_ke
    ");
    $stmtRubrik->execute([$jenisGoalId]);
    $rubrikRows = $stmtRubrik->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rubrikRows)) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada rubrik untuk goal ini']);
        exit;
    }

    $rubrikIds = array_column($rubrikRows, 'rubrik_id');

    $ph = implode(',', array_fill(0, count($rubrikIds), '?'));
    $stmtSub = $pdo->prepare("
        SELECT rs.id AS sub_id, rs.rubrik_goal_id, rs.nama, rs.nilai_max, rs.urutan_ke
        FROM trubrik_subkategori rs
        WHERE rs.goal_id = ?
          AND rs.rubrik_goal_id IN ($ph)
        ORDER BY rs.rubrik_goal_id, rs.urutan_ke
    ");
    $stmtSub->execute(array_merge([$goalId], $rubrikIds));
    $subRows = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

    $subByRubrik = [];
    foreach ($subRows as $s) {
        $rid = (int)$s['rubrik_goal_id'];
        $subByRubrik[$rid][] = [
            'sub_id'    => (int)$s['sub_id'],
            'nama'      => $s['nama'],
            'nilai_max' => (float)$s['nilai_max'],
            'urutan_ke' => (int)$s['urutan_ke']
        ];
    }

    $rubrik = array_map(fn($r) => [
        'rubrik_id'    => (int)$r['rubrik_id'],
        'kategori'     => $r['kategori'],
        'deskripsi'    => $r['deskripsi'],
        'urutan_ke'    => (int)$r['urutan_ke'],
        'nilai_max'    => (float)$r['nilai_max'],
        'sub_kategori' => $subByRubrik[(int)$r['rubrik_id']] ?? []
    ], $rubrikRows);

    $opsi = array_map(fn($o) => [
        'label'        => $o['label'],
        'encode_value' => round((float)($o['encode_value'] ?? 0), 3),
        'urutan_ke'    => (int)$o['urutan_ke']
    ], $opsiNilai);

    echo json_encode([
        'success'        => true,
        'tipe_penilaian' => $tipePenilaian,
        'opsi_nilai'     => $opsi,
        'nama_goal'     => $namaGoal,
        'data'           => $rubrik
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}