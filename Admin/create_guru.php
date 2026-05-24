<?php
require_once __DIR__ . '/../config/database.php';

$dataGuru = [
    [
        'id' => 'U0001',
        'nik' => '1012123004445001',
        'nama' => 'Nico Ludwig',
        'tanggal_lahir' => '2004-03-27',
        'username' => 'guru1',
        'email' => 'nicoludwig@example.com',
        'alamat' => 'Jl Rungkut Mejoyo Utara AN 49',
        'no_telp' => '089603678494',
        'id_guru' => 'G0001',
        'min_grade' => '1',
        'max_grade' => '3'
    ],
    [
        'id' => 'U0004',
        'nik' => '3201145506880002',
        'nama' => 'Siti Rahayu',
        'tanggal_lahir' => '1988-06-15',
        'username' => 'guru2',
        'email' => 'sitirahayu@example.com',
        'alamat' => 'Jl. Ngagel Jaya Selatan No. 12 Surabaya',
        'no_telp' => '081234567890',
        'id_guru' => 'G0002',
        'min_grade' => '4',
        'max_grade' => '6'
    ],
    [
        'id' => 'U0005',
        'nik' => '3578112209920001',
        'nama' => 'Budi Santoso',
        'tanggal_lahir' => '1992-09-22',
        'username' => 'guru3',
        'email' => 'budisantoso@example.com',
        'alamat' => 'Jl. Dharma Husada Indah Timur III No. 8 Surabaya',
        'no_telp' => '085678901234',
        'id_guru' => 'G0003',
        'min_grade' => '7',
        'max_grade' => '8'
    ]
];

$password_plain = 'password';
$role = 'guru';

$sqlUser = "INSERT INTO tUser (id, nik, nama, tanggal_lahir, username, password, email, alamat, no_telp, role, created_at)
            VALUES (:id, :nik, :nama, :tanggal_lahir, :username, :password, :email, :alamat, :no_telp, :role, :created_at)";
$stmtUser = $pdo->prepare($sqlUser);

$sqlGuru = "INSERT INTO tGuru (id, min_grade, max_grade, user_id, created_at)
            VALUES (:id_guru, :min_grade, :max_grade, :user_id, :created_at)";
$stmtGuru = $pdo->prepare($sqlGuru);

foreach ($dataGuru as $guru) {
    try {
        $password_hash = password_hash($password_plain, PASSWORD_DEFAULT);
        $created_at = date('Y-m-d H:i:s');

        $stmtUser->execute([
            ':id' => $guru['id'],
            ':nik' => $guru['nik'],
            ':nama' => $guru['nama'],
            ':tanggal_lahir' => $guru['tanggal_lahir'],
            ':username' => $guru['username'],
            ':password' => $password_hash,
            ':email' => $guru['email'],
            ':alamat' => $guru['alamat'],
            ':no_telp' => $guru['no_telp'],
            ':role' => $role,
            ':created_at' => $created_at
        ]);

        $stmtGuru->execute([
            ':id_guru' => $guru['id_guru'],
            ':min_grade' => $guru['min_grade'],
            ':max_grade' => $guru['max_grade'],
            ':user_id' => $guru['id'],
            ':created_at' => $created_at
        ]);
        
        echo "✓ Guru {$guru['nama']} ({$guru['id_guru']}) - Username: {$guru['username']} berhasil dibuat\n";
        
    } catch (PDOException $e) {
        echo "✗ Error membuat guru {$guru['nama']}: " . $e->getMessage() . "\n";
    }
}

echo "\n=== SEMUA GURU BERHASIL DIBUAT ===\n";