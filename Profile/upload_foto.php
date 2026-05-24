<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Origin');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

$uploadDir = __DIR__ . '/../uploads/user_profile/';
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
$maxFileSize = 5 * 1024 * 1024; // 5MB

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

try {
    error_log("POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));

    if (!isset($_POST['user_id']) || empty($_POST['user_id'])) {
        throw new Exception('User ID tidak ditemukan');
    }

    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File terlalu besar (melebihi upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE => 'File terlalu besar (melebihi MAX_FILE_SIZE)',
            UPLOAD_ERR_PARTIAL => 'File hanya terupload sebagian',
            UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diupload',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary tidak ditemukan',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk',
            UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi PHP'
        ];
        
        $errorCode = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errorMsg = $errorMessages[$errorCode] ?? 'Error upload file';
        throw new Exception($errorMsg);
    }

    $user_id = $_POST['user_id'];
    $file = $_FILES['photo'];

    if ($file['size'] > $maxFileSize) {
        throw new Exception('Ukuran file terlalu besar. Maksimal 5MB');
    }

    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, $allowedExtensions)) {
        throw new Exception('Format file tidak didukung. Gunakan: jpg, jpeg, png, gif');
    }

    $sqlCheckUser = "SELECT id, profile_picture FROM tUser WHERE id = :user_id";
    $stmtCheckUser = $pdo->prepare($sqlCheckUser);
    $stmtCheckUser->execute([':user_id' => $user_id]);
    $existingUser = $stmtCheckUser->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingUser) {
        throw new Exception('User tidak ditemukan');
    }

    $fileName = $user_id . '.' . $fileExt;
    $filePath = $uploadDir . $fileName;

    error_log("Attempting to save file to: " . $filePath);

    $oldFiles = glob($uploadDir . $user_id . '.*');
    foreach ($oldFiles as $oldFile) {
        if (file_exists($oldFile) && $oldFile !== $filePath) {
            unlink($oldFile);
            error_log("Old file deleted: " . $oldFile);
        }
    }

    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        $error = error_get_last();
        error_log("Failed to move uploaded file. Error: " . print_r($error, true));
        throw new Exception('Gagal mengupload file. Pastikan folder memiliki permission yang benar.');
    }

    error_log("File uploaded successfully to: " . $filePath);

    if (!file_exists($filePath)) {
        throw new Exception('File berhasil diupload tapi tidak ditemukan');
    }

    $updated_at = date('Y-m-d H:i:s');

    $pdo->beginTransaction();

    try {
        $sqlUpdateUser = "UPDATE tUser 
                          SET profile_picture = :profile_picture,
                              updated_at = :updated_at
                          WHERE id = :id";

        $stmtUpdateUser = $pdo->prepare($sqlUpdateUser);
        $stmtUpdateUser->execute([
            ':profile_picture' => $fileName,
            ':updated_at' => $updated_at,
            ':id' => $user_id
        ]);

        $pdo->commit();

        $sqlGetUser = "SELECT id, nama, username, email, tanggal_lahir, alamat, no_telp, role, profile_picture 
                       FROM tUser WHERE id = :user_id";
        $stmtGetUser = $pdo->prepare($sqlGetUser);
        $stmtGetUser->execute([':user_id' => $user_id]);
        $updatedUser = $stmtGetUser->fetch(PDO::FETCH_ASSOC);

        error_log("Database updated successfully");
        error_log("Upload complete: " . $fileName);

        echo json_encode([
            'success' => true,
            'message' => 'Foto profil berhasil diupload',
            'data' => [
                'user' => $updatedUser,
                'file_name' => $fileName,
                'file_path' => '/uploads/user_profile/' . $fileName,
                'full_path' => $filePath,
                'file_size' => round($file['size'] / 1024, 2) . ' KB'
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        if (file_exists($filePath)) {
            unlink($filePath);
            error_log("Rolled back: file deleted");
        }
        throw $e;
    }

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());

    if (isset($filePath) && file_exists($filePath)) {
        unlink($filePath);
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}