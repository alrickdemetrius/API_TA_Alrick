<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$idPresensi = $data["id"];

if (!$idPresensi) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap']);
    exit;
}

try {
    $query = "SELECT p.id, 
                    p.jadwal_id,
                    p.jenis,
                    p.status,
                    m.id as murid_id,
                    ug.nama as nama_guru, 
                    um.nama as nama_murid,
                    DATE_FORMAT(s.jam_mulai, '%H:%i') as jam_mulai,
                    DATE_FORMAT(s.jam_selesai, '%H:%i') as jam_selesai,
                    DATE_FORMAT(p.tanggal, '%Y-%m-%d') as tanggal
                FROM tpresensi_les p 
                INNER JOIN tjadwal_les j ON j.id = p.jadwal_id
                INNER JOIN tslot_jadwal s ON s.id = j.jadwal_slot_id
                INNER JOIN tmurid m ON m.id = j.murid_id
                INNER JOIN tuser um ON m.user_id = um.id
                INNER JOIN tguru g ON g.id = j.guru_id
                INNER JOIN tuser ug ON ug.id = g.user_id
                WHERE p.id = :id";

    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':id', $idPresensi, PDO::PARAM_INT);
    $stmt->execute();
    $kelas = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($kelas) {
        echo json_encode(['success' => true, 'data' => $kelas]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}