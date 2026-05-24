<?php
function createNotifikasi($pdo, $config)
{
    foreach (['jenis', 'reference_type', 'reference_id', 'role_pengirim', 'context'] as $f) {
        if (!isset($config[$f]))
            throw new Exception("Missing required field: $f");
    }

    $message = generateNotificationMessage($config['jenis'], $config['context']);

    $pdo->prepare(
        "INSERT INTO tnotifikasi (judul, pesan, jenis, reference_type, reference_id,
             role_pengirim, user_pengirim_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    )->execute([
                $message['judul'],
                $message['pesan'],
                $config['jenis'],
                $config['reference_type'],
                $config['reference_id'],
                $config['role_pengirim'],
                $config['user_pengirim_id'] ?? null,
            ]);

    $notifId = (int) $pdo->lastInsertId();
    $recipients = getNotificationRecipients($pdo, $config['jenis'], $config['context']);

    $stmt = $pdo->prepare(
        "INSERT INTO tnotifikasi_penerima (notifikasi_id, user_id, created_at) VALUES (?, ?, NOW())"
    );
    foreach ($recipients as $userId)
        $stmt->execute([$notifId, $userId]);

    sendWebPushToUsers($pdo, $recipients, $message['judul'], $message['pesan'], [
        'notifikasi_id' => (string) $notifId,
        'jenis' => $config['jenis']
    ]);

    return $notifId;
}

