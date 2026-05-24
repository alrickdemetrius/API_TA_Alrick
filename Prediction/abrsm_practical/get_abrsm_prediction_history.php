<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/cors.php';

/**
 * get_abrsm_prediction_history.php
 *
 * Mengambil riwayat prediksi untuk suatu goal ABRSM.
 * Kolom score_total dihapus dari schema v12 — tidak di-SELECT.
 */

$data    = json_decode(file_get_contents("php://input"), true);
$goal_id = $data['goal_id'] ?? null;
$limit   = isset($data['limit']) ? (int)$data['limit'] : 10;

if (!$goal_id) {
    echo json_encode(['success' => false, 'message' => 'goal_id is required']);
    exit;
}

try {
    $stmtHist = $pdo->prepare("
        SELECT
            lp.id,
            lp.murid_id,
            lp.goal_id,
            lp.jenis_goal,
            lp.tipe_penilaian,
            lp.prediksi_kategori,
            lp.prediksi_index,
            lp.grade_murid,
            lp.json_nilai,
            lp.json_nilai_encode,
            lp.json_count_penilaian,
            lp.confidence_score,
            lp.hasil_akhir_diset,
            lp.created_at
        FROM tlog_prediksi lp
        WHERE lp.goal_id = :goal_id
        ORDER BY lp.created_at ASC
        LIMIT :lim");
    $stmtHist->bindValue(':goal_id', $goal_id, PDO::PARAM_INT);
    $stmtHist->bindValue(':lim',     $limit,   PDO::PARAM_INT);
    $stmtHist->execute();
    $rows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

    $predictions = array_map(function($r) {
        return [
            'id'                  => (int)$r['id'],
            'goal_id'             => (int)$r['goal_id'],
            'jenis_goal'          => $r['jenis_goal'],
            'tipe_penilaian'      => $r['tipe_penilaian'],
            'prediksi_kategori'   => $r['prediksi_kategori'],
            'prediksi_index'      => $r['prediksi_index'] !== null ? (int)$r['prediksi_index'] : null,
            'grade_murid'         => (int)$r['grade_murid'],
            'confidence_score'    => $r['confidence_score'] !== null ? (float)$r['confidence_score'] : null,
            'hasil_akhir_diset'   => (bool)$r['hasil_akhir_diset'],
            'json_nilai'          => json_decode($r['json_nilai'], true),
            'json_nilai_encode'   => $r['json_nilai_encode'] ? json_decode($r['json_nilai_encode'], true) : null,
            'json_count_penilaian'=> $r['json_count_penilaian'] ? json_decode($r['json_count_penilaian'], true) : null,
            'created_at'          => $r['created_at']
        ];
    }, $rows);

    // Total semua prediksi untuk goal ini
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM tlog_prediksi WHERE goal_id = :goal_id");
    $stmtCount->execute([':goal_id' => $goal_id]);
    $total = (int)$stmtCount->fetchColumn();

    echo json_encode([
        'success' => true,
        'data'    => $predictions,
        'count'   => $total,
        'showing' => count($predictions)
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}