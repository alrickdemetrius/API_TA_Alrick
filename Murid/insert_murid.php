<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

$data = json_decode(file_get_contents("php://input"), true);

foreach (['nama', 'tanggal_lahir', 'no_telp', 'alamat', 'grade'] as $f) {
    if (!isset($data[$f]) || trim((string)$data[$f]) === '') {
        echo json_encode(['success' => false, 'message' => "Field '$f' wajib diisi"]); exit;
    }
}

$grade = (int)$data['grade'];
if ($grade < 1 || $grade > 8) {
    echo json_encode(['success' => false, 'message' => 'Grade harus antara 1 sampai 8']); exit;
}

$nama = trim($data['nama']);
$tanggalLahir = trim($data['tanggal_lahir']);
$noTelp = trim($data['no_telp']);
$alamat = trim($data['alamat']);
$email = !empty($data['email'])  ? trim($data['email'])  : null;
$nik = !empty($data['nik'])    ? trim($data['nik'])    : null;
$guruId = !empty($data['guru_id'])? trim($data['guru_id']): null;
$slotId = isset($data['slot_id']) && $data['slot_id'] !== null ? (int)$data['slot_id'] : null;
$now = date('Y-m-d H:i:s');

try {
    $pdo->beginTransaction();

    $base = strtolower(preg_replace('/\s+/', '', $nama));
    $username = $base; $i = 1;
    $chk = $pdo->prepare("SELECT 1 FROM tuser WHERE username = :u");
    while ($chk->execute([':u' => $username]) && $chk->fetchColumn()) {
        $username = $base . $i++;
    }

    $lastUserId = $pdo->query(
        "SELECT id FROM tuser WHERE id LIKE 'U%' ORDER BY CAST(SUBSTRING(id,2) AS UNSIGNED) DESC LIMIT 1"
    )->fetchColumn();
    $newUserId = 'U' . str_pad((int)substr($lastUserId ?: 'U0000', 1) + 1, 4, '0', STR_PAD_LEFT);
    $pdo->prepare(
        "INSERT INTO tuser (id,nik,nama,tanggal_lahir,username,password,email,alamat,no_telp,role,created_at)
         VALUES (?,?,?,?,?,?,?,?,?,'murid',?)"
    )->execute([
        $newUserId, $nik, $nama, $tanggalLahir, $username,
        password_hash('piano123', PASSWORD_DEFAULT),
        $email, $alamat, $noTelp, $now
    ]);

    $lastMuridId = $pdo->query(
        "SELECT id FROM tmurid WHERE id LIKE 'M%' ORDER BY CAST(SUBSTRING(id,2) AS UNSIGNED) DESC LIMIT 1"
    )->fetchColumn();
    $newMuridId = 'M' . str_pad((int)substr($lastMuridId ?: 'M0000', 1) + 1, 4, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO tmurid (id,user_id,grade,created_at) VALUES (?,?,?,?)")
        ->execute([$newMuridId, $newUserId, $grade, $now]);

    $jadwalId = null;
    if ($guruId !== null && $slotId !== null) {
        $guru = $pdo->prepare(
            "SELECT id FROM tguru WHERE id=? AND min_grade<=? AND max_grade>=?"
        );
        $guru->execute([$guruId, $grade, $grade]);
        if (!$guru->fetchColumn()) throw new Exception('Guru tidak valid atau tidak sesuai grade murid');

        $stmtSlot = $pdo->prepare("SELECT id FROM tslot_jadwal WHERE id = ?");
        $stmtSlot->execute([$slotId]);
        if (!$stmtSlot->fetchColumn()) throw new Exception('Slot jadwal tidak valid');

        $stmtDupe = $pdo->prepare("SELECT id FROM tjadwal_les WHERE guru_id = ? AND jadwal_slot_id = ?");
        $stmtDupe->execute([$guruId, $slotId]);
        if ($stmtDupe->fetchColumn()) throw new Exception('Guru sudah memiliki jadwal pada slot tersebut');

        $pdo->prepare(
            "INSERT INTO tjadwal_les (status_aktif,guru_id,jadwal_slot_id,murid_id,created_at)
             VALUES (1,?,?,?,?)"
        )->execute([$guruId, $slotId, $newMuridId, $now]);
        $jadwalId = (int)$pdo->lastInsertId();
    }

    $pdo->commit();

    try {
        $hariLabel = ['1'=>'Senin','2'=>'Selasa','3'=>'Rabu','4'=>'Kamis','5'=>'Jumat','6'=>'Sabtu','7'=>'Minggu'];

        $notifContext = [
            'nama_murid'       => $nama,
            'username'         => $username,
            'password_default' => 'piano123',
            'murid_user_id'    => $newUserId,
        ];

        if ($jadwalId !== null && $guruId !== null && $slotId !== null) {
            $stmtJadwalInfo = $pdo->prepare("
                SELECT s.hari, DATE_FORMAT(s.jam_mulai,'%H:%i') AS jam_mulai,
                       DATE_FORMAT(s.jam_selesai,'%H:%i') AS jam_selesai,
                       ug.id AS guru_user_id, ug.nama AS nama_guru
                FROM tslot_jadwal s
                INNER JOIN tjadwal_les jl ON jl.jadwal_slot_id = s.id AND jl.id = ?
                INNER JOIN tguru g        ON g.id = jl.guru_id
                INNER JOIN tuser ug       ON ug.id = g.user_id
                LIMIT 1
            ");
            $stmtJadwalInfo->execute([$jadwalId]);
            $jadwalRow = $stmtJadwalInfo->fetch(PDO::FETCH_ASSOC);
            if ($jadwalRow) {
                $notifContext['guru_user_id'] = $jadwalRow['guru_user_id'];
                $notifContext['jadwal'] = [
                    'hari_label'  => $hariLabel[$jadwalRow['hari']] ?? $jadwalRow['hari'],
                    'jam_mulai'   => $jadwalRow['jam_mulai'],
                    'jam_selesai' => $jadwalRow['jam_selesai'],
                    'nama_guru'   => $jadwalRow['nama_guru'],
                ];
            }
        }

        createNotifikasi($pdo, [
            'jenis'            => 'murid_baru',
            'reference_type'   => 'user',
            'reference_id'     => $newUserId,
            'role_pengirim'    => 'admin',
            'user_pengirim_id' => null,
            'context'          => $notifContext
        ]);
    } catch (Exception $eNotif) {
        error_log('[insert_murid] Notifikasi gagal: ' . $eNotif->getMessage());
    }

    echo json_encode([
        'success' => true, 'message' => 'Murid berhasil ditambahkan',
        'data' => ['user_id' => $newUserId, 'murid_id' => $newMuridId,
                   'username' => $username, 'jadwal_id' => $jadwalId]
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}