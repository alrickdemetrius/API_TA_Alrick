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

    if (
        !isset($data['role']) || !isset($data['nama']) || !isset($data['tanggal_lahir']) ||
        !isset($data['no_telp']) || !isset($data['alamat'])
    ) {
        echo json_encode([
            'success' => false,
            'message' => 'Data tidak lengkap. Role, nama, tanggal lahir, no telepon, dan alamat wajib diisi.'
        ]);
        exit;
    }

    $role = strtolower($data['role']);
    $nama = $data['nama'];
    $tanggal_lahir = $data['tanggal_lahir'];
    $no_telp = $data['no_telp'];
    $alamat = $data['alamat'];
    $email = isset($data['email']) && !empty($data['email']) ? $data['email'] : null;
    $nik = isset($data['nik']) && !empty($data['nik']) ? $data['nik'] : null;

    if (!empty($nik) && !preg_match('/^\d{16}$/', $nik)) {
        echo json_encode(['success' => false, 'message' => 'NIK harus terdiri dari 16 digit angka']);
        exit;
    }

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

        $min_grade = (int) $data['min_grade'];
        $max_grade = (int) $data['max_grade'];

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

    $sqlGetLastUserId = "SELECT id FROM tUser WHERE id LIKE 'U%' ORDER BY id DESC LIMIT 1";
    $stmtLastUserId = $pdo->query($sqlGetLastUserId);
    $lastUserId = $stmtLastUserId->fetchColumn();

    if ($lastUserId) {
        $lastNumber = (int) substr($lastUserId, 1);
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }
    $userId = 'U' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);

    $username = strtolower(str_replace(' ', '', $nama));

    $sqlCheckUsername = "SELECT COUNT(*) FROM tUser WHERE username = :username";
    $stmtCheckUsername = $pdo->prepare($sqlCheckUsername);
    $stmtCheckUsername->execute([':username' => $username]);
    $usernameExists = $stmtCheckUsername->fetchColumn();

    if ($usernameExists > 0) {
        $counter = 1;
        $originalUsername = $username;
        while ($usernameExists > 0) {
            $username = $originalUsername . $counter;
            $stmtCheckUsername->execute([':username' => $username]);
            $usernameExists = $stmtCheckUsername->fetchColumn();
            $counter++;
        }
    }

    $password_hash = password_hash('piano123', PASSWORD_DEFAULT);

    $created_at = date('Y-m-d H:i:s');

    $pdo->beginTransaction();

    try {
        $sqlUser = "INSERT INTO tUser (id, nik, nama, tanggal_lahir, username, password, email, alamat, no_telp, role, created_at)
                    VALUES (:id, :nik, :nama, :tanggal_lahir, :username, :password, :email, :alamat, :no_telp, :role, :created_at)";

        $stmtUser = $pdo->prepare($sqlUser);
        $stmtUser->execute([
            ':id' => $userId,
            ':nik' => $nik,
            ':nama' => $nama,
            ':tanggal_lahir' => $tanggal_lahir,
            ':username' => $username,
            ':password' => $password_hash,
            ':email' => $email,
            ':alamat' => $alamat,
            ':no_telp' => $no_telp,
            ':role' => $role,
            ':created_at' => $created_at
        ]);

        if ($role === 'guru') {
            $sqlGetLastGuruId = "SELECT id FROM tGuru WHERE id LIKE 'G%' ORDER BY id DESC LIMIT 1";
            $stmtLastGuruId = $pdo->query($sqlGetLastGuruId);
            $lastGuruId = $stmtLastGuruId->fetchColumn();

            if ($lastGuruId) {
                $lastGuruNumber = (int) substr($lastGuruId, 1);
                $newGuruNumber = $lastGuruNumber + 1;
            } else {
                $newGuruNumber = 1;
            }
            $guruId = 'G' . str_pad($newGuruNumber, 4, '0', STR_PAD_LEFT);

            $sqlGuru = "INSERT INTO tGuru (id, min_grade, max_grade, user_id, created_at)
                        VALUES (:id, :min_grade, :max_grade, :user_id, :created_at)";

            $stmtGuru = $pdo->prepare($sqlGuru);
            $stmtGuru->execute([
                ':id' => $guruId,
                ':min_grade' => $min_grade,
                ':max_grade' => $max_grade,
                ':user_id' => $userId,
                ':created_at' => $created_at
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Karyawan berhasil ditambahkan',
            'data' => [
                'user_id' => $userId,
                'username' => $username,
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