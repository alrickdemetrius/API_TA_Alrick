<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

try {
    $stmtJenis = $pdo->query("
        SELECT jg.id AS jenis_goal_id, jg.nama, jg.deskripsi, jg.tipe_penilaian
        FROM tjenis_goal jg
        ORDER BY jg.id
    ");
    $jenisList = $stmtJenis->fetchAll(PDO::FETCH_ASSOC);

    $stmtRubrik = $pdo->query("
        SELECT rg.id AS rubrik_id, rg.jenis_goal_id, rg.kategori,
               rg.deskripsi, rg.urutan_ke, rg.nilai_max
        FROM trubrik_goal rg
        ORDER BY rg.jenis_goal_id, rg.urutan_ke
    ");
    $rubrikRows = $stmtRubrik->fetchAll(PDO::FETCH_ASSOC);

    $stmtSub = $pdo->query("
        SELECT s.id AS sub_id, s.rubrik_goal_id, s.nama,
               s.nilai_max, s.urutan_ke
        FROM trubrik_subkategori s
        ORDER BY s.rubrik_goal_id, s.urutan_ke
    ");
    $subRows = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

    $stmtOpsi = $pdo->query("
        SELECT jenis_goal_id, label, urutan_ke
        FROM trubrik_opsi_nilai
        ORDER BY jenis_goal_id, urutan_ke
    ");
    $opsiRows = $stmtOpsi->fetchAll(PDO::FETCH_ASSOC);

    $subByRubrik = [];
    foreach ($subRows as $s) {
        $subByRubrik[(int)$s['rubrik_goal_id']][] = [
            'sub_id'    => (int)$s['sub_id'],
            'nama'      => $s['nama'],
            'nilai_max' => (float)$s['nilai_max'],
            'urutan_ke' => (int)$s['urutan_ke']
        ];
    }

    $rubrikByJenis = [];
    foreach ($rubrikRows as $r) {
        $jid = (int)$r['jenis_goal_id'];
        $rid = (int)$r['rubrik_id'];
        $rubrikByJenis[$jid][] = [
            'rubrik_id'      => $rid,
            'kategori'       => $r['kategori'],
            'deskripsi'      => $r['deskripsi'],
            'urutan_ke'      => (int)$r['urutan_ke'],
            'nilai_max'      => (float)$r['nilai_max'],
            'sub_kategori'   => $subByRubrik[$rid] ?? []
        ];
    }

    $opsiByJenis = [];
    foreach ($opsiRows as $o) {
        $opsiByJenis[(int)$o['jenis_goal_id']][] = [
            'label'     => $o['label'],
            'urutan_ke' => (int)$o['urutan_ke']
        ];
    }

    $result = [];
    foreach ($jenisList as $j) {
        $jid = (int)$j['jenis_goal_id'];
        $result[] = [
            'jenis_goal_id'  => $jid,
            'nama'           => $j['nama'],
            'deskripsi'      => $j['deskripsi'],
            'tipe_penilaian' => $j['tipe_penilaian'],
            'opsi_nilai'     => $opsiByJenis[$jid] ?? [],
            'kategoris'      => $rubrikByJenis[$jid] ?? []
        ];
    }

    echo json_encode(['success' => true, 'data' => $result, 'total' => count($result)]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}