<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);
$id   = $data['id'] ?? null;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Materi ID tidak boleh kosong']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT
            m.id,
            m.nama,
            m.deskripsi,
            m.kategori_id,
            m.local_file,
            m.link,
            m.sheet,
            m.created_at,
            m.updated_at,
            k.nama AS nama_kategori,
            k.parent_id,
            k.is_group,
            kp.nama AS nama_parent
         FROM tmateri m
         JOIN tkategori_materi k  ON k.id  = m.kategori_id
         LEFT JOIN tkategori_materi kp ON kp.id = k.parent_id
         WHERE m.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Materi tidak ditemukan']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'id'            => (int)$row['id'],
            'nama'          => $row['nama'],
            'deskripsi'     => $row['deskripsi'],
            'kategori_id'   => (int)$row['kategori_id'],
            'nama_kategori' => $row['nama_kategori'],
            'parent_id'     => $row['parent_id'] ? (int)$row['parent_id'] : null,
            'nama_parent'   => $row['nama_parent'],
            'is_group'      => (int)$row['is_group'],
            'local_file'    => $row['local_file'],
            'link'          => $row['link'],
            'sheet'         => $row['sheet'],
            'created_at'    => $row['created_at'],
            'updated_at'    => $row['updated_at']
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}