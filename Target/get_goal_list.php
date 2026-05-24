<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['user_id']) || empty($data['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Parameter user_id tidak lengkap'
    ]);
    exit;
}

if (!isset($data['role']) || empty($data['role'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Parameter role tidak lengkap'
    ]);
    exit;
}

$userId = trim($data['user_id']);
$role = trim($data['role']);

try {
    $query = "";
    
    if ($role === 'admin') {
        $query = "SELECT 
                    g.id as goal_id,
                    g.murid_id,
                    g.jenis_goal_id,
                    g.nama,
                    g.catatan_umum,
                    g.tanggal_target,
                    g.status,
                    g.hasil_akhir,
                    g.created_at,
                    m.id as murid_id,
                    um.nama as nama_murid,
                    m.grade,
                    jg.id as jenis_goal_id,
                    jg.nama as nama_jenis_goal,
                    jg.deskripsi as deskripsi_jenis_goal
                  FROM tgoal g
                  INNER JOIN tmurid m ON g.murid_id = m.id
                  INNER JOIN tuser um ON m.user_id = um.id
                  INNER JOIN tjenis_goal jg ON g.jenis_goal_id = jg.id
                  ORDER BY g.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        
    } else if ($role === 'guru') {
        $query = "SELECT 
                    g.id as goal_id,
                    g.murid_id,
                    g.jenis_goal_id,
                    g.nama,
                    g.catatan_umum,
                    g.tanggal_target,
                    g.status,
                    g.hasil_akhir,
                    g.created_at,
                    m.id as murid_id,
                    um.nama as nama_murid,
                    m.grade,
                    jg.id as jenis_goal_id,
                    jg.nama as nama_jenis_goal,
                    jg.deskripsi as deskripsi_jenis_goal
                  FROM tgoal g
                  INNER JOIN tmurid m ON g.murid_id = m.id
                  INNER JOIN tuser um ON m.user_id = um.id
                  INNER JOIN tjenis_goal jg ON g.jenis_goal_id = jg.id
                  INNER JOIN tjadwal_les jl ON jl.murid_id = m.id AND jl.status_aktif = 1
                  INNER JOIN tguru gu ON gu.id = jl.guru_id
                  WHERE gu.user_id = :user_id
                  GROUP BY g.id
                  ORDER BY g.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();
        
    } else if ($role === 'murid') {
        $query = "SELECT 
                    g.id as goal_id,
                    g.murid_id,
                    g.jenis_goal_id,
                    g.nama,
                    g.catatan_umum,
                    g.tanggal_target,
                    g.status,
                    g.hasil_akhir,
                    g.created_at,
                    m.id as murid_id,
                    um.nama as nama_murid,
                    m.grade,
                    jg.id as jenis_goal_id,
                    jg.nama as nama_jenis_goal,
                    jg.deskripsi as deskripsi_jenis_goal
                  FROM tgoal g
                  INNER JOIN tmurid m ON g.murid_id = m.id
                  INNER JOIN tuser um ON m.user_id = um.id
                  INNER JOIN tjenis_goal jg ON g.jenis_goal_id = jg.id
                  WHERE m.user_id = :user_id
                  ORDER BY g.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();
        
    } else {
        throw new Exception('Role tidak valid');
    }

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $goalList = [];
    foreach ($results as $row) {
        $goalList[] = [
            'goal_id' => (int)$row['goal_id'],
            'murid_id' => $row['murid_id'],
            'nama_murid' => $row['nama_murid'],
            'grade' => (int)$row['grade'],
            'nama' => $row['nama'],
            'jenis_goal_id' => (int)$row['jenis_goal_id'],
            'nama_jenis_goal' => $row['nama_jenis_goal'],
            'deskripsi_jenis_goal' => $row['deskripsi_jenis_goal'],
            'catatan_umum' => $row['catatan_umum'],
            'tanggal_target' => $row['tanggal_target'],
            'status' => $row['status'],
            'hasil_akhir' => $row['hasil_akhir'],
            'created_at' => $row['created_at']
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $goalList,
        'total' => count($goalList)
    ]);

} catch (PDOException $e) {
    
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}