<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

try {
    
    $stmt = $pdo->query(
        "SELECT id, nama, parent_id, is_group
         FROM tkategori_materi
         ORDER BY COALESCE(parent_id, id), id"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $topLevel = [];
    $subMap   = []; 

    foreach ($rows as $row) {
        $row['id']       = (int)$row['id'];
        $row['is_group'] = (int)$row['is_group'];
        $row['parent_id'] = $row['parent_id'] ? (int)$row['parent_id'] : null;

        if ($row['parent_id'] === null) {
            $topLevel[$row['id']] = $row;
        } else {
            $subMap[$row['parent_id']][] = $row;
        }
    }

    
    $result = [];
    foreach ($topLevel as $id => $cat) {
        $cat['sub'] = $subMap[$id] ?? [];
        $result[]   = $cat;
    }

    echo json_encode(['success' => true, 'data' => $result]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}