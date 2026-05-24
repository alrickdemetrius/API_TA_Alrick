<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

$data = json_decode(file_get_contents("php://input"), true);

$goalId     = $data['goal_id']     ?? null;
$status     = $data['status']      ?? null;
$hasilAkhir = $data['hasil_akhir'] ?? null;
$guruUserId = $data['guru_user_id'] ?? null;

if (!$goalId || !$status) {
    echo json_encode(['success' => false, 'message' => 'goal_id dan status wajib diisi']);
    exit;
}
if (!in_array($status, ['selesai', 'batal'])) {
    echo json_encode(['success' => false, 'message' => 'Status harus "selesai" atau "batal"']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmtCheck = $pdo->prepare("
        SELECT
            g.id, g.status,
            jg.nama AS nama_goal,
            um.id AS murid_user_id, um.nama AS nama_murid,
            ug.id AS guru_user_id,  ug.nama AS nama_guru
        FROM tgoal g
        INNER JOIN tjenis_goal jg ON jg.id = g.jenis_goal_id
        INNER JOIN tmurid m       ON m.id  = g.murid_id
        INNER JOIN tuser um       ON um.id = m.user_id
        LEFT  JOIN tjadwal_les jl ON jl.murid_id = m.id AND jl.status_aktif = 1
        LEFT  JOIN tguru gu       ON gu.id = jl.guru_id
        LEFT  JOIN tuser ug       ON ug.id = gu.user_id
        WHERE g.id = :goal_id
        LIMIT 1");
    $stmtCheck->execute([':goal_id' => $goalId]);
    $goalInfo = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$goalInfo) throw new Exception('Goal tidak ditemukan');
    if ($goalInfo['status'] !== 'berjalan') {
        throw new Exception('Goal dengan status "' . $goalInfo['status'] . '" tidak dapat diubah');
    }

    if (!$goalInfo['guru_user_id'] && $guruUserId) {
        $stmtGuru = $pdo->prepare("SELECT id AS guru_user_id, nama AS nama_guru FROM tuser WHERE id = :id");
        $stmtGuru->execute([':id' => $guruUserId]);
        $guruData = $stmtGuru->fetch(PDO::FETCH_ASSOC);
        if ($guruData) {
            $goalInfo['guru_user_id'] = $guruData['guru_user_id'];
            $goalInfo['nama_guru']    = $guruData['nama_guru'];
        }
    }

    if ($status === 'selesai' && !$hasilAkhir) {
        $stmtPred = $pdo->prepare("
            SELECT prediksi_kategori
            FROM tlog_prediksi
            WHERE goal_id = :goal_id
            ORDER BY created_at DESC
            LIMIT 1");
        $stmtPred->execute([':goal_id' => $goalId]);
        $prediksi = $stmtPred->fetch(PDO::FETCH_ASSOC);
        if ($prediksi) {
            $hasilAkhir = $prediksi['prediksi_kategori'];
            $pdo->prepare("
                UPDATE tlog_prediksi SET hasil_akhir_diset = 1
                WHERE goal_id = :goal_id ORDER BY created_at DESC LIMIT 1")
                ->execute([':goal_id' => $goalId]);
        }
    }

    $pdo->prepare("
        UPDATE tgoal
        SET status = :status, hasil_akhir = :hasil_akhir, updated_at = NOW()
        WHERE id = :goal_id")
        ->execute([
            ':status'      => $status,
            ':hasil_akhir' => $hasilAkhir,
            ':goal_id'     => $goalId
        ]);

    $jenis = $status === 'selesai' ? 'goal_selesai' : 'goal_batal';
    createNotifikasi($pdo, [
        'jenis'            => $jenis,
        'reference_type'   => 'goal',
        'reference_id'     => $goalId,
        'role_pengirim'    => 'guru',
        'user_pengirim_id' => $goalInfo['guru_user_id'],
        'context' => [
            'nama_murid'    => $goalInfo['nama_murid'],
            'nama_goal'     => $goalInfo['nama_goal'],
            'hasil_akhir'   => $hasilAkhir,
            'nama_guru'     => $goalInfo['nama_guru'],
            'guru_user_id'  => $goalInfo['guru_user_id'],
            'murid_user_id' => $goalInfo['murid_user_id']
        ]
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $status === 'selesai' ? 'Target berhasil diselesaikan' : 'Target berhasil dibatalkan',
        'data' => [
            'goal_id'     => (int)$goalId,
            'status'      => $status,
            'hasil_akhir' => $hasilAkhir
        ]
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}