<?php
// CORS Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Origin');
header('Access-Control-Max-Age: 86400');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

$uploadDirLocalFile = __DIR__ . '/../uploads/materi/local_file/';
$uploadDirSheet = __DIR__ . '/../uploads/materi/sheet/';

$allowedExtensionsLocalFile = ['mp4', 'mov', 'avi', 'mkv', 'mp3', 'wav', 'm4a'];
$allowedExtensionsSheet = ['jpg', 'jpeg', 'png', 'pdf'];
$maxFileSize = 50 * 1024 * 1024;

if (!is_dir($uploadDirLocalFile)) {
    mkdir($uploadDirLocalFile, 0777, true);
}
if (!is_dir($uploadDirSheet)) {
    mkdir($uploadDirSheet, 0777, true);
}

try {
    error_log("=== UPLOAD MATERI FILES DEBUG ===");
    error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
    error_log("POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));

    $errors = [];

    if (!isset($_POST['materi_id']) || empty($_POST['materi_id'])) {
        $errors[] = 'materi_id tidak ditemukan atau kosong';
    }

    if (!empty($errors)) {
        echo json_encode([
            'success' => false,
            'message' => 'Parameter tidak lengkap: ' . implode(', ', $errors)
        ]);
        exit;
    }

    $materiId = intval($_POST['materi_id']);
    $uploadedFiles = [];

    $sqlCheck = "SELECT id FROM tmateri WHERE id = :materi_id";
    $stmtCheck = $pdo->prepare($sqlCheck);
    $stmtCheck->execute([':materi_id' => $materiId]);
    
    if (!$stmtCheck->fetch()) {
        throw new Exception('Materi tidak ditemukan');
    }

    $pdo->beginTransaction();

    if (isset($_FILES['local_file']) && $_FILES['local_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['local_file'];

        if ($file['size'] > $maxFileSize) {
            throw new Exception('Ukuran local_file terlalu besar. Maksimal 50MB');
        }

        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExt, $allowedExtensionsLocalFile)) {
            throw new Exception('Format local_file tidak didukung. Gunakan: ' . implode(', ', $allowedExtensionsLocalFile));
        }

        $fileName = 'local_file' . $materiId . '.' . $fileExt;
        $filePath = $uploadDirLocalFile . $fileName;

        $oldFiles = glob($uploadDirLocalFile . 'local_file' . $materiId . '.*');
        foreach ($oldFiles as $oldFile) {
            if (file_exists($oldFile)) {
                unlink($oldFile);
                error_log("Old local_file deleted: " . $oldFile);
            }
        }

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception('Gagal mengupload local_file');
        }

        $uploadedFiles['local_file'] = $fileName;
        error_log("local_file uploaded: " . $fileName);
    }

    if (isset($_FILES['sheet']) && $_FILES['sheet']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['sheet'];

        if ($file['size'] > $maxFileSize) {
            throw new Exception('Ukuran sheet terlalu besar. Maksimal 50MB');
        }

        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExt, $allowedExtensionsSheet)) {
            throw new Exception('Format sheet tidak didukung. Gunakan: ' . implode(', ', $allowedExtensionsSheet));
        }

        $fileName = 'sheet' . $materiId . '.' . $fileExt;
        $filePath = $uploadDirSheet . $fileName;

        $oldFiles = glob($uploadDirSheet . 'sheet' . $materiId . '.*');
        foreach ($oldFiles as $oldFile) {
            if (file_exists($oldFile)) {
                unlink($oldFile);
                error_log("Old sheet deleted: " . $oldFile);
            }
        }

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception('Gagal mengupload sheet');
        }

        $uploadedFiles['sheet'] = $fileName;
        error_log("sheet uploaded: " . $fileName);
    }

    if (!empty($uploadedFiles)) {
        $updates = [];
        $params = [':id' => $materiId];

        if (isset($uploadedFiles['local_file'])) {
            $updates[] = "local_file = :local_file";
            $params[':local_file'] = $uploadedFiles['local_file'];
        }

        if (isset($uploadedFiles['sheet'])) {
            $updates[] = "sheet = :sheet";
            $params[':sheet'] = $uploadedFiles['sheet'];
        }

        $updates[] = "updated_at = NOW()";

        $sqlUpdate = "UPDATE tmateri SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmtUpdate = $pdo->prepare($sqlUpdate);
        $stmtUpdate->execute($params);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'File berhasil diupload',
        'data' => [
            'materi_id' => $materiId,
            'uploaded_files' => $uploadedFiles
        ]
    ]);

} catch (PDOException $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }

    if (isset($uploadedFiles)) {
        foreach ($uploadedFiles as $type => $filename) {
            $dir = $type === 'local_file' ? $uploadDirLocalFile : $uploadDirSheet;
            $path = $dir . $filename;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
    
    error_log("Database error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (isset($uploadedFiles)) {
        foreach ($uploadedFiles as $type => $filename) {
            $dir = $type === 'local_file' ? $uploadDirLocalFile : $uploadDirSheet;
            $path = $dir . $filename;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
    
    error_log("Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}