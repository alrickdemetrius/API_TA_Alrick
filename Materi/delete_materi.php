<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data     = json_decode(file_get_contents("php://input"), true);
$materiId = $data['materi_id'] ?? null;

if (!$materiId) {
    echo json_encode(['success' => false, 'message' => 'materi_id wajib diisi']);
    exit;
}

try {
    $stmtCheck = $pdo->prepare("SELECT id, nama FROM tmateri WHERE id = :id");
    $stmtCheck->execute([':id' => $materiId]);
    $materi = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$materi) {
        echo json_encode(['success' => false, 'message' => 'Materi tidak ditemukan']);
        exit;
    }

    $stmtCekTugas = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM ttugas_materi tm
        INNER JOIN ttugas t ON t.id = tm.tugas_id
        WHERE tm.materi_id = :materi_id
          AND t.status_tugas NOT IN ('selesai', 'terlambat')
    ");
    $stmtCekTugas->execute([':materi_id' => $materiId]);
    $cekTugas = $stmtCekTugas->fetch(PDO::FETCH_ASSOC);

    if ((int)$cekTugas['total'] > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Materi tidak dapat dihapus karena masih digunakan di ' .
                         $cekTugas['total'] . ' tugas yang sedang berjalan'
        ]);
        exit;
    }

    $pdo->prepare("DELETE FROM tmateri WHERE id = :id")
        ->execute([':id' => $materiId]);

    echo json_encode([
        'success' => true,
        'message' => 'Materi berhasil dihapus',
        'data'    => ['materi_id' => (int)$materiId, 'nama' => $materi['nama']]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
