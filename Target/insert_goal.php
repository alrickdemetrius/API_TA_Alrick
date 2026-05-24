<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

set_exception_handler(function($e) {
    echo json_encode(['success' => false, 'message' => 'Uncaught: ' . $e->getMessage()]);
    exit;
});
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo json_encode(['success' => false, 'message' => "PHP Error [$errno]: $errstr in $errfile:$errline"]);
    exit;
});

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['murid_id']))       { echo json_encode(['success'=>false,'message'=>'Murid wajib dipilih']); exit; }
if (empty($data['jenis_goal_id']))  { echo json_encode(['success'=>false,'message'=>'Jenis target wajib dipilih']); exit; }
if (empty($data['tanggal_target'])) { echo json_encode(['success'=>false,'message'=>'Tanggal target wajib diisi']); exit; }
if (empty($data['detail_kategoris'])) { echo json_encode(['success'=>false,'message'=>'Detail kategori penilaian wajib diisi']); exit; }

$muridId      = trim($data['murid_id']);
$jenisGoalId  = (int)$data['jenis_goal_id'];
$namaGoal     = isset($data['nama']) ? trim($data['nama']) : null;
$tanggal      = trim($data['tanggal_target']);
$catatan      = isset($data['catatan_umum']) ? trim($data['catatan_umum']) : null;
$details      = $data['detail_kategoris'];
$opsiKustom   = $data['opsi_nilai'] ?? [];   // [{label, encode_value}]
$labelKelas   = $data['label_kelas'] ?? [];  // [string×4]: label untuk indeks 0,1,2,3
$guruUserId   = $data['guru_user_id'] ?? null;

function hitungEncodeValue(int $urutan, int $total): float {
    if ($total <= 1) return 0.0;
    return round(($urutan - 1) / ($total - 1) * 3, 3);
}

