<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/cors.php';

/**
 * get_competition_prediction_history.php
 *
 * Mengambil riwayat prediksi kompetisi piano dari tlog_prediksi.
 * json_nilai berisi label per rubrik (Poor/Fair/Good/Excellent).
 * json_nilai_encode berisi encode integer per rubrik (0-3).
 */

$data   = json_decode(file_get_contents("php://input"), true);
$goalId = $data['goal_id'] ?? null;
$limit  = isset($data['limit']) ? min((int)$data['limit'], 50) : 10;

if (!$goalId) {
    echo json_encode(['success' => false, 'message' => 'goal_id wajib diisi']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            lp.id,
            lp.goal_id,
            lp.jenis_goal,
            lp.tipe_penilaian,
            lp.prediksi_kategori,
            lp.prediksi_index,
            lp.confidence_score,
            lp.hasil_akhir_diset,
            lp.json_nilai,
            lp.json_nilai_encode,
            lp.json_count_penilaian,
            lp.created_at
        FROM tlog_prediksi lp
        WHERE lp.goal_id        = :goal_id
          AND lp.tipe_penilaian = 'kategorik'
        ORDER BY lp.created_at ASC
        LIMIT :lim");
    $stmt->bindValue(':goal_id', $goalId, PDO::PARAM_INT);
    $stmt->bindValue(':lim',     $limit,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $logs = [];
    foreach ($rows as $row) {
        $logs[] = [
            'id'                   => (int)$row['id'],
            'goal_id'              => (int)$row['goal_id'],
            'jenis_goal'           => $row['jenis_goal'],
            'tipe_penilaian'       => $row['tipe_penilaian'],
            'prediksi_kategori'    => $row['prediksi_kategori'],
            'prediksi_index'       => $row['prediksi_index'] !== null ? (int)$row['prediksi_index'] : null,
            'confidence_score'     => $row['confidence_score'] !== null
                                      ? (float)$row['confidence_score'] : null,
            'hasil_akhir_diset'    => (bool)$row['hasil_akhir_diset'],
            // json_nilai: label per rubrik {'tempo_control':'Good', ...}
            'json_nilai'           => $row['json_nilai']
                                      ? json_decode($row['json_nilai'], true) : [],
            // json_nilai_encode: encode per rubrik {'tempo_control':2, ...}
            'json_nilai_encode'    => $row['json_nilai_encode']
                                      ? json_decode($row['json_nilai_encode'], true) : null,
            // json_count_penilaian: {'tempo_control': {'total':3}, ...}
            'json_count_penilaian' => (function($raw) {
                if (!$raw) return null;
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) return null;
                // Normalisasi: jika nilai integer (format lama), wrap ke ['total' => x]
                foreach ($decoded as $k => $v) {
                    if (is_int($v) || is_numeric($v)) {
                        $decoded[$k] = ['total' => (int)$v];
                    }
                }
                return $decoded;
            })($row['json_count_penilaian']),
            'created_at'           => $row['created_at']
        ];
    }

    echo json_encode([
        'success' => true,
        'data'    => $logs,
        'count'   => count($logs),
        'showing' => $limit
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}