function generateNotificationMessage($jenis, $context)
{
    switch ($jenis) {

        case 'presensi_submit':
            return [
                'judul' => "Presensi - {$context['nama_murid']}",
                'pesan' => "Guru {$context['nama_guru']} telah menyimpan presensi untuk {$context['nama_murid']} pada {$context['tanggal']} jam {$context['jam_mulai']}-{$context['jam_selesai']}"
            ];

        case 'tugas_baru':
            return [
                'judul' => "Tugas - {$context['nama_tugas']} - {$context['nama_murid']}",
                'pesan' => "Guru {$context['nama_guru']} memberikan tugas baru '{$context['nama_tugas']}' untuk {$context['nama_murid']}. Deadline: {$context['deadline']}"
            ];

        case 'tugas_ubah':
            return [
                'judul' => "Tugas Diubah - {$context['nama_tugas']} - {$context['nama_murid']}",
                'pesan' => "Guru {$context['nama_guru']} mengubah data tugas '{$context['nama_tugas']}' untuk {$context['nama_murid']}. Silakan cek detail tugas untuk informasi terbaru."
            ];

        case 'tugas_kumpul':
            return [
                'judul' => "Tugas Dikumpulkan - {$context['nama_tugas']} - {$context['nama_murid']}",
                'pesan' => "{$context['nama_murid']} telah mengumpulkan tugas '{$context['nama_tugas']}' pada {$context['waktu_kumpul']}"
            ];

        case 'tugas_dinilai':
            return [
                'judul' => "Tugas Dinilai - {$context['nama_tugas']} - {$context['nama_murid']}",
                'pesan' => "Tugas '{$context['nama_tugas']}' telah dinilai oleh Guru {$context['nama_guru']}. Nilai: {$context['nilai']}"
            ];

        case 'tugas_terlambat':
            return [
                'judul' => "Tugas Terlambat - {$context['nama_tugas']} - {$context['nama_murid']}",
                'pesan' => "{$context['nama_murid']} mengumpulkan tugas '{$context['nama_tugas']}' terlambat. Deadline: {$context['deadline']}, Dikumpulkan: {$context['waktu_kumpul']}"
            ];

        case 'goal_baru':
            return [
                'judul' => "Target Baru - {$context['nama_murid']}",
                'pesan' => "Guru {$context['nama_guru']} membuat target baru untuk {$context['nama_murid']}."
                    . " Nama: {$context['nama_goal']}. Jenis: {$context['nama_jenis_goal']}."
            ];

        case 'murid_baru':
            $jadwalInfo = isset($context['jadwal'])
                ? " Jadwal: {$context['jadwal']['hari_label']}, {$context['jadwal']['jam_mulai']}-{$context['jadwal']['jam_selesai']}, Guru: {$context['jadwal']['nama_guru']}."
                : " Jadwal belum diatur.";
            return [
                'judul' => "Murid Baru - {$context['nama_murid']}",
                'pesan' => "Murid baru telah ditambahkan: {$context['nama_murid']}."
                    . " Username: {$context['username']}. Password awal: {$context['password_default']}."
                    . " Harap segera ubah password setelah login pertama."
                    . $jadwalInfo
            ];

        case 'jadwal_diubah':
            return [
                'judul' => "Jadwal Diubah - {$context['nama_murid']}",
                'pesan' => "Jadwal rutin {$context['nama_murid']} telah diperbarui."
                    . " Guru: {$context['nama_guru']}."
                    . " Hari: {$context['hari_label']}, Jam: {$context['jam_mulai']}-{$context['jam_selesai']}."
            ];

        case 'jadwal_nonaktif':
            return [
                'judul' => "Jadwal Dinonaktifkan - {$context['nama_murid']}",
                'pesan' => "Jadwal les {$context['nama_murid']} dengan Guru {$context['nama_guru']}"
                    . " ({$context['hari_label']}, {$context['jam_mulai']}-{$context['jam_selesai']})"
                    . " telah dinonaktifkan karena ketersediaan guru berubah."
                    . " Silakan atur jadwal baru untuk murid ini."
            ];

        case 'profil_diubah':
            return [
                'judul' => "Profil Diubah - {$context['nama_user']}",
                'pesan' => "{$context['nama_user']} telah mengubah data profil mereka."
            ];

        case 'goal_selesai':
            return [
                'judul' => "Goal Tercapai - {$context['nama_murid']}",
                'pesan' => "Selamat! {$context['nama_murid']} berhasil mencapai goal '{$context['nama_goal']}' dengan nilai {$context['nilai_akhir']}"
            ];

        case 'goal_batal':
            return [
                'judul' => "Goal Dibatalkan - {$context['nama_murid']}",
                'pesan' => "Goal '{$context['nama_goal']}' untuk {$context['nama_murid']} telah dibatalkan oleh Guru {$context['nama_guru']}"
            ];

        case 'goal_prediksi':
            $label = $context['label_prediksi'] ?? 'Tidak Diketahui';
            $confidence = isset($context['confidence']) ? round($context['confidence'], 1) : 0;
            return [
                'judul' => "Prediksi Kelulusan - {$context['nama_murid']}",
                'pesan' => "Hasil prediksi untuk goal '{$context['nama_goal']}' dari {$context['nama_murid']}: {$label} dengan confidence {$confidence}%. Target: {$context['tanggal_target']}"
            ];

        case 'reminder_kelas':
            return [
                'judul' => "Reminder Kelas: {$context['nama_murid']}",
                'pesan' => "{$context['jenis_label']} akan dimulai 1 jam lagi. "
                    . "Murid: {$context['nama_murid']}, "
                    . "Guru: {$context['nama_guru']}, "
                    . "Waktu: {$context['jam_mulai']}-{$context['jam_selesai']}."
            ];

        case 'generate_presensi_bentrok':
            $detail = !empty($context['detail_lines'])
                ? implode(', ', $context['detail_lines'])
                : '-';
            return [
                'judul' => "Generate Presensi: {$context['jumlah']} Jadwal Bentrok — {$context['bulan']}",
                'pesan' => "Saat generate presensi {$context['bulan']}, {$context['jumlah']} jadwal rutin otomatis ditandai 'diganti' karena bentrok dengan request yang sudah disetujui. Detail: {$detail}"
            ];

        case 'request_tambahan':
            $ket = !empty($context['keterangan']) ? " Keterangan: \"{$context['keterangan']}\"." : '';
            return [
                'judul' => "Request Jadwal Tambahan - {$context['nama_murid']}",
                'pesan' => "{$context['nama_pengirim']} mengajukan request jadwal tambahan untuk {$context['nama_murid']} pada {$context['tanggal']} jam {$context['jam_mulai']}-{$context['jam_selesai']}.{$ket}"
            ];

        case 'request_pengganti':
            $ket = !empty($context['keterangan']) ? " Keterangan: \"{$context['keterangan']}\"." : '';
            return [
                'judul' => "Request Jadwal Pengganti - {$context['nama_murid']}",
                'pesan' => "{$context['nama_pengirim']} mengajukan request jadwal pengganti untuk {$context['nama_murid']}. Jadwal lama: {$context['tanggal_lama']}, Pengganti: {$context['tanggal_baru']} jam {$context['jam_mulai']}-{$context['jam_selesai']}.{$ket}"
            ];

        case 'request_diterima':
            $jr  = $context['jenis_request'] === 'tambahan' ? 'tambahan' : 'pengganti';
            $ket = !empty($context['alasan_selesai']) ? " Keterangan: \"{$context['alasan_selesai']}\"." : '';
            return [
                'judul' => "Request Disetujui - {$context['nama_murid']}",
                'pesan' => "Request jadwal {$jr} untuk {$context['nama_murid']} telah disetujui. Jadwal: {$context['tanggal']} jam {$context['jam_mulai']}-{$context['jam_selesai']}.{$ket}"
            ];

        case 'request_ditolak':
            $jr  = $context['jenis_request'] === 'tambahan' ? 'tambahan' : 'pengganti';
            $ket = !empty($context['alasan_selesai']) ? " Alasan: \"{$context['alasan_selesai']}\"." : '';
            return [
                'judul' => "Request Ditolak - {$context['nama_murid']}",
                'pesan' => "Request jadwal {$jr} untuk {$context['nama_murid']} telah ditolak.{$ket}"
            ];

        case 'nilai_masuk':
            return [
                'judul' => "Nilai Masuk - {$context['nama_murid']}",
                'pesan' => "Nilai baru untuk {$context['nama_murid']} telah diinput. {$context['jenis_nilai']} pada {$context['tanggal']}"
            ];

        case 'lain':
            return [
                'judul' => $context['judul'] ?? 'Notifikasi',
                'pesan' => $context['pesan'] ?? 'Anda memiliki notifikasi baru'
            ];

        default:
            throw new Exception("Unknown notification type: $jenis");
    }
}

