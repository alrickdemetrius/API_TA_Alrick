<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$notifikasiId = $data['notifikasi_id'] ?? null;
$userId = $data['user_id'] ?? null;

if (!$notifikasiId || !$userId) {
    echo json_encode([
        'success' => false,
        'message' => 'Notifikasi ID dan User ID wajib diisi'
    ]);
    exit;
}

try {
    $query = "SELECT 
                n.id,
                n.judul,
                n.pesan,
                n.jenis,
                n.reference_type,
                n.reference_id,
                n.role_pengirim,
                n.user_pengirim_id,
                n.created_at,
                np.is_read,
                np.read_at,
                u.nama as nama_pengirim
            FROM tnotifikasi n
            INNER JOIN tnotifikasi_penerima np ON np.notifikasi_id = n.id
            LEFT JOIN tuser u ON u.id = n.user_pengirim_id
            WHERE n.id = :notifikasi_id 
              AND np.user_id = :user_id";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':notifikasi_id' => $notifikasiId,
        ':user_id' => $userId
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        echo json_encode([
            'success' => false,
            'message' => 'Notifikasi tidak ditemukan'
        ]);
        exit;
    }

    $notification = [
        'id'             => (int)$result['id'],
        'judul'          => $result['judul'],
        'pesan'          => $result['pesan'],
        'jenis'          => $result['jenis'],
        'reference_type' => $result['reference_type'],
        'reference_id'   => $result['reference_id'],
        'is_read'        => (bool)$result['is_read'],
        'read_at'        => $result['read_at'],
        'created_at'     => $result['created_at'],
        'pengirim'       => [
            'role'    => $result['role_pengirim'],
            'user_id' => $result['user_pengirim_id'],
            'nama'    => $result['nama_pengirim']
        ]
    ];

    if (in_array($result['jenis'], ['request_tambahan', 'request_pengganti'])
        && !empty($result['reference_id'])) {
        $stmtReq = $pdo->prepare("
            SELECT status FROM trequest_jadwal WHERE id = ?
        ");
        $stmtReq->execute([$result['reference_id']]);
        $reqRow = $stmtReq->fetch(PDO::FETCH_ASSOC);
        $notification['request_status'] = $reqRow ? $reqRow['status'] : null;
    } else {
        $notification['request_status'] = null;
    }

    echo json_encode([
        'success' => true,
        'data' => $notification
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}