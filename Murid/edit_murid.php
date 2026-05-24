<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$requiredFields = ['murid_id', 'user_id', 'nama', 'tanggal_lahir', 'username', 'alamat', 'no_telp', 'grade'];
foreach ($requiredFields as $field) {
    if (!isset($data[$field]) || empty($data[$field])) {
        echo json_encode([
            'success' => false,
            'message' => 'Parameter ' . $field . ' wajib diisi'
        ]);
        exit;
    }
}

$muridId = $data['murid_id'];
$userId = $data['user_id'];
$nama = $data['nama'];
$tanggalLahir = $data['tanggal_lahir'];
$username = $data['username'];
$alamat = $data['alamat'];
$noTelp = $data['no_telp'];
$grade = (int)$data['grade'];
$email = $data['email'] ?? null;
$nik = $data['nik'] ?? null;

if (!empty($nik) && !preg_match('/^\d{16}$/', $nik)) {
    echo json_encode(['success' => false, 'message' => 'NIK harus terdiri dari 16 digit angka']);
    exit;
}

if ($grade < 1 || $grade > 8) {
    echo json_encode([
        'success' => false,
        'message' => 'Grade harus antara 1 sampai 8'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $checkMurid = "SELECT m.id, m.user_id FROM tmurid m WHERE m.id = :murid_id";
    $stmtCheck = $pdo->prepare($checkMurid);
    $stmtCheck->bindParam(':murid_id', $muridId, PDO::PARAM_STR);
    $stmtCheck->execute();
    $existingMurid = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$existingMurid) {
        throw new Exception('Data murid tidak ditemukan');
    }

    if ($existingMurid['user_id'] !== $userId) {
        throw new Exception('User ID tidak sesuai dengan murid ID');
    }

    $checkUsername = "SELECT id FROM tuser WHERE username = :username AND id != :user_id";
    $stmtUsername = $pdo->prepare($checkUsername);
    $stmtUsername->bindParam(':username', $username, PDO::PARAM_STR);
    $stmtUsername->bindParam(':user_id', $userId, PDO::PARAM_STR);
    $stmtUsername->execute();
    
    if ($stmtUsername->rowCount() > 0) {
        throw new Exception('Username sudah digunakan oleh user lain');
    }

    $updatedAt = date('Y-m-d H:i:s');

    $updateUser = "UPDATE tuser 
                   SET nama = :nama,
                       tanggal_lahir = :tanggal_lahir,
                       username = :username,
                       alamat = :alamat,
                       no_telp = :no_telp,
                       email = :email,
                       nik = :nik,
                       updated_at = :updated_at
                   WHERE id = :user_id";

    $stmtUser = $pdo->prepare($updateUser);
    $stmtUser->bindParam(':nama', $nama, PDO::PARAM_STR);
    $stmtUser->bindParam(':tanggal_lahir', $tanggalLahir, PDO::PARAM_STR);
    $stmtUser->bindParam(':username', $username, PDO::PARAM_STR);
    $stmtUser->bindParam(':alamat', $alamat, PDO::PARAM_STR);
    $stmtUser->bindParam(':no_telp', $noTelp, PDO::PARAM_STR);
    $stmtUser->bindParam(':email', $email, PDO::PARAM_STR);
    $stmtUser->bindParam(':nik', $nik, PDO::PARAM_STR);
    $stmtUser->bindParam(':updated_at', $updatedAt, PDO::PARAM_STR);
    $stmtUser->bindParam(':user_id', $userId, PDO::PARAM_STR);
    $stmtUser->execute();

    $updateMurid = "UPDATE tmurid 
                    SET grade = :grade,
                        updated_at = :updated_at
                    WHERE id = :murid_id";

    $stmtMurid = $pdo->prepare($updateMurid);
    $stmtMurid->bindParam(':grade', $grade, PDO::PARAM_INT);
    $stmtMurid->bindParam(':updated_at', $updatedAt, PDO::PARAM_STR);
    $stmtMurid->bindParam(':murid_id', $muridId, PDO::PARAM_STR);
    $stmtMurid->execute();

    $pdo->commit();

    $getUpdated = "SELECT m.id as murid_id, m.grade, u.id as user_id, u.nik,
                        u.nama, u.tanggal_lahir, u.username, u.email,
                        u.alamat, u.no_telp, u.profile_picture as foto
                   FROM tmurid m INNER JOIN tuser u ON m.user_id = u.id
                   WHERE m.id = :murid_id";
    
    $stmtGet = $pdo->prepare($getUpdated);
    $stmtGet->bindParam(':murid_id', $muridId, PDO::PARAM_STR);
    $stmtGet->execute();
    $updatedData = $stmtGet->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Data murid berhasil diupdate',
        'data' => $updatedData
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Database error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}