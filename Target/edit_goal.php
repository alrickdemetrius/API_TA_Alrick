<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['goal_id']))          { echo json_encode(['success'=>false,'message'=>'goal_id wajib diisi']); exit; }
if (empty($data['jenis_goal_id']))    { echo json_encode(['success'=>false,'message'=>'Jenis target wajib dipilih']); exit; }
if (empty($data['tanggal_target']))   { echo json_encode(['success'=>false,'message'=>'Tanggal target wajib diisi']); exit; }
if (empty($data['detail_kategoris'])){ echo json_encode(['success'=>false,'message'=>'Detail kategori wajib diisi']); exit; }

$goalId      = (int)$data['goal_id'];
$jenisGoalId = (int)$data['jenis_goal_id'];
$namaGoal    = isset($data['nama']) ? trim($data['nama']) : null;
$tanggal     = trim($data['tanggal_target']);
$catatan     = isset($data['catatan_umum']) ? trim($data['catatan_umum']) : null;
$details     = $data['detail_kategoris'];
$opsiKustom  = $data['opsi_nilai'] ?? [];
$labelKelas  = $data['label_kelas'] ?? [];

function hitungEncodeValue(int $urutan, int $total): float {
    if ($total <= 1) return 0.0;
    return round(($urutan - 1) / ($total - 1) * 3, 3);
}

try {
    $pdo->beginTransaction();
    $now = date('Y-m-d H:i:s');

    // goal + status
    $stmtCheck = $pdo->prepare("SELECT id, murid_id, status FROM tgoal WHERE id = ?");
    $stmtCheck->execute([$goalId]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$existing) throw new Exception('Target tidak ditemukan');
    if ($existing['status'] !== 'berjalan') {
        throw new Exception('Target tidak dapat diubah karena sudah ' . $existing['status']);
    }

    $stmtJenis = $pdo->prepare("SELECT id, nama, tipe_penilaian FROM tjenis_goal WHERE id = ?");
    $stmtJenis->execute([$jenisGoalId]);
    $jenisData = $stmtJenis->fetch(PDO::FETCH_ASSOC);
    if (!$jenisData) throw new Exception('Jenis target tidak ditemukan');

    // minimal 1 sub kategori
    $stmtRubrikList = $pdo->prepare("SELECT id FROM trubrik_goal WHERE jenis_goal_id = ?");
    $stmtRubrikList->execute([$jenisGoalId]);
    $rubrikIds = $stmtRubrikList->fetchAll(PDO::FETCH_COLUMN);

    $detailByRubrik = [];
    foreach ($details as $d) {
        if (!empty($d['rubrik_goal_id'])) $detailByRubrik[(int)$d['rubrik_goal_id']] = $d;
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

    $pdo->prepare("
        UPDATE tgoal SET jenis_goal_id=?, nama=?, catatan_umum=?, tanggal_target=?,
            label_kelas_0=?, label_kelas_1=?, label_kelas_2=?, label_kelas_3=?, updated_at=?
        WHERE id=?
    ")->execute([
        $jenisGoalId, $namaGoal, $catatan, $tanggal,
        $labelKelas[0] ?? null, $labelKelas[1] ?? null,
        $labelKelas[2] ?? null, $labelKelas[3] ?? null,
        $now, $goalId
    ]);

    // opsi nilai + encode_value interpolasi
    $pdo->prepare("DELETE FROM tgoal_opsi_nilai WHERE goal_id = ?")->execute([$goalId]);

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
                $goalId, trim($opsi['label']), $urutan, $encodeValue, $now
            ]);
        }
    }

    // hapus detail lama
    $stmtOldDetails = $pdo->prepare("SELECT id FROM tdetail_goal WHERE goal_id = ?");
    $stmtOldDetails->execute([$goalId]);
    $oldIds = $stmtOldDetails->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($oldIds)) {
        $ph = implode(',', array_fill(0, count($oldIds), '?'));
        $pdo->prepare("DELETE FROM tdetail_goal_materi WHERE detail_goal_id IN ($ph)")->execute($oldIds);
    }
    $pdo->prepare("DELETE FROM tdetail_goal WHERE goal_id = ?")->execute([$goalId]);

    // hapus sub lama
    $stmtRubrik = $pdo->prepare("SELECT id FROM trubrik_goal WHERE jenis_goal_id = ?");
    $stmtRubrik->execute([$jenisGoalId]);
    $rIds = $stmtRubrik->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($rIds)) {
        $ph2 = implode(',', array_fill(0, count($rIds), '?'));
        $pdo->prepare("DELETE FROM trubrik_subkategori WHERE rubrik_goal_id IN ($ph2)")->execute($rIds);
    }

    // insert
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
            $stmtSub->execute([$goalId, $rubrikId, trim($sub['nama']), (float)($sub['nilai_max'] ?? 0), $idx+1, $now]);
        }

        foreach ($materiIds as $mId) {
            $stmtMateri->execute([$detailId, $mId, $now]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Target berhasil diperbarui',
        'data'    => ['goal_id' => $goalId]
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}