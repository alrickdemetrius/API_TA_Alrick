<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

function CekTerlambat(PDO $pdo): array
{
    $now = date('Y-m-d H:i:s');

    $sqlFind = "
        SELECT
            t.id          AS tugas_id,
            t.judul,
            t.deadline,
            ug.id         AS guru_user_id,
            ug.nama       AS nama_guru,
            um.id         AS murid_user_id,
            um.nama       AS nama_murid
        FROM ttugas t
        INNER JOIN tjadwal_les j  ON j.id  = t.jadwal_id
        INNER JOIN tguru g        ON g.id  = j.guru_id
        INNER JOIN tuser ug       ON ug.id = g.user_id
        INNER JOIN tmurid m       ON m.id  = j.murid_id
        INNER JOIN tuser um       ON um.id = m.user_id
        WHERE t.status    = 'belum_dikerjakan'
          AND t.deadline  < :now
    ";

    $stmtFind = $pdo->prepare($sqlFind);
    $stmtFind->execute([':now' => $now]);
    $overdueTasks = $stmtFind->fetchAll(PDO::FETCH_ASSOC);

    if (empty($overdueTasks)) {
        return ['updated' => 0, 'tasks' => []];
    }

    // Update status ke 'terlambat' dan kirim notifikasi
    $sqlUpdate = "
        UPDATE ttugas
        SET    status     = 'terlambat',
               updated_at = :updated_at
        WHERE  id         = :tugas_id
    ";
    $stmtUpdate = $pdo->prepare($sqlUpdate);

    $updatedTasks = [];

    foreach ($overdueTasks as $task) {
        $tugasId = (int) $task['tugas_id'];

        $stmtUpdate->execute([
            ':tugas_id'   => $tugasId,
            ':updated_at' => $now,
        ]);

        // Notifikasi ke guru dan murid
        createNotifikasi($pdo, [
            'jenis'          => 'lain',
            'reference_type' => 'tugas',
            'reference_id'   => $tugasId,
            'role_pengirim'  => 'sistem',
            'user_pengirim_id' => null,
            'context'        => [
                'judul'         => "Deadline Terlewat - {$task['nama_murid']}",
                'pesan'         => "Tugas '{$task['judul']}' untuk {$task['nama_murid']} telah melewati deadline ({$task['deadline']}) dan belum dikumpulkan. Status diubah menjadi 'Terlambat'.",
                'recipients'    => [
                    $task['guru_user_id'],
                    $task['murid_user_id'],
                ],
                'guru_user_id'  => $task['guru_user_id'],
                'murid_user_id' => $task['murid_user_id'],
            ],
        ]);

        $updatedTasks[] = [
            'tugas_id'   => $tugasId,
            'judul'      => $task['judul'],
            'deadline'   => $task['deadline'],
            'nama_murid' => $task['nama_murid'],
            'nama_guru'  => $task['nama_guru'],
            'status'     => 'terlambat',
        ];
    }

    return [
        'updated' => count($updatedTasks),
        'tasks'   => $updatedTasks,
    ];
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    try {
        $pdo->beginTransaction();
        $result = CekTerlambat($pdo);
        $pdo->commit();

        $now = date('Y-m-d H:i:s');

        if ($result['updated'] === 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Tidak ada tugas yang terlambat',
                'data'    => [
                    'total_overdue'      => 0,
                    'notifications_sent' => 0,
                    'tasks'              => [],
                    'checked_at'         => $now,
                ],
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => $result['updated'] . ' tugas telah ditandai terlambat',
                'data'    => [
                    'total_overdue'      => $result['updated'],
                    'notifications_sent' => $result['updated'],
                    'tasks'              => $result['tasks'],
                    'checked_at'         => $now,
                ],
            ]);
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("DB error terlambat_tugas: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage(),
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error terlambat_tugas: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}