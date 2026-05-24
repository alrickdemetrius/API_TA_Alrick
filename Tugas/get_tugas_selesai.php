<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data    = json_decode(file_get_contents("php://input"), true);
$muridId = $data['murid_id'] ?? null;

if (!$muridId) {
    echo json_encode(['success' => false, 'message' => 'murid_id wajib diisi']);
    exit;
}

try {
    // Tugas selesai
    $stmtTugas = $pdo->prepare("
        SELECT t.id AS tugas_id, t.judul, t.deskripsi_tugas,
               t.deadline, t.status AS status_tugas,
               t.waktu_pengumpulan, t.is_dinilai, t.created_at,
               ug.nama AS nama_guru,
               um.nama AS nama_murid, m.id AS murid_id, m.grade,
               g.id AS goal_id, jg.nama AS nama_goal
        FROM ttugas t
        INNER JOIN tjadwal_les jl ON jl.id = t.jadwal_id
        INNER JOIN tmurid m       ON m.id  = jl.murid_id
        INNER JOIN tuser um       ON um.id = m.user_id
        INNER JOIN tguru guru     ON guru.id = jl.guru_id
        INNER JOIN tuser ug       ON ug.id = guru.user_id
        LEFT  JOIN tgoal g        ON g.id  = t.goal_id
        LEFT  JOIN tjenis_goal jg ON jg.id = g.jenis_goal_id
        WHERE m.id = ? AND t.status IN ('selesai', 'batal')
        ORDER BY t.waktu_pengumpulan DESC
    ");
    $stmtTugas->execute([$muridId]);
    $tasks = $stmtTugas->fetchAll(PDO::FETCH_ASSOC);

    // ── Per task: ambil nilai dan files ─────────────────────────────────────
    $stmtNilai = $pdo->prepare("
        SELECT rg.kategori AS rubrik, dn.nilai, dn.nilai_label
        FROM tpenilaian p
        INNER JOIN tdetail_nilai dn ON dn.penilaian_id = p.id
        INNER JOIN trubrik_goal rg  ON rg.id = dn.rubrik_goal_id
        WHERE p.tugas_id = ? AND dn.sumber = 'tugas'
        ORDER BY rg.urutan_ke
    ");

    $stmtFiles = $pdo->prepare("
        SELECT id, nama_file, nama_asli, ukuran
        FROM ttugas_file
        WHERE tugas_id = ?
        ORDER BY created_at ASC
    ");

    foreach ($tasks as &$task) {
        $task['is_dinilai'] = (bool)$task['is_dinilai'];

        // Nilai
        $stmtNilai->execute([$task['tugas_id']]);
        $nilaiRows = $stmtNilai->fetchAll(PDO::FETCH_ASSOC);

        $task['nilai_details'] = array_map(fn($r) => [
            'rubrik'       => $r['rubrik'],
            'nilai'        => $r['nilai'] !== null ? (float)$r['nilai'] : null,
            'nilai_label'  => $r['nilai_label']
        ], $nilaiRows);

        // Rata-rata untuk numerikal
        $numValues = array_filter(array_column($nilaiRows, 'nilai'), fn($v) => $v !== null);
        $task['nilai_rata_rata'] = count($numValues) > 0
            ? round(array_sum($numValues) / count($numValues), 1)
            : null;

        // Files
        $stmtFiles->execute([$task['tugas_id']]);
        $task['files'] = array_map(fn($f) => [
            'id'        => (int)$f['id'],
            'nama_file' => $f['nama_file'],
            'nama_asli' => $f['nama_asli'],
            'ukuran_kb' => round((int)$f['ukuran'] / 1024, 1)
        ], $stmtFiles->fetchAll(PDO::FETCH_ASSOC));
    }

    echo json_encode([
        'success'  => true,
        'data'     => $tasks,
        'count'    => count($tasks),
        'murid_id' => $muridId
    ]);

} catch (PDOException $e) {
    error_log("get_tugas_selesai error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}