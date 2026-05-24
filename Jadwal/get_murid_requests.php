<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$muridId = $data['murid_id'] ?? null;
$jenis = $data['jenis'] ?? null;

if (!$muridId || !$jenis) {
    echo json_encode(['success' => false, 'message' => 'Murid ID dan jenis wajib diisi']);
    exit;
}

if (!in_array($jenis, ['pengganti', 'tambahan'])) {
    echo json_encode(['success' => false, 'message' => 'Jenis tidak valid']);
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
                r.alasan_selesai,
                r.created_at,
                -- murid
                m.id as murid_id,
                um.nama as nama_murid,
                um.no_telp as no_telp_murid,
                m.grade,
                -- guru
                g.id as guru_id,
                ug.nama as nama_guru,
                -- pembuat request
                ureq.id as request_dari_id,
                ureq.nama as nama_pembuat_request,
                ureq.role as role_pembuat_request
                
            FROM trequest_jadwal r
            INNER JOIN tmurid m ON m.id = r.murid_id
            INNER JOIN tuser um ON um.id = m.user_id
            LEFT JOIN tjadwal_les j ON j.id = r.jadwal_les_id
            LEFT JOIN tguru g ON g.id = j.guru_id
            LEFT JOIN tuser ug ON ug.id = g.user_id
            INNER JOIN tuser ureq ON ureq.id = r.request_dari
            WHERE r.murid_id = :murid_id 
              AND r.jenis = :jenis
              AND r.status = 'pending'
            ORDER BY r.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':murid_id' => $muridId,
        ':jenis' => $jenis
    ]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($requests)) {
        echo json_encode([
            'success' => true,
            'data' => [
                'murid' => null,
                'requests' => []
            ]
        ]);
        exit;
    }

    $muridInfo = [
        'murid_id' => $requests[0]['murid_id'],
        'nama'     => $requests[0]['nama_murid'],
        'no_telp'  => $requests[0]['no_telp_murid'],
        'grade'    => (int)$requests[0]['grade']
    ];

    $formattedRequests = [];
    foreach ($requests as $req) {
        $formatted = [
            'request_id'     => (int)$req['request_id'],
            'jenis'          => $req['jenis'],
            'status'         => $req['status'],
            'keterangan'     => $req['keterangan'],
            'alasan_selesai' => $req['alasan_selesai'],
            'created_at'     => $req['created_at'],
            
            'pembuat_request' => [
                'user_id' => $req['request_dari_id'],
                'nama'    => $req['nama_pembuat_request'],
                'role'    => $req['role_pembuat_request']
            ],
            
            'guru' => [
                'guru_id' => $req['guru_id'],
                'nama'    => $req['nama_guru']
            ],
            
            'jadwal_baru' => [
                'tanggal'     => $req['tanggal_baru'],
                'jam_mulai'   => substr($req['jam_mulai_baru'], 0, 5),
                'jam_selesai' => substr($req['jam_selesai_baru'], 0, 5)
            ]
        ];

        if ($req['jenis'] === 'pengganti' && $req['jadwal_les_id']) {
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

            $stmtOrig = $pdo->prepare($queryOriginal);
            $stmtOrig->execute([
                ':jadwal_les_id' => $req['jadwal_les_id'],
                ':tanggal_baru'  => $req['tanggal_baru']
            ]);
            $original = $stmtOrig->fetch(PDO::FETCH_ASSOC);

            if ($original) {
                $formatted['presensi_asli'] = [
                    'presensi_id' => (int)$original['presensi_id'],
                    'tanggal'     => $original['tanggal_asli'],
                    'jam_mulai'   => $original['jam_mulai_asli'],
                    'jam_selesai' => $original['jam_selesai_asli'],
                    'status'      => $original['status_presensi'],
                    'jenis'       => $original['jenis_presensi']
                ];
            } else {
                $formatted['presensi_asli'] = null;
            }
        }

        $formattedRequests[] = $formatted;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'murid'    => $muridInfo,
            'requests' => $formattedRequests
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}