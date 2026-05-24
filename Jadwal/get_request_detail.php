<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$requestId = $data['request_id'] ?? null;

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'Request ID wajib diisi']);
    exit;
}

try {
    $query = "SELECT 
                r.id as request_id,
                r.jenis,
                r.tanggal as tanggal_baru,
                r.jam_mulai as jam_mulai_baru,
                r.jam_selesai as jam_selesai_baru,
                r.status,
                r.jadwal_les_id,
                r.keterangan,
                r.created_at,
                -- murid
                m.id as murid_id,
                um.nama as nama_murid,
                um.no_telp as no_telp_murid,
                m.grade,
                g.id as guru_id,
                ug.nama as nama_guru,
                -- pembuat request
                ureq.nama as nama_pembuat_request,
                ureq.role as role_pembuat_request
                
            FROM trequest_jadwal r
            INNER JOIN tmurid m ON m.id = r.murid_id
            INNER JOIN tuser um ON um.id = m.user_id
            LEFT JOIN tjadwal_les j ON j.id = r.jadwal_les_id
            LEFT JOIN tguru g ON g.id = j.guru_id
            LEFT JOIN tuser ug ON ug.id = g.user_id
            INNER JOIN tuser ureq ON ureq.id = r.request_dari
            WHERE r.id = :request_id";

    $stmt = $pdo->prepare($query);
    $stmt->execute([':request_id' => $requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Request tidak ditemukan']);
        exit;
    }

    $response = [
        'request_id'     => (int)$request['request_id'],
        'jenis'          => $request['jenis'],
        'status'         => $request['status'],
        'keterangan'     => $request['keterangan'],
        'created_at'     => $request['created_at'],
        
        'pembuat_request' => [
            'nama' => $request['nama_pembuat_request'],
            'role' => $request['role_pembuat_request']
        ],
        
        'murid' => [
            'murid_id' => $request['murid_id'],
            'nama'     => $request['nama_murid'],
            'no_telp'  => $request['no_telp_murid'],
            'grade'    => (int)$request['grade']
        ],
        
        'guru' => [
            'guru_id' => $request['guru_id'],
            'nama'    => $request['nama_guru']
        ],
        
        'jadwal_baru' => [
            'tanggal'     => $request['tanggal_baru'],
            'jam_mulai'   => substr($request['jam_mulai_baru'], 0, 5),  // HH:MM
            'jam_selesai' => substr($request['jam_selesai_baru'], 0, 5)
        ]
    ];

    if ($request['jenis'] === 'pengganti' && $request['jadwal_les_id']) {
        $queryOriginal = "SELECT 
                            p.id as presensi_id,
                            p.tanggal as tanggal_asli,
                            DATE_FORMAT(s.jam_mulai, '%H:%i') as jam_mulai_asli,
                            DATE_FORMAT(s.jam_selesai, '%H:%i') as jam_selesai_asli,
                            p.status as status_presensi,
                            p.jenis as jenis_presensi
                        FROM tpresensi_les p
                        INNER JOIN tjadwal_les j ON j.id = p.jadwal_id
                        INNER JOIN tslot_jadwal s ON s.id = j.jadwal_slot_id
                        WHERE p.jadwal_id = :jadwal_les_id
                          AND p.status = 'aktif'
                        ORDER BY ABS(DATEDIFF(p.tanggal, :tanggal_baru))
                        LIMIT 1";

        $stmtOriginal = $pdo->prepare($queryOriginal);
        $stmtOriginal->execute([
            ':jadwal_les_id' => $request['jadwal_les_id'],
            ':tanggal_baru'  => $request['tanggal_baru']
        ]);
        $original = $stmtOriginal->fetch(PDO::FETCH_ASSOC);

        if ($original) {
            $response['presensi_asli'] = [
                'presensi_id' => (int)$original['presensi_id'],
                'tanggal'     => $original['tanggal_asli'],
                'jam_mulai'   => $original['jam_mulai_asli'],
                'jam_selesai' => $original['jam_selesai_asli'],
                'status'      => $original['status_presensi'],
                'jenis'       => $original['jenis_presensi']
            ];
        } else {
            $response['presensi_asli'] = null;
        }
    }

    echo json_encode([
        'success' => true,
        'data'    => $response
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}