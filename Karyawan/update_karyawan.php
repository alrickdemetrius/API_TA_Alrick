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

    if (!isset($data['id']) || !isset($data['role']) || !isset($data['nama']) || 
        !isset($data['tanggal_lahir']) || !isset($data['no_telp']) || !isset($data['alamat'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Data tidak lengkap. ID, Role, nama, tanggal lahir, no telepon, dan alamat wajib diisi.'
        ]);
        exit;
    }
    
    $userId = $data['id'];
    $role = strtolower($data['role']);
    $nama = $data['nama'];
    $tanggal_lahir = $data['tanggal_lahir'];
    $no_telp = $data['no_telp'];
    $alamat = $data['alamat'];
    $email = isset($data['email']) && !empty($data['email']) ? $data['email'] : null;
    $nik = isset($data['nik']) && !empty($data['nik']) ? $data['nik'] : null;

    if ($role !== 'guru' && $role !== 'admin') {
        echo json_encode([
            'success' => false,
            'message' => 'Role tidak valid. Harus "guru" atau "admin".'
        ]);
        exit;
    }

    if ($role === 'guru') {
        if (!isset($data['min_grade']) || !isset($data['max_grade'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Min Grade dan Max Grade wajib diisi untuk guru.'
            ]);
            exit;
        }
        
        $min_grade = (int)$data['min_grade'];
        $max_grade = (int)$data['max_grade'];
        
        if ($min_grade < 1 || $min_grade > 8 || $max_grade < 1 || $max_grade > 8) {
            echo json_encode([
                'success' => false,
                'message' => 'Min Grade dan Max Grade harus antara 1-8.'
            ]);
            exit;
        }
        
        if ($max_grade < $min_grade) {
            echo json_encode([
                'success' => false,
                'message' => 'Max Grade tidak boleh lebih kecil dari Min Grade.'
            ]);
            exit;
        }
    }

    $sqlCheckUser = "SELECT role FROM tUser WHERE id = :user_id";
    $stmtCheckUser = $pdo->prepare($sqlCheckUser);
    $stmtCheckUser->execute([':user_id' => $userId]);
    $existingUser = $stmtCheckUser->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingUser) {
        echo json_encode([
            'success' => false,
            'message' => 'Data karyawan tidak ditemukan'
        ]);
        exit;
    }
    
    $oldRole = $existingUser['role'];
    $updated_at = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    
    try {
        $sqlUser = "UPDATE tUser 
                    SET nik = :nik,
                        nama = :nama,
                        tanggal_lahir = :tanggal_lahir,
                        email = :email,
                        alamat = :alamat,
                        no_telp = :no_telp,
                        role = :role,
                        updated_at = :updated_at
                    WHERE id = :id";
        
        $stmtUser = $pdo->prepare($sqlUser);
        $stmtUser->execute([
            ':id' => $userId,
            ':nik' => $nik,
            ':nama' => $nama,
            ':tanggal_lahir' => $tanggal_lahir,
            ':email' => $email,
            ':alamat' => $alamat,
            ':no_telp' => $no_telp,
            ':role' => $role,
            ':updated_at' => $updated_at
        ]);

        if ($role === 'guru') {
            $sqlCheckGuru = "SELECT id FROM tGuru WHERE user_id = :user_id";
            $stmtCheckGuru = $pdo->prepare($sqlCheckGuru);
            $stmtCheckGuru->execute([':user_id' => $userId]);
            $existingGuru = $stmtCheckGuru->fetch(PDO::FETCH_ASSOC);
            
            if ($existingGuru) {
                $sqlUpdateGuru = "UPDATE tGuru 
                                  SET min_grade = :min_grade,
                                      max_grade = :max_grade,
                                      updated_at = :updated_at
                                  WHERE user_id = :user_id";
                
                $stmtUpdateGuru = $pdo->prepare($sqlUpdateGuru);
                $stmtUpdateGuru->execute([
                    ':min_grade' => $min_grade,
                    ':max_grade' => $max_grade,
                    ':user_id' => $userId,
                    ':updated_at' => $updated_at
                ]);
            } else {
                $sqlGetLastGuruId = "SELECT id FROM tGuru WHERE id LIKE 'G%' ORDER BY id DESC LIMIT 1";
                $stmtLastGuruId = $pdo->query($sqlGetLastGuruId);
                $lastGuruId = $stmtLastGuruId->fetchColumn();
                
                if ($lastGuruId) {
                    $lastGuruNumber = (int)substr($lastGuruId, 1);
                    $newGuruNumber = $lastGuruNumber + 1;
                } else {
                    $newGuruNumber = 1;
                }
                $guruId = 'G' . str_pad($newGuruNumber, 4, '0', STR_PAD_LEFT);
                
                $sqlInsertGuru = "INSERT INTO tGuru (id, min_grade, max_grade, user_id, created_at)
                                  VALUES (:id, :min_grade, :max_grade, :user_id, :created_at)";
                
                $stmtInsertGuru = $pdo->prepare($sqlInsertGuru);
                $stmtInsertGuru->execute([
                    ':id' => $guruId,
                    ':min_grade' => $min_grade,
                    ':max_grade' => $max_grade,
                    ':user_id' => $userId,
                    ':created_at' => $updated_at
                ]);
            }
        } elseif ($oldRole === 'guru' && $role === 'admin') {
            $sqlDeleteGuru = "DELETE FROM tGuru WHERE user_id = :user_id";
            $stmtDeleteGuru = $pdo->prepare($sqlDeleteGuru);
            $stmtDeleteGuru->execute([':user_id' => $userId]);
        }

        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Data karyawan berhasil diupdate',
            'data' => [
                'user_id' => $userId,
                'role' => $role
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
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
