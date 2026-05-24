<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$role = $data['role'] ?? null;
$userId = $data['user_id'] ?? null;

if (!$role) {
    echo json_encode(['success' => false, 'message' => 'Role wajib diisi']);
    exit;
}

try {
    $params = [];

    if ($role === 'admin') {
        $query = "SELECT 
                    r.jenis,
                    m.id as murid_id,
                    um.nama as nama_murid,
                    COUNT(*) as total_request
                FROM trequest_jadwal r
                INNER JOIN tmurid m ON m.id = r.murid_id
                INNER JOIN tuser um ON um.id = m.user_id
                WHERE r.status = 'pending'
                GROUP BY r.jenis, m.id, um.nama
                ORDER BY r.jenis, um.nama";

    } elseif ($role === 'guru' && $userId) {
        $query = "SELECT 
                    r.jenis,
                    m.id as murid_id,
                    um.nama as nama_murid,
                    COUNT(*) as total_request
                FROM trequest_jadwal r
                INNER JOIN tmurid m ON m.id = r.murid_id
                INNER JOIN tuser um ON um.id = m.user_id
                WHERE r.status = 'pending'
                  AND EXISTS (
                      SELECT 1 
                      FROM tjadwal_les jl
                      INNER JOIN tguru g ON g.id = jl.guru_id
                      WHERE jl.murid_id = m.id
                        AND g.user_id = :user_id
                        AND jl.status_aktif = 1
                  )
                GROUP BY r.jenis, m.id, um.nama
                ORDER BY r.jenis, um.nama";
        $params[':user_id'] = $userId;

    } elseif ($role === 'murid' && $userId) {
        $query = "SELECT 
                    r.jenis,
                    m.id as murid_id,
                    um.nama as nama_murid,
                    COUNT(*) as total_request
                FROM trequest_jadwal r
                INNER JOIN tmurid m ON m.id = r.murid_id
                INNER JOIN tuser um ON um.id = m.user_id
                WHERE r.status = 'pending'
                  AND m.user_id = :user_id
                GROUP BY r.jenis, m.id, um.nama
                ORDER BY r.jenis, um.nama";
        $params[':user_id'] = $userId;

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid role or missing user_id']);
        exit;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [
        'tambahan' => [],
        'pengganti' => []
    ];

    foreach ($results as $row) {
        $grouped[$row['jenis']][] = [
            'murid_id' => $row['murid_id'],
            'nama_murid' => $row['nama_murid'],
            'total_request' => (int) $row['total_request']
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $grouped
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}