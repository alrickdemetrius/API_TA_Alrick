<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

try {
    $stmt = $pdo->query(
        "SELECT
            m.id,
            m.nama,
            m.deskripsi,
            m.kategori_id,
            m.local_file,
            m.link,
            m.sheet,
            m.created_at,
            k.nama       AS nama_kategori,
            k.parent_id,
            kp.nama      AS nama_parent
         FROM tmateri m
         JOIN tkategori_materi k  ON k.id  = m.kategori_id
         LEFT JOIN tkategori_materi kp ON kp.id = k.parent_id
         ORDER BY COALESCE(k.parent_id, k.id), k.id, m.nama"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    
    $groups = [];
    foreach ($rows as $row) {
        $kid = (int)$row['kategori_id'];

        if (!isset($groups[$kid])) {
            $groups[$kid] = [
                'kategori_id'   => $kid,
                'nama_kategori' => $row['nama_kategori'],
                'parent_id'     => $row['parent_id'] ? (int)$row['parent_id'] : null,
                'nama_parent'   => $row['nama_parent'],
                'items'         => []
            ];
        }

        $groups[$kid]['items'][] = [
            'id'            => (int)$row['id'],
            'nama'          => $row['nama'],
            'deskripsi'     => $row['deskripsi'],
            'kategori_id'   => $kid,
            'nama_kategori' => $row['nama_kategori'],
            'nama_parent'   => $row['nama_parent'],
            'local_file'    => $row['local_file'],
            'link'          => $row['link'],
            'sheet'         => $row['sheet'],
            'created_at'    => $row['created_at']
        ];
    }

    echo json_encode([
        'success' => true,
        'data'    => array_values($groups),
        'total'   => count($rows)
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}