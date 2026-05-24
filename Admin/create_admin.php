<?php
require_once __DIR__ . '/../config/database.php';

$id = 'U0003';
$nama = 'Nassar';
$tanggal_lahir = '2004-03-27';
$username = 'admin1';
$password_plain = 'password';
$email = 'admin@example.com';
$alamat = 'Jl Rungkut Mejoyo Utara AN 49';
$no_telp = '089603678494';
$created_at = date('Y-m-d H:i:s');
$role = 'admin';

$password_hash = password_hash($password_plain, PASSWORD_DEFAULT);

$sql = "INSERT INTO tUser (id,nama,tanggal_lahir,username, password, email, alamat,no_telp, role,created_at)
        VALUES (:id,:nama, :tanggal_lahir, :username, :password,:email,:alamat,:no_telp, :role,:created_at)";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':id' => $id,
    ':nama' => $nama,
    ':tanggal_lahir' => $tanggal_lahir,
    ':username' => $username,
    ':password' => $password_hash,
    ':email' => $email,
    ':alamat'=> $alamat,
    ':no_telp'=> $no_telp,
    ':role' => $role,
    ':created_at'=>$created_at
]);

echo "Admin berhasil dibuat - Username: {$username}\n";