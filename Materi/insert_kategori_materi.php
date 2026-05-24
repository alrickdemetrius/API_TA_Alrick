<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data     = json_decode(file_get_contents("php://input"), true);
$nama     = trim($data['nama']      ?? '');
$parentId = $data['parent_id']      ?? null;

if (!$nama) {
    echo json_encode(['success' => false, 'message' => 'Nama kategori wajib diisi']);
    exit;
}

try {
    if ($parentId !== null) {
        $stmtParent = $pdo->prepare(
            "SELECT id, is_group FROM tkategori_materi WHERE id = :id"
        );
        $stmtParent->execute([':id' => $parentId]);
        $parent = $stmtParent->fetch(PDO::FETCH_ASSOC);

        if (!$parent) {
            echo json_encode(['success' => false, 'message' => 'Kategori induk tidak ditemukan']);
            exit;
        }

        if ((int)$parent['is_group'] === 0) {
            $pdo->prepare("UPDATE tkategori_materi SET is_group = 1 WHERE id = :id")
                ->execute([':id' => $parentId]);
        }
    }

    $stmtCek = $pdo->prepare(
        "SELECT id FROM tkategori_materi
         WHERE LOWER(nama) = LOWER(:nama)
           AND " . ($parentId !== null ? "parent_id = :pid" : "parent_id IS NULL")
    );
    $params = [':nama' => $nama];
    if ($parentId !== null) $params[':pid'] = $parentId;
    $stmtCek->execute($params);

    if ($stmtCek->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'Nama kategori sudah ada pada level yang sama'
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO tkategori_materi (nama, parent_id, is_group, created_at)
         VALUES (:nama, :parent_id, 0, NOW())"
    );
    $stmt->execute([
        ':nama'      => $nama,
        ':parent_id' => $parentId
    ]);

    $newId = (int)$pdo->lastInsertId();

    echo json_encode([
        'success'   => true,
        'message'   => 'Kategori berhasil ditambahkan',
        'id'        => $newId,
        'nama'      => $nama,
        'parent_id' => $parentId
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}