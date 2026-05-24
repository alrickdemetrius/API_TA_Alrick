<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['user_id']) || !isset($data['old_password']) || 
        !isset($data['new_password']) || !isset($data['confirm_password'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Data tidak lengkap. User ID, password lama, password baru, dan konfirmasi password wajib diisi.'
        ]);
        exit;
    }
    
    $user_id = $data['user_id'];
    $old_password = $data['old_password'];
    $new_password = $data['new_password'];
    $confirm_password = $data['confirm_password'];

    if ($new_password !== $confirm_password) {
        echo json_encode([
            'success' => false,
            'message' => 'Password baru dan konfirmasi password tidak sama'
        ]);
        exit;
    }

    if (strlen($new_password) < 6) {
        echo json_encode([
            'success' => false,
            'message' => 'Password baru minimal 6 karakter'
        ]);
        exit;
    }

    if ($new_password === $old_password) {
        echo json_encode([
            'success' => false,
            'message' => 'Password baru tidak boleh sama dengan password lama'
        ]);
        exit;
    }

    $sqlCheckUser = "SELECT id, password FROM tUser WHERE id = :user_id";
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

    if (!password_verify($old_password, $existingUser['password'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Password lama tidak sesuai'
        ]);
        exit;
    }

    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $updated_at = date('Y-m-d H:i:s');

    $sql = "UPDATE tUser 
            SET password = :password,
                updated_at = :updated_at
            WHERE id = :user_id";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        ':password' => $new_password_hash,
        ':updated_at' => $updated_at,
        ':user_id' => $user_id
    ]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Password berhasil diubah'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Gagal mengubah password'
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