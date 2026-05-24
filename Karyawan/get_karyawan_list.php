<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$role = $data['role'] ?? null;
$roleFilter = $data['roleFilter'] ?? 'semua';

if (!$role) {
    echo json_encode([
        'success' => false,
        'message' => 'Parameter tidak lengkap'
    ]);
    exit;
}

try {
    if ($role === 'admin' || $role === 'guru') {

        $query = "SELECT u.id as user_id, 
                        g.id as guru_id, 
                        u.nik as nik, 
                        u.nama as nama,
                        u.email, 
                        u.alamat, 
                        u.no_telp, 
                        u.profile_picture as foto, 
                        u.role as role,
                        g.min_grade as min_grade, 
                        g.max_grade as max_grade
                    FROM tuser u 
                    LEFT JOIN tguru g ON u.id = g.user_id
                    WHERE u.role != 'murid'
                      AND u.deleted_at IS NULL";

        if ($roleFilter !== 'semua') {
            $query .= " AND u.role = :roleFilter";
        }

        $query .= " ORDER BY u.nama ASC";

        $stmt = $pdo->prepare($query);
        
        if ($roleFilter !== 'semua') {
            $stmt->bindParam(':roleFilter', $roleFilter, PDO::PARAM_STR);
        }
        
        $stmt->execute();
        $karyawan = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $karyawan
        ]);
        exit;
    } else {
        echo json_encode([
            'success' => false,
            'message' => "Anda tidak memiliki akses."
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}