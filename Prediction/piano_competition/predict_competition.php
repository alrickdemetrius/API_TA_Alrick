<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../Notifikasi/notifikasi_helper.php';

$FLASK_URL = 'https://web-production-fd291.up.railway.app/competition/predict';

function hitungLabelKumulatif(array $distribusi, array $opsiNilai): array
{
    if (empty($distribusi) || empty($opsiNilai)) {
        return ['label' => null, 'encode' => 0.0, 'rata_encode' => 0.0];
    }

    $encodeMap = [];
    foreach ($opsiNilai as $o) {
        $encodeMap[$o['label']] = (float) $o['encode_value'];
    }

    $totalSum = 0;
    $totalCount = 0;
    foreach ($distribusi as $d) {
        $enc = $encodeMap[$d['nilai_label']] ?? 0;
        $totalSum += $enc * (int) $d['jumlah'];
        $totalCount += (int) $d['jumlah'];
    }
    $rataEncode = $totalCount > 0 ? $totalSum / $totalCount : 0;

    $labelResult = null;
    $minJarak = PHP_FLOAT_MAX;
    $minUrutan = PHP_INT_MIN;
    foreach ($opsiNilai as $o) {
        $jarak = abs((float) $o['encode_value'] - $rataEncode);
        $urutan = (int) ($o['urutan_ke'] ?? 0);
        $epsilon = 1e-9;
        if (
            $jarak < $minJarak - $epsilon ||
            ($jarak < $minJarak + $epsilon && $urutan > $minUrutan)
        ) {
            $minJarak = $jarak;
            $minUrutan = $urutan;
            $labelResult = $o['label'];
        }
    }

    return [
        'label' => $labelResult,
        'encode' => $encodeMap[$labelResult] ?? 0.0,
        'rata_encode' => $rataEncode
    ];
}

