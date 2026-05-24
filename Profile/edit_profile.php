<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/database.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['user_id']) || !isset($data['nama']) || !isset($data['username']) || 
        !isset($data['tanggal_lahir']) || !isset($data['alamat']) || !isset($data['no_telp'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Data tidak lengkap. User ID, nama, username, tanggal lahir, alamat, no telp, dan email wajib diisi.'
        ]);
        exit;
    }
    
    $user_id = $data['user_id'];
    $nama = $data['nama'];
    $username = $data['username'];
    $tanggal_lahir = $data['tanggal_lahir'];
    $alamat = $data['alamat'];
    $no_telp = $data['no_telp'];
    $email = isset($data['email']) ? $data['email'] : null;

    $sqlCheckUser = "SELECT id, username FROM tUser WHERE id = :user_id";
    $stmtCheckUser = $pdo->prepare($sqlCheckUser);
    $stmtCheckUser->execute([':user_id' => $user_id]);
    $existingUser = $stmtCheckUser->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingUser) {
        echo json_encode([
            'success' => false,
            'message' => 'User tidak ditemukan'
        ]);
        exit;
    }
    
    $old_username = $existingUser['username'];

    if ($username !== $old_username) {
        $sqlCheckUsername = "SELECT COUNT(*) FROM tUser WHERE username = :username AND id != :user_id";
        $stmtCheckUsername = $pdo->prepare($sqlCheckUsername);
        $stmtCheckUsername->execute([
            ':username' => $username,
            ':user_id' => $user_id
        ]);
        $usernameExists = $stmtCheckUsername->fetchColumn();
        
        if ($usernameExists > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Username sudah digunakan oleh user lain'
            ]);
            exit;
        }
    }
    
    $updated_at = date('Y-m-d H:i:s');

    $sql = "UPDATE tUser 
            SET nama = :nama,
                username = :username,
                tanggal_lahir = :tanggal_lahir,
                email = :email,
                alamat = :alamat,
                no_telp = :no_telp,
                updated_at = :updated_at
            WHERE id = :user_id";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        ':nama' => $nama,
        ':username' => $username,
        ':tanggal_lahir' => $tanggal_lahir,
        ':email' => $email,
        ':alamat' => $alamat,
        ':no_telp' => $no_telp,
        ':updated_at' => $updated_at,
        ':user_id' => $user_id
    ]);
    
    if ($result) {
        $sqlGetUser = "SELECT id, nama, username, email, tanggal_lahir, alamat, no_telp, role, profile_picture 
                       FROM tUser WHERE id = :user_id";
        $stmtGetUser = $pdo->prepare($sqlGetUser);
        $stmtGetUser->execute([':user_id' => $user_id]);
        $updatedUser = $stmtGetUser->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Profile berhasil diupdate',
            'data' => $updatedUser
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Gagal mengupdate profile'
        ]);
    }
    
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