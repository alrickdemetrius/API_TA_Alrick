<?php
require_once __DIR__ . '/../config/database.php';

$dataMurid = [
    [
        'id' => 'U0002',
        'nama' => 'Alrick Demetrius',
        'tanggal_lahir' => '2004-03-27',
        'username' => 'murid1',
        'email' => 'alrickdemetrius@example.com',
        'alamat' => 'Jl Rungkut Mejoyo Utara AN 49',
        'no_telp' => '089603678494',
        'id_murid' => 'M0001',
        'grade' => '2'
    ],
    [
        'id' => 'U0006',
        'nama' => 'Jasmine Putri',
        'tanggal_lahir' => '2010-05-12',
        'username' => 'murid2',
        'email' => 'jasmineputri@example.com',
        'alamat' => 'Jl. Kenjeran No. 45 Surabaya',
        'no_telp' => '081234567891',
        'id_murid' => 'M0002',
        'grade' => '1'
    ],
    [
        'id' => 'U0007',
        'nama' => 'Michael Anderson',
        'tanggal_lahir' => '2012-08-20',
        'username' => 'murid3',
        'email' => 'michaelanderson@example.com',
        'alamat' => 'Jl. Manyar Kertoarjo V No. 23 Surabaya',
        'no_telp' => '082345678902',
        'id_murid' => 'M0003',
        'grade' => '3'
    ],
    [
        'id' => 'U0008',
        'nama' => 'Clarissa Wijaya',
        'tanggal_lahir' => '2011-11-03',
        'username' => 'murid4',
        'email' => 'clarissawijaya@example.com',
        'alamat' => 'Jl. Gubeng Kertajaya VIII/12 Surabaya',
        'no_telp' => '083456789013',
        'id_murid' => 'M0004',
        'grade' => '4'
    ],
    [
        'id' => 'U0009',
        'nama' => 'Kevin Tanaka',
        'tanggal_lahir' => '2009-02-14',
        'username' => 'murid5',
        'email' => 'kevintanaka@example.com',
        'alamat' => 'Jl. Raya Darmo Permai Selatan No. 88 Surabaya',
        'no_telp' => '084567890124',
        'id_murid' => 'M0005',
        'grade' => '5'
    ],
    [
        'id' => 'U0010',
        'nama' => 'Angela Susanto',
        'tanggal_lahir' => '2010-07-25',
        'username' => 'murid6',
        'email' => 'angelasusanto@example.com',
        'alamat' => 'Jl. Nginden Semolo No. 67 Surabaya',
        'no_telp' => '085678901235',
        'id_murid' => 'M0006',
        'grade' => '6'
    ],
    [
        'id' => 'U0011',
        'nama' => 'Daniel Hartono',
        'tanggal_lahir' => '2008-09-18',
        'username' => 'murid7',
        'email' => 'danielhartono@example.com',
        'alamat' => 'Jl. Pucang Anom Timur No. 34 Surabaya',
        'no_telp' => '086789012346',
        'id_murid' => 'M0007',
        'grade' => '7'
    ],
    [
        'id' => 'U0012',
        'nama' => 'Stephanie Chen',
        'tanggal_lahir' => '2009-12-05',
        'username' => 'murid8',
        'email' => 'stephaniechen@example.com',
        'alamat' => 'Jl. Klampis Jaya No. 29A Surabaya',
        'no_telp' => '087890123457',
        'id_murid' => 'M0008',
        'grade' => '8'
    ],
    [
        'id' => 'U0013',
        'nama' => 'Ryan Prasetyo',
        'tanggal_lahir' => '2011-04-22',
        'username' => 'murid9',
        'email' => 'ryanprasetyo@example.com',
        'alamat' => 'Jl. Margorejo Indah Blok A-15 Surabaya',
        'no_telp' => '088901234568',
        'id_murid' => 'M0009',
        'grade' => '3'
    ],
    [
        'id' => 'U0014',
        'nama' => 'Isabella Gunawan',
        'tanggal_lahir' => '2010-01-30',
        'username' => 'murid10',
        'email' => 'isabellagunawan@example.com',
        'alamat' => 'Jl. Wisata Bukit Mas No. 52 Surabaya',
        'no_telp' => '089012345679',
        'id_murid' => 'M0010',
        'grade' => '5'
    ]
];

$password_plain = 'password';
$role = 'murid';

$sqlUser = "INSERT INTO tUser (id, nama, tanggal_lahir, username, password, email, alamat, no_telp, role, created_at)
            VALUES (:id, :nama, :tanggal_lahir, :username, :password, :email, :alamat, :no_telp, :role, :created_at)";
$stmtUser = $pdo->prepare($sqlUser);

$sqlMurid = "INSERT INTO tMurid (id, user_id, grade, created_at)
             VALUES (:id_murid, :user_id, :grade, :created_at)";
$stmtMurid = $pdo->prepare($sqlMurid);

foreach ($dataMurid as $murid) {
    try {
        $password_hash = password_hash($password_plain, PASSWORD_DEFAULT);
        $created_at = date('Y-m-d H:i:s');

        $stmtUser->execute([
            ':id' => $murid['id'],
            ':nama' => $murid['nama'],
            ':tanggal_lahir' => $murid['tanggal_lahir'],
            ':username' => $murid['username'],
            ':password' => $password_hash,
            ':email' => $murid['email'],
            ':alamat' => $murid['alamat'],
            ':no_telp' => $murid['no_telp'],
            ':role' => $role,
            ':created_at' => $created_at
        ]);

        $stmtMurid->execute([
            ':id_murid' => $murid['id_murid'],
            ':user_id' => $murid['id'],
            ':grade' => $murid['grade'],
            ':created_at' => $created_at
        ]);
        
        echo "✓ Murid {$murid['nama']} ({$murid['id_murid']}) Grade {$murid['grade']} - Username: {$murid['username']} berhasil dibuat\n";
        
    } catch (PDOException $e) {
        echo "✗ Error membuat murid {$murid['nama']}: " . $e->getMessage() . "\n";
    }
}

echo "\n=== SEMUA MURID BERHASIL DIBUAT ===\n";