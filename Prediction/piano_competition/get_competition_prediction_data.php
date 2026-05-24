<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/cors.php';

/**
 * get_competition_prediction_data.php
 *
 * Mengambil data nilai terkini per rubrik kompetisi untuk ditampilkan
 * di halaman detail target sebelum tombol prediksi ditekan.
 *
 * Response berisi:
 *  - label terbanyak per rubrik (mode)
 *  - encode per rubrik
 *  - jumlah penilaian per rubrik
 *  - total penilaian
 */

$data   = json_decode(file_get_contents("php://input"), true);
$goalId = $data['goal_id'] ?? null;

if (!$goalId) {
    echo json_encode(['success' => false, 'message' => 'goal_id wajib diisi']);
    exit;
}

try {
    // Validasi goal bertipe kategorik
    $stmtGoal = $pdo->prepare("
        SELECT g.id, g.murid_id, jg.nama AS nama_goal, jg.tipe_penilaian
        FROM tgoal g
        INNER JOIN tjenis_goal jg ON jg.id = g.jenis_goal_id
        WHERE g.id = :goal_id");
    $stmtGoal->execute([':goal_id' => $goalId]);
    $goalInfo = $stmtGoal->fetch(PDO::FETCH_ASSOC);

    if (!$goalInfo) {
        echo json_encode(['success' => false, 'message' => 'Goal tidak ditemukan']);
        exit;
    }

    if ($goalInfo['tipe_penilaian'] !== 'kategorik') {
        echo json_encode(['success' => false, 'message' => 'Prediksi kompetisi hanya untuk goal bertipe kategorik']);
        exit;
    }

    // Mapping kategori rubrik → key Flask
    $kategori_map = [
        'Tempo Control'             => 'tempo_control',
        'Accuracy and Cleanliness'  => 'accuracy_cleanliness',
        'Hand Coordination'         => 'hand_coordination',
        'Dynamics and Articulation' => 'dynamics_articulation',
        'Expression and Emotion'    => 'expression_emotion',
        'Phrasing'                  => 'phrasing',
        'Stage Presence'            => 'stage_presence'
    ];

    // Ambil distribusi label per rubrik + encode_value dari tgoal_opsi_nilai
    // Fallback ke interpolasi dari trubrik_opsi_nilai jika label custom tidak ada
    $stmtScores = $pdo->prepare("
        SELECT rg.kategori,
               dn.nilai_label,
               COALESCE(gov.encode_value, rov.encode_value_calc) AS encode_val,
               COUNT(*) AS jumlah
        FROM tpenilaian p
        INNER JOIN tdetail_nilai dn ON dn.penilaian_id = p.id
        INNER JOIN trubrik_goal rg  ON rg.id = dn.rubrik_goal_id
        LEFT JOIN tgoal_opsi_nilai gov
               ON gov.goal_id = p.goal_id AND gov.label = dn.nilai_label
        LEFT JOIN (
            SELECT ov.label, ov.jenis_goal_id,
                   ROUND((ov.urutan_ke - 1) / (cnt.total - 1) * 3, 3) AS encode_value_calc
            FROM trubrik_opsi_nilai ov
            INNER JOIN (
                SELECT jenis_goal_id, COUNT(*) AS total
                FROM trubrik_opsi_nilai GROUP BY jenis_goal_id
            ) cnt ON cnt.jenis_goal_id = ov.jenis_goal_id
        ) rov ON rov.jenis_goal_id = rg.jenis_goal_id AND rov.label = dn.nilai_label
        WHERE p.goal_id = :goal_id AND dn.nilai_label IS NOT NULL
        GROUP BY rg.id, rg.kategori, dn.nilai_label, encode_val
        ORDER BY rg.urutan_ke, jumlah DESC
    ");
    $stmtScores->execute([':goal_id' => $goalId]);
    $scoreRows = $stmtScores->fetchAll(PDO::FETCH_ASSOC);

    // Ambil label terbanyak (mode) dan distribusi per rubrik
    $nilai_label    = [];
    $nilai_encode   = [];
    $distribusi     = [];
    $seen           = [];

    foreach ($scoreRows as $row) {
        $key = $kategori_map[$row['kategori']] ?? null;
        if (!$key) continue;

        // Distribusi semua label per rubrik
        if (!isset($distribusi[$key])) $distribusi[$key] = [];
        $distribusi[$key][] = [
            'label'        => $row['nilai_label'],
            'encode_value' => round((float)$row['encode_val'], 3),
            'jumlah'       => (int)$row['jumlah']
        ];

        // Label + encode terbanyak (sudah urut DESC, ambil pertama)
        if (!isset($seen[$key])) {
            $seen[$key]         = true;
            $nilai_label[$key]  = $row['nilai_label'];
            $nilai_encode[$key] = round((float)$row['encode_val'], 3);
        }
    }

    // Hitung total penilaian per rubrik
    $stmtCount = $pdo->prepare("
        SELECT rg.kategori, COUNT(DISTINCT dn.id) AS total
        FROM tpenilaian p
        INNER JOIN tdetail_nilai dn ON dn.penilaian_id = p.id
        INNER JOIN trubrik_goal rg  ON rg.id = dn.rubrik_goal_id
        WHERE p.goal_id = :goal_id AND dn.nilai_label IS NOT NULL
        GROUP BY rg.id, rg.kategori");
    $stmtCount->execute([':goal_id' => $goalId]);
    $count_penilaian = [];
    foreach ($stmtCount->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $kategori_map[$row['kategori']] ?? null;
        if ($key) $count_penilaian[$key] = (int)$row['total'];
    }

    // Total keseluruhan
    $total_penilaian = array_sum($count_penilaian);

    // Default untuk rubrik yang belum ada penilaian
    foreach (array_values($kategori_map) as $key) {
        if (!isset($nilai_label[$key]))    $nilai_label[$key]    = null;
        if (!isset($nilai_encode[$key]))   $nilai_encode[$key]   = null;
        if (!isset($count_penilaian[$key])) $count_penilaian[$key] = 0;
        if (!isset($distribusi[$key]))     $distribusi[$key]     = [];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'goal_id'         => (int)$goalId,
            'nilai_label'     => $nilai_label,
            'encode_value'    => $nilai_encode,
            'distribusi'      => $distribusi,
            'count_penilaian' => $count_penilaian,
            'total_penilaian' => $total_penilaian,
            'can_predict'     => $total_penilaian > 0
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}