<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data       = json_decode(file_get_contents("php://input"), true);
$kategoriId = $data['kategori_id'] ?? null;

if (!$kategoriId) {
    echo json_encode(['success' => false, 'message' => 'kategori_id tidak boleh kosong']);
    exit;
}

try {
    $stmtCek = $pdo->prepare("SELECT id, nama, is_group FROM tkategori_materi WHERE id = :id");
    $stmtCek->execute([':id' => $kategoriId]);
    $kategori = $stmtCek->fetch(PDO::FETCH_ASSOC);

    if (!$kategori) {
        echo json_encode(['success' => false, 'message' => 'Kategori tidak ditemukan']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT
            m.id,
            m.nama,
            m.deskripsi,
            m.kategori_id,
            m.local_file,
            m.link,
            m.sheet,
            k.nama      AS nama_kategori,
            k.parent_id,
            CASE
                WHEN k.parent_id = :kategori_id THEN k.nama
                ELSE NULL
            END AS sub_kategori_nama
         FROM tmateri m
         JOIN tkategori_materi k ON k.id = m.kategori_id
         WHERE m.kategori_id = :kategori_id
            OR k.parent_id   = :kategori_id
         ORDER BY sub_kategori_nama IS NULL ASC, sub_kategori_nama ASC, m.nama ASC"
    );
    $stmt->execute([':kategori_id' => $kategoriId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $list = [];
    foreach ($rows as $row) {
        $list[] = [
            'id'                => (int)$row['id'],
            'nama'              => $row['nama'],
            'deskripsi'         => $row['deskripsi'],
            'kategori_id'       => (int)$row['kategori_id'],
            'nama_kategori'     => $row['nama_kategori'],
            'sub_kategori_nama' => $row['sub_kategori_nama'],
            'local_file'        => $row['local_file'],
            'link'              => $row['link'],
            'sheet'             => $row['sheet']
        ];
    }

    echo json_encode(['success' => true, 'data' => $list, 'total' => count($list)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}