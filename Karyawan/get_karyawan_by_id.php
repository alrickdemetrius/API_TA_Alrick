<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'ID karyawan tidak ditemukan'
        ]);
        exit;
    }
    
    $userId = $data['id'];

    $sql = "SELECT u.id, u.nik, u.nama, u.tanggal_lahir, u.email, u.alamat, u.no_telp, u.role,
                   g.id as guru_id, g.min_grade, g.max_grade
            FROM tUser u
            LEFT JOIN tGuru g ON u.id = g.user_id
            WHERE u.id = :user_id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $userId]);
    $karyawan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$karyawan) {
        echo json_encode([
            'success' => false,
            'message' => 'Data karyawan tidak ditemukan'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $karyawan
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}