$data = json_decode(file_get_contents("php://input"), true);
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

    if (!$goalInfo)
        throw new Exception('Goal tidak ditemukan');
    if ($goalInfo['tipe_penilaian'] !== 'kategorik') {
        throw new Exception('Prediksi kompetisi hanya untuk goal bertipe kategorik');
    }

    // 2. Mapping kategori rubrik
    $kategori_map = [
        'Tempo Control' => 'tempo_control',
        'Accuracy and Cleanliness' => 'accuracy_cleanliness',
        'Hand Coordination' => 'hand_coordination',
        'Dynamics and Articulation' => 'dynamics_articulation',
        'Expression and Emotion' => 'expression_emotion',
        'Phrasing' => 'phrasing',
        'Stage Presence' => 'stage_presence'
    ];

    // 3. Ambil label terbanyak per rubrik — SAMA dengan lingkaran
    $stmtRubriks = $pdo->prepare("
        SELECT rg.id AS rubrik_id, rg.kategori
        FROM trubrik_goal rg
        WHERE rg.jenis_goal_id = (SELECT jenis_goal_id FROM tgoal WHERE id = :goal_id)
        ORDER BY rg.urutan_ke
    ");
    $stmtRubriks->execute([':goal_id' => $goalId]);
    $rubriks = $stmtRubriks->fetchAll(PDO::FETCH_ASSOC);

    $stmtOpsi = $pdo->prepare("
    SELECT label, encode_value, urutan_ke FROM tgoal_opsi_nilai WHERE goal_id = ? ORDER BY urutan_ke
    ");
    $stmtOpsi->execute([$goalId]);
    $opsiRows = $stmtOpsi->fetchAll(PDO::FETCH_ASSOC);
    $encodeMap = [];
    foreach ($opsiRows as $o) {
        $encodeMap[$o['label']] = (float) $o['encode_value'];
    }

    $stmtDist = $pdo->prepare("
        SELECT dn.nilai_label, COUNT(*) AS jumlah
        FROM tdetail_nilai dn
        INNER JOIN tpenilaian p ON p.id = dn.penilaian_id
        WHERE dn.rubrik_goal_id = ? AND p.goal_id = ? AND dn.nilai_label IS NOT NULL
        GROUP BY dn.nilai_label
    ");

    $nilai = [];
    $nilai_label = [];
    $count_penilaian = [];

    foreach ($rubriks as $rubrik) {
        $key = $kategori_map[$rubrik['kategori']] ?? null;
        if (!$key)
            continue;

        $stmtDist->execute([$rubrik['rubrik_id'], $goalId]);
        $distribusi = $stmtDist->fetchAll(PDO::FETCH_ASSOC);

        $total = array_sum(array_column($distribusi, 'jumlah'));
        $count_penilaian[$key] = ['total' => (int) $total];

        if (empty($distribusi)) {
            $nilai_label[$key] = null;
            $nilai[$key] = 0;
            continue;
        }

        $hasil = hitungLabelKumulatif($distribusi, $opsiRows);
        $nilai_label[$key] = $hasil['label'];
        $nilai[$key] = (int) round($hasil['encode']);
    }

    $all_keys = array_values($kategori_map);
    $rubrikKosong = [];
    foreach ($all_keys as $key) {
        if (!isset($count_penilaian[$key]) || $count_penilaian[$key]['total'] === 0) {
            $rubrikKosong[] = $key;
        }
        if (!isset($nilai[$key])) {
            $nilai[$key] = 0;
            $nilai_label[$key] = 'Poor';
        }
        if (!isset($count_penilaian[$key])) {
            $count_penilaian[$key] = ['total' => 0];
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

    $nilaiFlask = $nilai;

    $flaskPayload = json_encode([
        'murid_id' => $goalInfo['murid_id'],
        'goal_id' => (int) $goalId,
        'nilai' => $nilaiFlask
    ]);

    $ch = curl_init($FLASK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $flaskPayload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    $flaskResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        throw new Exception(
            'Flask server tidak dapat dihubungi. ' .
            'Pastikan predict_competition_api.py sedang berjalan. ' .
            'Error: ' . ($curlError ?: "HTTP $httpCode")
        );
    }

    $flaskResult = json_decode($flaskResponse, true);
    if (!$flaskResult || !$flaskResult['success']) {
        throw new Exception('Prediksi gagal: ' . ($flaskResult['message'] ?? 'Unknown error'));
    }

    $modelResult = $flaskResult['prediction']['result'];
    $confidence = round($flaskResult['prediction']['confidence_percentage'], 2);

    $modelLabelToIndex = ['Not Ready' => 0, 'Developing' => 1, 'Ready' => 2, 'Competitive' => 3];
    $prediksiIndex = $modelLabelToIndex[$modelResult] ?? null;

    $stmtLabelKelas = $pdo->prepare("
        SELECT label_kelas_0, label_kelas_1, label_kelas_2, label_kelas_3 FROM tgoal WHERE id = ?
    ");
    $stmtLabelKelas->execute([$goalId]);
    $labelKelasRow = $stmtLabelKelas->fetch(PDO::FETCH_ASSOC);

    if (
        $labelKelasRow !== false && $prediksiIndex !== null
        && !empty($labelKelasRow["label_kelas_$prediksiIndex"])
    ) {
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
            :json_nilai, :json_nilai_encode, :json_count,
            :confidence, 0, NOW()
        )");
    $stmtLog->execute([
        ':murid_id' => $goalInfo['murid_id'],
        ':goal_id' => $goalId,
        ':jenis_goal' => $goalInfo['nama_goal'],
        ':tipe_penilaian' => 'kategorik',
        ':prediksi_kategori' => $prediksi_kategori,
        ':prediksi_index' => $prediksiIndex,
        ':grade_murid' => $goalInfo['grade_murid'],
        ':json_nilai' => json_encode($nilai_label),
        ':json_nilai_encode' => json_encode($nilai),
        ':json_count' => json_encode($count_penilaian),
        ':confidence' => $confidence
    ]);
    $logId = (int) $pdo->lastInsertId();

    // ── 6. Notifikasi ─────────────────────────────────────────────────────────
    $stmtGuru = $pdo->prepare("
        SELECT ug.id AS guru_user_id, ug.nama AS nama_guru
        FROM tjadwal_les jl
        INNER JOIN tguru g  ON g.id  = jl.guru_id
        INNER JOIN tuser ug ON ug.id = g.user_id
        WHERE jl.murid_id = :murid_id AND jl.status_aktif = 1
        LIMIT 1");
    $stmtGuru->execute([':murid_id' => $goalInfo['murid_id']]);
    $guruInfo = $stmtGuru->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    try {
        if ($guruInfo) {
            createNotifikasi($pdo, [
                'jenis' => 'goal_prediksi',
                'reference_type' => 'goal',
                'reference_id' => $goalId,
                'role_pengirim' => 'sistem',
                'user_pengirim_id' => null,
                'context' => [
                    'nama_murid' => $goalInfo['nama_murid'],
                    'nama_goal' => $goalInfo['nama_goal'],
                    'tanggal_target' => $goalInfo['tanggal_target']
                        ? date('d/m/Y', strtotime($goalInfo['tanggal_target'])) : '-',
                    'label_prediksi' => $prediksi_kategori,
                    'confidence' => $confidence,
                    'guru_user_id' => $guruInfo['guru_user_id'],
                    'murid_user_id' => $goalInfo['murid_user_id']
                ]
            ]);
        }
    } catch (Exception $e) {
        error_log('Notifikasi error: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Prediksi berhasil dilakukan',
        'data' => [
            'goal_id' => (int) $goalId,
            'log_id' => $logId,
            'prediksi_kategori' => $prediksi_kategori,
            'confidence' => $confidence,
            'probabilities' => $flaskResult['prediction']['probabilities'] ?? null,
            'nilai_encode' => $nilai,
            'nilai_label' => $nilai_label,
            'count_penilaian' => $count_penilaian
        ]
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}