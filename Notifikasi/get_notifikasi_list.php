<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);

$userId = $data['user_id'] ?? null;

if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'User ID wajib diisi']);
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
            WHERE np.user_id = :user_id
            ORDER BY n.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute([':user_id' => $userId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = [];
    $unreadCount = 0;
    
    foreach ($results as $row) {
        $isRead = (bool)$row['is_read'];
        if (!$isRead) {
            $unreadCount++;
        }
        
        $notifications[] = [
            'id'             => (int)$row['id'],
            'judul'          => $row['judul'],
            'pesan'          => $row['pesan'],
            'jenis'          => $row['jenis'],
            'reference_type' => $row['reference_type'],
            'reference_id'   => $row['reference_id'],
            'is_read'        => $isRead,
            'read_at'        => $row['read_at'],
            'created_at'     => $row['created_at'],
            'pengirim'       => [
                'role'    => $row['role_pengirim'],
                'user_id' => $row['user_pengirim_id'],
                'nama'    => $row['nama_pengirim']
            ]
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $notifications,
        'total' => count($notifications),
        'unread_count' => $unreadCount
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}