try {
    $pdo->beginTransaction();
    $now = date('Y-m-d H:i:s');

    $stmtMurid = $pdo->prepare("
        SELECT m.id, um.id AS murid_user_id, um.nama AS nama_murid
        FROM tmurid m INNER JOIN tuser um ON um.id = m.user_id
        WHERE m.id = ?
    ");
    $stmtMurid->execute([$muridId]);
    $muridInfo = $stmtMurid->fetch(PDO::FETCH_ASSOC);
    if (!$muridInfo) throw new Exception('Data murid tidak ditemukan');

    $stmtJenis = $pdo->prepare("SELECT id, nama, tipe_penilaian FROM tjenis_goal WHERE id = ?");
    $stmtJenis->execute([$jenisGoalId]);
    $jenisData = $stmtJenis->fetch(PDO::FETCH_ASSOC);
    if (!$jenisData) throw new Exception('Jenis target tidak ditemukan');

    $stmtRubrikList = $pdo->prepare("SELECT id FROM trubrik_goal WHERE jenis_goal_id = ?");
    $stmtRubrikList->execute([$jenisGoalId]);
    $rubrikIds = $stmtRubrikList->fetchAll(PDO::FETCH_COLUMN);

    $detailByRubrik = [];
    foreach ($details as $d) {
        if (!empty($d['rubrik_goal_id'])) {
            $detailByRubrik[(int)$d['rubrik_goal_id']] = $d;
        }
    }

    foreach ($rubrikIds as $rid) {
        $d = $detailByRubrik[$rid] ?? null;
        if (!$d || empty($d['sub_kategori'])) {
            $stmtKat = $pdo->prepare("SELECT kategori FROM trubrik_goal WHERE id = ?");
            $stmtKat->execute([$rid]);
            $kat = $stmtKat->fetchColumn();
            throw new Exception("Rubrik \"$kat\" wajib memiliki minimal 1 sub kategori");
        }
    }

    $stmtGuru = $pdo->prepare("
        SELECT ug.id AS guru_user_id, ug.nama AS nama_guru
        FROM tjadwal_les j
        INNER JOIN tguru g  ON g.id  = j.guru_id
        INNER JOIN tuser ug ON ug.id = g.user_id
        WHERE j.murid_id = ? AND j.status_aktif = 1 LIMIT 1
    ");
    $stmtGuru->execute([$muridId]);
    $guruInfo = $stmtGuru->fetch(PDO::FETCH_ASSOC);

    if (!$guruInfo && $guruUserId) {
        $stmtGuruDirect = $pdo->prepare("SELECT id AS guru_user_id, nama AS nama_guru FROM tuser WHERE id = ?");
        $stmtGuruDirect->execute([$guruUserId]);
        $guruInfo = $stmtGuruDirect->fetch(PDO::FETCH_ASSOC);
    }
    if (!$guruInfo) throw new Exception('Data guru tidak ditemukan');

    $stmtGoal = $pdo->prepare("
        INSERT INTO tgoal (murid_id, jenis_goal_id, nama, catatan_umum, tanggal_target, label_kelas_0, label_kelas_1, label_kelas_2, label_kelas_3, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtGoal->execute([
        $muridId, $jenisGoalId, $namaGoal, $catatan, $tanggal,
        $labelKelas[0] ?? null, $labelKelas[1] ?? null,
        $labelKelas[2] ?? null, $labelKelas[3] ?? null,
        $now
    ]);
    $goalId = (int)$pdo->lastInsertId();

    if ($jenisData['tipe_penilaian'] === 'kategorik' && !empty($opsiKustom)) {
        $jumlahLabel = count($opsiKustom);
        $stmtOpsi = $pdo->prepare("
            INSERT INTO tgoal_opsi_nilai (goal_id, label, urutan_ke, encode_value, created_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($opsiKustom as $i => $opsi) {
            $urutan      = $i + 1;
            $encodeValue = hitungEncodeValue($urutan, $jumlahLabel);
            $stmtOpsi->execute([
                $goalId,
                trim($opsi['label']),
                $urutan,
                $encodeValue,
                $now
            ]);
        }
    }

    $stmtDetail = $pdo->prepare("
        INSERT INTO tdetail_goal (goal_id, rubrik_goal_id, catatan, created_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmtSub = $pdo->prepare("
        INSERT INTO trubrik_subkategori (goal_id, rubrik_goal_id, nama, nilai_max, urutan_ke, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmtMateri = $pdo->prepare("
        INSERT INTO tdetail_goal_materi (detail_goal_id, materi_id, created_at)
        VALUES (?, ?, ?)
    ");

    foreach ($details as $d) {
        if (empty($d['rubrik_goal_id'])) continue;
        $rubrikId  = (int)$d['rubrik_goal_id'];
        $catRubrik = isset($d['catatan']) ? trim($d['catatan']) : null;
        $materiIds = array_filter(array_map('intval', $d['materi_ids'] ?? []));
        $subList   = $d['sub_kategori'] ?? [];

        $stmtDetail->execute([$goalId, $rubrikId, $catRubrik ?: null, $now]);
        $detailId = (int)$pdo->lastInsertId();

        foreach ($subList as $idx => $sub) {
            if (empty($sub['nama'])) continue;
            $stmtSub->execute([
                $goalId,
                $rubrikId,
                trim($sub['nama']),
                (float)($sub['nilai_max'] ?? 0),
                $idx + 1,
                $now
            ]);
        }

        foreach ($materiIds as $mId) {
            $stmtMateri->execute([$detailId, $mId, $now]);
        }
    }

    $pdo->commit();

    try {
        createNotifikasi($pdo, [
            'jenis'            => 'goal_baru',
            'reference_type'   => 'goal',
            'reference_id'     => $goalId,
            'role_pengirim'    => 'guru',
            'user_pengirim_id' => $guruInfo['guru_user_id'],
            'context' => [
                'nama_murid'      => $muridInfo['nama_murid'],
                'nama_guru'       => $guruInfo['nama_guru'],
                'nama_goal'       => $namaGoal ?? $jenisData['nama'],
                'nama_jenis_goal' => $jenisData['nama'],
                'guru_user_id'    => $guruInfo['guru_user_id'],
                'murid_user_id'   => $muridInfo['murid_user_id']
            ]
        ]);
    } catch (Exception $eNotif) {
        error_log('[insert_goal] Notifikasi gagal: ' . $eNotif->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Target berhasil dibuat',
        'data'    => ['goal_id' => $goalId, 'murid_id' => $muridId]
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}