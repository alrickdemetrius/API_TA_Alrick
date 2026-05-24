<?php
/**
 * reminder_cron.php
 *
 * Cron job untuk mengirim reminder kelas 1 jam sebelum jadwal.
 * Jalankan setiap menit via crontab:
 *   * * * * * php /path/to/API_TA_Alrick/reminder_cron.php >> /path/to/logs/reminder.log 2>&1
 *
 * Logic:
 *   1. Cari semua presensi aktif hari ini yang jam_mulainya 55-65 menit dari sekarang
 *      (window 10 menit untuk toleransi cron terlambat)
 *   2. Cek apakah sudah pernah kirim reminder untuk presensi ini hari ini
 *   3. Jika belum → buat notifikasi di DB + kirim Web Push ke murid dan guru
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/Notifikasi/notifikasi_helper.php';

$now        = new DateTime();
$windowStart = (clone $now)->modify('+55 minutes')->format('H:i:s');
$windowEnd   = (clone $now)->modify('+65 minutes')->format('H:i:s');
$today       = $now->format('Y-m-d');

echo "[" . $now->format('Y-m-d H:i:s') . "] Reminder cron started\n";
echo "  Window: $windowStart - $windowEnd\n";

try {
    // ── 1. Ambil semua presensi aktif hari ini dalam window 1 jam ─────────
    $stmtPresensi = $pdo->prepare("
        SELECT
            p.id               AS presensi_id,
            p.jenis            AS jenis_presensi,
            p.tanggal,
            s.jam_mulai,
            s.jam_selesai,
            -- Murid
            m.id               AS murid_id,
            um.id              AS murid_user_id,
            um.nama            AS nama_murid,
            -- Guru dari jadwal utama
            g.id               AS guru_id,
            ug.id              AS guru_user_id,
            ug.nama            AS nama_guru
        FROM tpresensi_les p
        INNER JOIN tjadwal_les j  ON j.id  = p.jadwal_id
        INNER JOIN tslot_jadwal s ON s.id  = j.jadwal_slot_id
        INNER JOIN tmurid m       ON m.id  = j.murid_id
        INNER JOIN tuser um       ON um.id = m.user_id
        INNER JOIN tguru g        ON g.id  = j.guru_id
        INNER JOIN tuser ug       ON ug.id = g.user_id
        WHERE p.tanggal    = :today
          AND p.status     = 'aktif'
          AND s.jam_mulai >= :window_start
          AND s.jam_mulai <= :window_end
    ");
    $stmtPresensi->execute([
        ':today'        => $today,
        ':window_start' => $windowStart,
        ':window_end'   => $windowEnd
    ]);
    $presensiList = $stmtPresensi->fetchAll(PDO::FETCH_ASSOC);

    // Tambahan: cari presensi tambahan/pengganti (jam dari trequest_jadwal)
    $stmtReqPresensi = $pdo->prepare("
        SELECT
            p.id               AS presensi_id,
            p.jenis            AS jenis_presensi,
            p.tanggal,
            rj.jam_mulai,
            rj.jam_selesai,
            m.id               AS murid_id,
            um.id              AS murid_user_id,
            um.nama            AS nama_murid,
            g.id               AS guru_id,
            ug.id              AS guru_user_id,
            ug.nama            AS nama_guru
        FROM tpresensi_les p
        INNER JOIN tjadwal_les j  ON j.id  = p.jadwal_id
        INNER JOIN tmurid m       ON m.id  = j.murid_id
        INNER JOIN tuser um       ON um.id = m.user_id
        INNER JOIN tguru g        ON g.id  = j.guru_id
        INNER JOIN tuser ug       ON ug.id = g.user_id
        INNER JOIN trequest_jadwal rj
               ON rj.murid_id  = m.id
              AND rj.tanggal   = p.tanggal
              AND rj.jenis     = p.jenis
              AND rj.status    = 'approved'
        WHERE p.tanggal    = :today
          AND p.status     = 'aktif'
          AND p.jenis     != 'rutin'
          AND rj.jam_mulai >= :window_start
          AND rj.jam_mulai <= :window_end
    ");
    $stmtReqPresensi->execute([
        ':today'        => $today,
        ':window_start' => $windowStart,
        ':window_end'   => $windowEnd
    ]);
    $reqPresensiList = $stmtReqPresensi->fetchAll(PDO::FETCH_ASSOC);

    // Gabungkan, deduplikasi berdasarkan presensi_id
    $allPresensi = [];
    foreach (array_merge($presensiList, $reqPresensiList) as $p) {
        $allPresensi[$p['presensi_id']] = $p;
    }

    echo "  Found " . count($allPresensi) . " upcoming presensi\n";

    if (empty($allPresensi)) {
        echo "  No reminders to send.\n";
        exit(0);
    }

    // ── 2. Cek mana yang sudah diremind hari ini ──────────────────────────
    $stmtCekReminder = $pdo->prepare("
        SELECT reference_id
        FROM tnotifikasi
        WHERE jenis          = 'reminder_kelas'
          AND reference_type = 'presensi'
          AND DATE(created_at) = :today
          AND reference_id   = :presensi_id
        LIMIT 1
    ");

    $sent = 0;
    foreach ($allPresensi as $presensi) {
        $stmtCekReminder->execute([
            ':today'       => $today,
            ':presensi_id' => $presensi['presensi_id']
        ]);
        if ($stmtCekReminder->fetch()) {
            echo "  Skip presensi #{$presensi['presensi_id']} — already reminded\n";
            continue;
        }

        // ── 3. Buat notifikasi dan kirim Web Push ─────────────────────────
        $jamMulai   = substr($presensi['jam_mulai'], 0, 5);
        $jamSelesai = substr($presensi['jam_selesai'], 0, 5);
        $jenisLabel = match($presensi['jenis_presensi']) {
            'tambahan'  => 'Jadwal Tambahan',
            'pengganti' => 'Jadwal Pengganti',
            default     => 'Kelas Rutin'
        };

        try {
            createNotifikasi($pdo, [
                'jenis'            => 'reminder_kelas',
                'reference_type'   => 'presensi',
                'reference_id'     => $presensi['presensi_id'],
                'role_pengirim'    => 'sistem',
                'user_pengirim_id' => null,
                'context' => [
                    'nama_murid'   => $presensi['nama_murid'],
                    'nama_guru'    => $presensi['nama_guru'],
                    'jam_mulai'    => $jamMulai,
                    'jam_selesai'  => $jamSelesai,
                    'jenis_label'  => $jenisLabel,
                    'murid_user_id'=> $presensi['murid_user_id'],
                    'guru_user_id' => $presensi['guru_user_id']
                ]
            ]);

            $sent++;
            echo "  Sent reminder for presensi #{$presensi['presensi_id']} "
               . "({$presensi['nama_murid']} - {$jamMulai})\n";

        } catch (Exception $e) {
            echo "  ERROR presensi #{$presensi['presensi_id']}: " . $e->getMessage() . "\n";
        }
    }

    echo "  Done. Sent $sent reminder(s).\n";

} catch (Exception $e) {
    echo "  FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
