<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../Notifikasi/notifikasi_helper.php';


$FLASK_URL = 'https://web-production-fd291.up.railway.app/abrsm/predict';

$data   = json_decode(file_get_contents("php://input"), true);
$goalId = $data['goal_id'] ?? null;

if (!$goalId) {
    echo json_encode(['success' => false, 'message' => 'goal_id wajib diisi']);
    exit;
}

try {
    $pdo->beginTransaction();

    // ── 1. Info goal ──────────────────────────────────────────────────────────
    $stmtGoal = $pdo->prepare("
        SELECT g.id, g.murid_id, g.tanggal_target, g.jenis_goal_id,
               jg.nama AS nama_goal, jg.tipe_penilaian,
               m.grade AS grade_murid,
               um.id AS murid_user_id, um.nama AS nama_murid
        FROM tgoal g
        INNER JOIN tjenis_goal jg ON jg.id = g.jenis_goal_id
        INNER JOIN tmurid m       ON m.id  = g.murid_id
        INNER JOIN tuser um       ON um.id = m.user_id
        WHERE g.id = :goal_id");
    $stmtGoal->execute([':goal_id' => $goalId]);
    $goalInfo = $stmtGoal->fetch(PDO::FETCH_ASSOC);

    if (!$goalInfo) throw new Exception('Goal tidak ditemukan');
    if ($goalInfo['tipe_penilaian'] !== 'numerikal') {
        throw new Exception('Prediksi ujian hanya untuk goal bertipe numerikal');
    }

    // ── 2. Rata-rata nilai per rubrik (Lagu = rata-rata semua sub, lainnya per rubrik) ──
    // Fitur model: ['grade', 'lagu', 'scales', 'sight', 'aural']
    // Lagu dihitung sebagai rata-rata nilai semua sub-kategori lagu (berapapun jumlahnya)
    $kategori_map = [
        'Scales'        => 'scales',
        'Sight Reading' => 'sight',
        'Aural'         => 'aural'
    ];

    $stmtScores = $pdo->prepare("
        SELECT rg.id AS rubrik_id,
               rg.kategori AS rubrik_nama,
               rg.nilai_max,
               AVG(dn.nilai)                                               AS rata_nilai_abrsm,
               COUNT(dn.id)                                                AS total_count,
               SUM(CASE WHEN dn.sumber='kelas' THEN 1 ELSE 0 END)         AS count_kelas,
               SUM(CASE WHEN dn.sumber='tugas' THEN 1 ELSE 0 END)         AS count_tugas,
               SUM(CASE WHEN dn.sumber='lain'  THEN 1 ELSE 0 END)         AS count_lain
        FROM trubrik_goal rg
        INNER JOIN tdetail_nilai dn ON dn.rubrik_goal_id = rg.id
        INNER JOIN tpenilaian p     ON p.id = dn.penilaian_id AND p.goal_id = :goal_id
        WHERE dn.nilai IS NOT NULL
        GROUP BY rg.id, rg.kategori
        ORDER BY rg.urutan_ke
    ");
    $stmtScores->execute([':goal_id' => $goalId]);
    $results = $stmtScores->fetchAll(PDO::FETCH_ASSOC);

    $nilai           = [];
    $count_penilaian = [];

    foreach ($results as $row) {
        $rubrikNama = $row['rubrik_nama'];
        $rata       = round((float)$row['rata_nilai_abrsm'], 2);
        $counts     = [
            'total' => (int)$row['total_count'],
            'kelas' => (int)$row['count_kelas'],
            'tugas' => (int)$row['count_tugas'],
            'lain'  => (int)$row['count_lain']
        ];

        if (isset($kategori_map[$rubrikNama])) {
            $key = $kategori_map[$rubrikNama];
        } else {
            // Semua rubrik selain Scales/Sight/Aural → dianggap Lagu
            $key = 'lagu';
        }

        if (!isset($nilai[$key])) {
            $nilai[$key]           = $rata;
            $count_penilaian[$key] = $counts;
        }
    }

    // Validasi: semua rubrik harus punya minimal 1 penilaian
    $rubrikKosong = [];
    foreach ($kategori_map as $cat) {
        if (!isset($count_penilaian[$cat]) || $count_penilaian[$cat]['total'] === 0) {
            $rubrikKosong[] = $cat;
        }
        if (!isset($nilai[$cat])) {
            $nilai[$cat]           = 0;
            $count_penilaian[$cat] = ['total' => 0, 'kelas' => 0, 'tugas' => 0, 'lain' => 0];
        }
    }

    if (!empty($rubrikKosong)) {
        echo json_encode([
            'success' => false,
            'message' => 'Semua rubrik harus memiliki minimal 1 penilaian sebelum dapat diprediksi.',
            'rubrik_kosong' => $rubrikKosong
        ]);
        exit;
    }

    $score_total = round(array_sum($nilai), 2);

    $nilaiFlask = $nilai;
    $nilaiFlask['lagu'] = round($nilai['lagu'] * 3, 2);

    $score_total = round($nilaiFlask['lagu'] + ($nilai['scales'] ?? 0) + ($nilai['sight'] ?? 0) + ($nilai['aural'] ?? 0), 2);

    // ── 3. Kirim ke Flask ─────────────────────────────────────────────────────
    $flaskPayload = json_encode([
        'murid_id' => $goalInfo['murid_id'],
        'goal_id'  => (int)$goalId,
        'grade'    => (int)$goalInfo['grade_murid'],
        'nilai'    => $nilaiFlask
    ]);

    $ch = curl_init($FLASK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $flaskPayload,
        CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_CONNECTTIMEOUT  => 10,
    ]);
    $flaskResponse = curl_exec($ch);
    $curlError     = curl_error($ch);
    $curlErrno     = curl_errno($ch);
    $httpCode      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($flaskResponse === false || $curlErrno !== 0) {
        throw new Exception(
            'Flask server tidak dapat dihubungi (errno: ' . $curlErrno . '). ' .
            'Pastikan predict_abrsm_api.py berjalan di port 5000. Error: ' . $curlError
        );
    }

    if ($httpCode !== 200) {
        throw new Exception(
            'Flask server merespons dengan HTTP ' . $httpCode .
            '. Response: ' . substr($flaskResponse, 0, 200)
        );
    }

    $flaskResult = json_decode($flaskResponse, true);
    if (!$flaskResult || !$flaskResult['success']) {
        throw new Exception('Prediksi gagal: ' . ($flaskResult['message'] ?? 'Unknown error'));
    }

    $modelResult = $flaskResult['prediction']['result'];
    $confidence  = round($flaskResult['prediction']['confidence_percentage'], 2);

    $modelLabelToIndex = ['Fail' => 0, 'Pass' => 1, 'Merit' => 2, 'Distinction' => 3];
    $prediksiIndex     = $modelLabelToIndex[$modelResult] ?? null;

    $stmtLabelKelas = $pdo->prepare("
        SELECT label_kelas_0, label_kelas_1, label_kelas_2, label_kelas_3 FROM tgoal WHERE id = ?
    ");
    $stmtLabelKelas->execute([$goalId]);
    $labelKelasRow = $stmtLabelKelas->fetch(PDO::FETCH_ASSOC);

    if ($labelKelasRow !== false && $prediksiIndex !== null
        && !empty($labelKelasRow["label_kelas_$prediksiIndex"])) {
        $prediksi_kategori = $labelKelasRow["label_kelas_$prediksiIndex"];
    } else {
        $prediksi_kategori = $modelResult;
    }

    $stmtLog = $pdo->prepare("
        INSERT INTO tlog_prediksi (
            murid_id, goal_id, jenis_goal, tipe_penilaian,
            prediksi_kategori, prediksi_index, grade_murid,
            json_nilai, json_nilai_encode, json_count_penilaian,
            confidence_score, hasil_akhir_diset, created_at
        ) VALUES (
            :murid_id, :goal_id, :jenis_goal, :tipe_penilaian,
            :prediksi_kategori, :prediksi_index, :grade_murid,
            :json_nilai, NULL, :json_count,
            :confidence, 0, NOW()
        )");
    $stmtLog->execute([
        ':murid_id'          => $goalInfo['murid_id'],
        ':goal_id'           => $goalId,
        ':jenis_goal'        => $goalInfo['nama_goal'],
        ':tipe_penilaian'    => 'numerikal',
        ':prediksi_kategori' => $prediksi_kategori,
        ':prediksi_index'    => $prediksiIndex,
        ':grade_murid'       => $goalInfo['grade_murid'],
        ':json_nilai'        => json_encode($nilai),
        ':json_count'        => json_encode($count_penilaian),
        ':confidence'        => $confidence
    ]);
    $logId = (int)$pdo->lastInsertId();

    // ── 5. Notifikasi ─────────────────────────────────────────────────────────
    $stmtGuru = $pdo->prepare("
        SELECT ug.id AS guru_user_id, ug.nama AS nama_guru
        FROM tjadwal_les jl
        INNER JOIN tguru g  ON g.id  = jl.guru_id
        INNER JOIN tuser ug ON ug.id = g.user_id
        WHERE jl.murid_id = :murid_id AND jl.status_aktif = 1
        LIMIT 1");
    $stmtGuru->execute([':murid_id' => $goalInfo['murid_id']]);
    $guruInfo = $stmtGuru->fetch(PDO::FETCH_ASSOC);

    if ($guruInfo) {
        createNotifikasi($pdo, [
            'jenis'            => 'goal_prediksi',
            'reference_type'   => 'goal',
            'reference_id'     => $goalId,
            'role_pengirim'    => 'sistem',
            'user_pengirim_id' => null,
            'context' => [
                'nama_murid'      => $goalInfo['nama_murid'],
                'nama_goal'       => $goalInfo['nama_goal'],
                'tanggal_target'  => $goalInfo['tanggal_target']
                                     ? date('d/m/Y', strtotime($goalInfo['tanggal_target'])) : '-',
                'label_prediksi'  => $prediksi_kategori,
                'confidence'      => $confidence,
                'guru_user_id'    => $guruInfo['guru_user_id'],
                'murid_user_id'   => $goalInfo['murid_user_id']
            ]
        ]);
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Prediksi berhasil dilakukan',
        'data'    => [
            'goal_id'          => (int)$goalId,
            'log_id'           => $logId,
            'prediksi_kategori'=> $prediksi_kategori,
            'confidence'       => $confidence,
            'score_total'      => $score_total,
            'score_total_max'  => 150,
            'probabilities'    => $flaskResult['prediction']['probabilities'] ?? null,
            'nilai'            => $nilai,
            'count_penilaian'  => $count_penilaian
        ]
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}