<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$muridId = $data["murid_id"];

if (!$muridId) {
    echo json_encode([
        'success' => false,
        'message' => 'Parameter tidak lengkap'
    ]);
    exit;
}

try {
    $query = "SELECT 
                m.id as murid_id,
                m.grade,
                u.id as user_id,
                u.nik,
                u.nama,
                u.tanggal_lahir,
                u.username,
                u.email,
                u.alamat,
                u.no_telp,
                u.profile_picture as foto,
                j.id as jadwal_id,
                j.guru_id,
                j.jadwal_slot_id,
                ug.nama as nama_guru,
                s.hari,
                DATE_FORMAT(s.jam_mulai, '%H:%i') as jam_mulai,
                DATE_FORMAT(s.jam_selesai, '%H:%i') as jam_selesai
            FROM tmurid m
                INNER JOIN tuser u ON m.user_id = u.id
                LEFT JOIN tjadwal_les j ON j.murid_id = m.id AND j.status_aktif = 1
                LEFT JOIN tguru g ON g.id = j.guru_id
                LEFT JOIN tuser ug ON ug.id = g.user_id
                LEFT JOIN tslot_jadwal s ON s.id = j.jadwal_slot_id
            WHERE m.id = :murid_id";

    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':murid_id', $muridId, PDO::PARAM_STR);
    $stmt->execute();
    $murid = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($murid) {
        $response = [
            'murid_id' => $murid['murid_id'],
            'user_id' => $murid['user_id'],
            'nik' => $murid['nik'],
            'nama' => $murid['nama'],
            'tanggal_lahir' => $murid['tanggal_lahir'],
            'username' => $murid['username'],
            'email' => $murid['email'],
            'alamat' => $murid['alamat'],
            'no_telp' => $murid['no_telp'],
            'grade' => (int)$murid['grade'],
            'foto' => $murid['foto'],
            'jadwal' => null
        ];

        if ($murid['jadwal_id']) {
            $response['jadwal'] = [
                'jadwal_id' => (int)$murid['jadwal_id'],
                'guru_id' => $murid['guru_id'],
                'nama_guru' => $murid['nama_guru'],
                'jadwal_slot_id' => (int)$murid['jadwal_slot_id'],
                'hari' => $murid['hari'],
                'jam_mulai' => $murid['jam_mulai'],
                'jam_selesai' => $murid['jam_selesai']
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $response
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Data murid tidak ditemukan'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}