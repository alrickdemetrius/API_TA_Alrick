<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

try {
    $query = "SELECT 
                g.id as guru_id,
                u.nama as nama_guru,
                u.email,
                u.no_telp,
                g.created_at
            FROM tguru g
            INNER JOIN tuser u ON u.id = g.user_id
            WHERE u.deleted_at IS NULL
            ORDER BY u.nama ASC";

    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $results,
        'total' => count($results)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}