function getNotificationRecipients($pdo, $jenis, $context)
{
    $recipients = getAdminIds($pdo);

    $guruMuridCases = [
        'presensi_submit',
        'tugas_kumpul',
        'tugas_terlambat',
        'goal_baru',
        'goal_selesai',
        'goal_batal',
        'goal_prediksi',
        'nilai_masuk',
        'request_tambahan',
        'request_pengganti',
        'request_diterima',
        'request_ditolak',
        'murid_baru',
        'jadwal_diubah',
        'jadwal_nonaktif',
        'reminder_kelas'
    ];

    if (in_array($jenis, $guruMuridCases)) {
        if (isset($context['guru_user_id']))
            $recipients[] = $context['guru_user_id'];
        if (isset($context['murid_user_id']))
            $recipients[] = $context['murid_user_id'];

    } elseif (in_array($jenis, ['tugas_baru', 'tugas_ubah', 'tugas_dinilai'])) {
        if (isset($context['guru_user_id']))
            $recipients[] = $context['guru_user_id'];
        if (isset($context['murid_user_ids']) && is_array($context['murid_user_ids'])) {
            $recipients = array_merge($recipients, $context['murid_user_ids']);
        } elseif (isset($context['murid_user_id'])) {
            $recipients[] = $context['murid_user_id'];
        }

    } elseif ($jenis === 'profil_diubah') {
        // hanya admin
    } elseif ($jenis === 'generate_presensi_bentrok') {
        // hanya admin
    } elseif ($jenis === 'lain' && isset($context['recipients']) && is_array($context['recipients'])) {
        $recipients = array_merge($recipients, $context['recipients']);
    }

    return array_values(array_filter(array_unique($recipients)));
}

function getAdminIds($pdo)
{
    $stmt = $pdo->prepare("SELECT id FROM tuser WHERE role = 'admin'");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function formatTanggalNotif($date)
{
    $ts = $date ? strtotime($date) : false;
    return $ts ? date('d/m/Y', $ts) : (string) $date;
}

function formatWaktuNotif($datetime)
{
    $ts = $datetime ? strtotime($datetime) : false;
    return $ts ? date('d/m/Y H:i', $ts) : (string) $datetime;
}

function sendWebPushToUsers($pdo, $userIds, $judul, $pesan, $extraData = [])
{
    if (empty($userIds))
        return;

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        error_log('[WebPush] vendor/autoload.php tidak ditemukan di: ' . $autoloadPath);
        return;
    }
    require_once $autoloadPath;

    try {
        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject' => 'mailto:admin@pianokursus.com',
                'publicKey' => 'BLE1Uhi7SehXdkIcXrQSpW0J_3gJ1Eh_x5llCZLlL7CB4KWfiiP9L1Vkzy-4_xPunzK9hpHRQ1Ix4xgxA4957CY',
                'privateKey' => 'JJo0ea9_Tj7kT_LIyPOAOweDkw-HG5BR8Op62PwN-fg',
            ]
        ]);

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id AS user_id, push_endpoint AS endpoint, push_p256dh AS p256dh, push_auth AS auth
             FROM tuser
             WHERE id IN ($placeholders)
               AND push_endpoint IS NOT NULL
               AND push_p256dh IS NOT NULL
               AND push_auth IS NOT NULL"
        );
        $stmt->execute($userIds);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($subscriptions))
            return;

        $payload = json_encode([
            'title' => $judul,
            'body' => $pesan,
            'icon' => '/assets/icon/favicon.png',
            'data' => array_merge($extraData, ['judul' => $judul, 'pesan' => $pesan])
        ]);

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $sub['endpoint'],
                    'keys' => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth']]
                ]),
                $payload
            );
        }

        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                error_log('[WebPush] Gagal kirim ke: ' . $report->getEndpoint()
                    . ' | Reason: ' . $report->getReason());
                if ($report->isSubscriptionExpired()) {
                    $pdo->prepare(
                        "UPDATE tuser SET push_endpoint = NULL, push_p256dh = NULL, push_auth = NULL
                         WHERE push_endpoint = ?"
                    )->execute([$report->getEndpoint()]);
                }
            }
        }

    } catch (\Exception $e) {
        error_log('[WebPush] Exception: ' . $e->getMessage());
    }
}