<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['goal_id']) || !isset($data['detail_nilai'])) {
    echo json_encode(['success' => false, 'message' => 'goal_id dan detail_nilai wajib diisi']);
    exit;
}
if (!is_array($data['detail_nilai']) || empty($data['detail_nilai'])) {
    echo json_encode(['success' => false, 'message' => 'detail_nilai harus berupa array dan tidak kosong']);
    exit;
}

$goalId = (int) $data['goal_id'];
$presensiId = isset($data['presensi_id']) && $data['presensi_id'] > 0 ? (int) $data['presensi_id'] : null;
$tugasId = isset($data['tugas_id']) && $data['tugas_id'] > 0 ? (int) $data['tugas_id'] : null;
$catatanPenilaian = $data['catatan'] ?? null;
$guruUserId = $data['guru_user_id'] ?? null;
$detailNilai = $data['detail_nilai'];

if ($presensiId)
    $sumberDefault = 'kelas';
elseif ($tugasId)
    $sumberDefault = 'tugas';
else
    $sumberDefault = 'lain';

try {
    $pdo->beginTransaction();
    $createdAt = date('Y-m-d H:i:s');

    $stmtGoalInfo = $pdo->prepare("
        SELECT jg.tipe_penilaian, jg.id AS jenis_goal_id, jg.nama AS nama_goal,
               um.id AS murid_user_id, um.nama AS nama_murid,
               COALESCE(:guru_uid, ug.id) AS guru_user_id, ug.nama AS nama_guru
        FROM tgoal g
        INNER JOIN tjenis_goal jg ON jg.id = g.jenis_goal_id
        INNER JOIN tmurid m       ON m.id  = g.murid_id
        INNER JOIN tuser um       ON um.id = m.user_id
        LEFT  JOIN tjadwal_les jl ON jl.murid_id = m.id AND jl.status_aktif = 1
        LEFT  JOIN tguru gu       ON gu.id = jl.guru_id
        LEFT  JOIN tuser ug       ON ug.id = gu.user_id
        WHERE g.id = :goal_id LIMIT 1
    ");
    $stmtGoalInfo->execute([':goal_id' => $goalId, ':guru_uid' => $guruUserId]);
    $goalInfo = $stmtGoalInfo->fetch(PDO::FETCH_ASSOC);
    if (!$goalInfo)
        throw new Exception('Goal tidak ditemukan');

    $tipePenilaian = $goalInfo['tipe_penilaian'];
    $jenisGoalId = (int) $goalInfo['jenis_goal_id'];

    $opsiValid = [];
    if ($tipePenilaian === 'kategorik') {
        $stmtOpsiGoal = $pdo->prepare("SELECT label FROM tgoal_opsi_nilai WHERE goal_id = ?");
        $stmtOpsiGoal->execute([$goalId]);
        $opsiGoal = $stmtOpsiGoal->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($opsiGoal)) {
            foreach ($opsiGoal as $lbl)
                $opsiValid[$lbl] = true;
        } else {
            $stmtOpsiDef = $pdo->prepare("SELECT label FROM trubrik_opsi_nilai WHERE jenis_goal_id = ?");
            $stmtOpsiDef->execute([$jenisGoalId]);
            foreach ($stmtOpsiDef->fetchAll(PDO::FETCH_COLUMN) as $lbl)
                $opsiValid[$lbl] = true;
        }
        if (empty($opsiValid))
            throw new Exception('Opsi nilai kategorik tidak ditemukan');
    }

    $nilaiMaxByRubrik = [];
    if ($tipePenilaian === 'numerikal') {
        $stmtMax = $pdo->prepare("SELECT id, nilai_max FROM trubrik_goal WHERE jenis_goal_id = ?");
        $stmtMax->execute([$jenisGoalId]);
        foreach ($stmtMax->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nilaiMaxByRubrik[(int) $r['id']] = (float) $r['nilai_max'];
        }
    }

    $stmtP = $pdo->prepare("
        INSERT INTO tpenilaian (goal_id, presensi_id, tugas_id, catatan, created_at)
        VALUES (:goal_id, :presensi_id, :tugas_id, :catatan, :created_at)
    ");
    $stmtP->execute([
        ':goal_id' => $goalId,
        ':presensi_id' => $presensiId,
        ':tugas_id' => $tugasId,
        ':catatan' => $catatanPenilaian,
        ':created_at' => $createdAt
    ]);
    $penilaianId = (int) $pdo->lastInsertId();

    $stmtDN = $pdo->prepare("
        INSERT INTO tdetail_nilai (penilaian_id, rubrik_goal_id, nilai, nilai_label, sumber, created_at)
        VALUES (:penilaian_id, :rubrik_goal_id, :nilai, :nilai_label, :sumber, :created_at)
    ");

    $stmtSub = $pdo->prepare("
        INSERT INTO tdetail_nilai_sub (detail_nilai_id, rubrik_subkategori_id, nilai, nilai_label, created_at)
        VALUES (:detail_nilai_id, :rubrik_subkategori_id, :nilai, :nilai_label, :created_at)
    ");

    $insertedCount = 0;

    foreach ($detailNilai as $detail) {
        $rubrikId = (int) ($detail['rubrik_goal_id'] ?? $detail['rubrik_id'] ?? 0);
        if (!$rubrikId)
            throw new Exception('rubrik_goal_id wajib diisi');

        $sumber = $detail['sumber'] ?? $sumberDefault;
        if (!in_array($sumber, ['kelas', 'tugas', 'lain']))
            $sumber = $sumberDefault;

        if ($tipePenilaian === 'numerikal') {
            $subNilai = $detail['sub_nilai'] ?? [];
            $subNilaiFiltered = array_filter(
                $subNilai,
                fn($s) =>
                isset($s['nilai']) && $s['nilai'] !== '' && $s['nilai'] !== null
            );

            if (empty($subNilaiFiltered))
                continue;

            $nilaiValues = array_map(fn($s) => (float) $s['nilai'], $subNilaiFiltered);
            $nilaiAgregat = array_sum($nilaiValues) / count($nilaiValues);

            $stmtDN->execute([
                ':penilaian_id' => $penilaianId,
                ':rubrik_goal_id' => $rubrikId,
                ':nilai' => round($nilaiAgregat, 2),
                ':nilai_label' => null,
                ':sumber' => $sumber,
                ':created_at' => $createdAt
            ]);
            $detailNilaiId = (int) $pdo->lastInsertId();

            foreach ($subNilaiFiltered as $sub) {
                $subId = (int) $sub['sub_id'];
                $nilaiSub = (float) $sub['nilai'];
                $stmtSub->execute([
                    ':detail_nilai_id' => $detailNilaiId,
                    ':rubrik_subkategori_id' => $subId,
                    ':nilai' => $nilaiSub,
                    ':nilai_label' => null,
                    ':created_at' => $createdAt
                ]);
            }
            $insertedCount++;

        } else {
            $label = $detail['nilai_label'] ?? null;
            if (!$label)
                continue;

            if (!array_key_exists($label, $opsiValid)) {
                $valid = implode(', ', array_keys($opsiValid));
                throw new Exception("nilai_label '$label' tidak valid. Opsi: $valid");
            }

            $stmtDN->execute([
                ':penilaian_id' => $penilaianId,
                ':rubrik_goal_id' => $rubrikId,
                ':nilai' => null,
                ':nilai_label' => $label,
                ':sumber' => $sumber,
                ':created_at' => $createdAt
            ]);
            $detailNilaiId = (int) $pdo->lastInsertId();

            $subList = $detail['sub_nilai'] ?? [];
            foreach ($subList as $sub) {
                $subId = (int) ($sub['sub_id'] ?? 0);
                $subLabel = $sub['nilai_label'] ?? $label;
                if (!$subId)
                    continue;
                $stmtSub->execute([
                    ':detail_nilai_id' => $detailNilaiId,
                    ':rubrik_subkategori_id' => $subId,
                    ':nilai' => null,
                    ':nilai_label' => $subLabel,
                    ':created_at' => $createdAt
                ]);
            }
            $insertedCount++;
        }
    }

    if ($insertedCount === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Tidak ada nilai yang diisi']);
        exit;
    }

    if ($goalInfo['murid_user_id']) {
        createNotifikasi($pdo, [
            'jenis' => 'nilai_masuk',
            'reference_type' => 'penilaian',
            'reference_id' => $penilaianId,
            'role_pengirim' => 'guru',
            'user_pengirim_id' => $goalInfo['guru_user_id'],
            'context' => [
                'nama_murid' => $goalInfo['nama_murid'],
                'jenis_nilai' => $goalInfo['nama_goal'],
                'tanggal' => date('d/m/Y'),
                'guru_user_id' => $goalInfo['guru_user_id'],
                'murid_user_id' => $goalInfo['murid_user_id']
            ]
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Penilaian berhasil disimpan',
        'data' => [
            'penilaian_id' => $penilaianId,
            'goal_id' => $goalId,
            'tipe_penilaian' => $tipePenilaian,
            'total_rubrik' => $insertedCount
        ]
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}