<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data       = json_decode(file_get_contents("php://input"), true);
$id         = $data['id']          ?? null;
$nama       = $data['nama']        ?? null;
$deskripsi  = $data['deskripsi']   ?? null;
$kategoriId = $data['kategori_id'] ?? null;
$link       = $data['link']        ?? null;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Materi ID tidak boleh kosong']);
    exit;
}
if (!$nama || !$kategoriId) {
    echo json_encode(['success' => false, 'message' => 'Nama dan kategori wajib diisi']);
    exit;
}

try {
    $stmtCek = $pdo->prepare(
        "SELECT id, is_group FROM tkategori_materi WHERE id = :id"
    );
    $stmtCek->execute([':id' => $kategoriId]);
    $kategori = $stmtCek->fetch(PDO::FETCH_ASSOC);

    if (!$kategori) {
        echo json_encode(['success' => false, 'message' => 'Kategori tidak ditemukan']);
        exit;
    }

    if ((int)$kategori['is_group'] === 1) {
        echo json_encode([
            'success' => false,
            'message' => 'Tidak bisa menyimpan materi ke kategori grup. Pilih sub-kategori yang spesifik.'
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        "UPDATE tmateri
         SET nama        = :nama,
             deskripsi   = :deskripsi,
             kategori_id = :kategori_id,
             link        = :link,
             updated_at  = NOW()
         WHERE id = :id"
    );
    $stmt->execute([
        ':id'          => $id,
        ':nama'        => $nama,
        ':deskripsi'   => $deskripsi,
        ':kategori_id' => $kategoriId,
        ':link'        => $link
    ]);

    echo json_encode(['success' => true, 'message' => 'Materi berhasil diupdate']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}