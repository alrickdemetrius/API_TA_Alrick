<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/cors.php';

$data    = json_decode(file_get_contents("php://input"), true);
$goal_id = $data['goal_id'] ?? null;

if (!$goal_id) {
    echo json_encode(['success' => false, 'message' => 'goal_id is required']);
    exit;
}

try {
    // Info goal
    $stmtGoal = $pdo->prepare("
        SELECT g.id AS goal_id, g.murid_id, g.jenis_goal_id,
               jg.nama AS jenis_goal, jg.tipe_penilaian,
               m.grade
        FROM tgoal g
        INNER JOIN tjenis_goal jg ON jg.id = g.jenis_goal_id
        INNER JOIN tmurid m       ON m.id  = g.murid_id
        WHERE g.id = :goal_id");
    $stmtGoal->execute([':goal_id' => $goal_id]);
    $goal = $stmtGoal->fetch(PDO::FETCH_ASSOC);

    if (!$goal) {
        echo json_encode(['success' => false, 'message' => 'Goal tidak ditemukan']);
        exit;
    }

    // Hanya ABRSM yang didukung untuk prediksi ini
    if ($goal['tipe_penilaian'] !== 'numerikal') {
        echo json_encode([
            'success'         => false,
            'message'         => 'Prediksi ujian hanya untuk goal bertipe numerikal',
            'jenis_goal'      => $goal['jenis_goal'],
            'supported_types' => ['numerikal']
        ]);
        exit;
    }

    // Ambil rata-rata nilai_abrsm per sub-kategori
    // Lagu 1/2/3 diambil per sub, Scales/Sight/Aural diambil per rubrik
    $stmtScores = $pdo->prepare("
        SELECT rg.kategori AS rubrik_nama,
               AVG(dns.nilai / rs.nilai_max * rg.nilai_max) AS rata_nilai_abrsm,
               COUNT(DISTINCT dns.id) AS total_count,
               SUM(CASE WHEN dn.sumber = 'kelas' THEN 1 ELSE 0 END) AS count_kelas,
               SUM(CASE WHEN dn.sumber = 'tugas' THEN 1 ELSE 0 END) AS count_tugas,
               SUM(CASE WHEN dn.sumber = 'lain'  THEN 1 ELSE 0 END) AS count_lain
        FROM tpenilaian p
        INNER JOIN tdetail_nilai dn       ON dn.penilaian_id     = p.id
        INNER JOIN tdetail_nilai_sub dns  ON dns.detail_nilai_id  = dn.id
        INNER JOIN trubrik_subkategori rs ON rs.id               = dns.rubrik_subkategori_id
        INNER JOIN trubrik_goal rg        ON rg.id               = dn.rubrik_goal_id
        WHERE p.goal_id = :goal_id
          AND dns.nilai IS NOT NULL
          AND rs.nilai_max > 0
          AND rg.nilai_max > 0
        GROUP BY rg.id, rg.kategori
        ORDER BY rg.urutan_ke
    ");
    $stmtScores->execute([':goal_id' => $goal_id]);
    $results = $stmtScores->fetchAll(PDO::FETCH_ASSOC);

    // Rubrik non-Lagu → petakan via nama kategori; Lagu → rata-rata semua sub
    $kategori_map = [
        'Scales'        => 'scales',
        'Sight Reading' => 'sight',
        'Aural'         => 'aural'
    ];
    $allKeys = ['lagu', 'scales', 'sight', 'aural'];

    $nilai           = [];
    $count_penilaian = [];
    $score_total     = 0;

    foreach ($results as $row) {
        $rubrikNama = $row['rubrik_nama'];
        $rata       = round((float)$row['rata_nilai_abrsm'], 2);
        $counts     = [
            'total' => (int)$row['total_count'],
            'kelas' => (int)$row['count_kelas'],
            'tugas' => (int)$row['count_tugas'],
            'lain'  => (int)$row['count_lain']
        ];

        $key = $kategori_map[$rubrikNama] ?? 'lagu';

        if (!isset($nilai[$key])) {
            $nilai[$key]           = $rata;
            $score_total          += $rata;
            $count_penilaian[$key] = $counts;
        }
    }

    // Default 0 untuk rubrik yang belum dinilai
    foreach ($allKeys as $cat) {
        if (!isset($nilai[$cat])) {
            $nilai[$cat]           = 0;
            $count_penilaian[$cat] = ['total' => 0, 'kelas' => 0, 'tugas' => 0, 'lain' => 0];
        }
    }

    $total_assessments  = array_sum(array_column($count_penilaian, 'total'));
    $has_sufficient_data = $total_assessments >= 6; // minimal 1 per kategori

    echo json_encode([
        'success' => true,
        'data'    => [
            'murid_id'            => $goal['murid_id'],
            'goal_id'             => (int)$goal['goal_id'],
            'jenis_goal'          => $goal['jenis_goal'],
            'jenis_goal_id'       => (int)$goal['jenis_goal_id'],
            'grade'               => (int)$goal['grade'],
            'nilai'               => $nilai,           // skala ABRSM asli
            'count_penilaian'     => $count_penilaian,
            'score_total'         => round($score_total, 2),
            'score_total_max'     => 150,
            'total_assessments'   => $total_assessments,
            'has_sufficient_data' => $has_sufficient_data
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}