<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$muridId = $data["murid_id"] ?? null;

if (!$muridId) {
    echo json_encode([
        'success' => false,
        'message' => 'Parameter murid_id tidak lengkap'
    ]);
    exit;
}

try {
    $query = "SELECT 
                g.id as goal_id,
                g.jenis_goal_id,
                g.nama as nama_goal,
                jg.nama as nama_jenis_goal,
                g.status
              FROM tgoal g
              INNER JOIN tjenis_goal jg ON jg.id = g.jenis_goal_id
              WHERE g.murid_id = :murid_id
                AND g.status = 'berjalan'
              ORDER BY g.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':murid_id', $muridId, PDO::PARAM_STR);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $goalList = [];
    foreach ($results as $row) {
        $goalList[] = [
            'goal_id'         => (int)$row['goal_id'],
            'jenis_goal_id'   => (int)$row['jenis_goal_id'],
            'nama_goal'       => $row['nama_goal'],
            'nama_jenis_goal' => $row['nama_jenis_goal'],
            'status'          => $row['status']
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $goalList
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}