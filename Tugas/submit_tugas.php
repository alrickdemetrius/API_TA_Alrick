<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Origin');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

$uploadDir        = __DIR__ . '/../uploads/tugas/';
$allowedExtensions = ['mp4', 'mov', 'avi', 'mkv', 'jpg', 'jpeg', 'png', 'gif'];
$maxFileSize      = 50 * 1024 * 1024; // 50MB per file

if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

try {
    // Validasi input
    if (empty($_POST['tugas_id']))  throw new Exception('tugas_id wajib diisi');
    if (empty($_POST['murid_id']))  throw new Exception('murid_id wajib diisi');
    if (empty($_FILES['files']))    throw new Exception('Minimal satu file wajib diupload');

    $tugasId = (int)$_POST['tugas_id'];
    $muridId = $_POST['murid_id'];

    // Normalisasi $_FILES['files'] agar selalu array
    $rawFiles = $_FILES['files'];
    $fileList = [];
    if (is_array($rawFiles['name'])) {
        // Multiple files
        for ($i = 0; $i < count($rawFiles['name']); $i++) {
            $fileList[] = [
                'name'     => $rawFiles['name'][$i],
                'type'     => $rawFiles['type'][$i],
                'tmp_name' => $rawFiles['tmp_name'][$i],
                'error'    => $rawFiles['error'][$i],
                'size'     => $rawFiles['size'][$i],
            ];
        }
    } else {
        // Single file
        $fileList[] = $rawFiles;
    }

    if (empty($fileList)) throw new Exception('Tidak ada file yang diterima');

    foreach ($fileList as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $msgs = [
                UPLOAD_ERR_INI_SIZE  => 'File terlalu besar (php.ini)',
                UPLOAD_ERR_FORM_SIZE => 'File terlalu besar (form)',
                UPLOAD_ERR_PARTIAL   => 'File hanya terupload sebagian',
                UPLOAD_ERR_NO_FILE   => 'Tidak ada file',
                UPLOAD_ERR_NO_TMP_DIR=> 'Folder temp tidak ditemukan',
                UPLOAD_ERR_CANT_WRITE=> 'Gagal menulis ke disk',
            ];
            throw new Exception($msgs[$file['error']] ?? 'Error upload: kode ' . $file['error']);
        }
        if ($file['size'] > $maxFileSize) {
            throw new Exception("File '{$file['name']}' terlalu besar. Maksimal 10MB.");
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions)) {
            throw new Exception("Format '{$ext}' tidak didukung. Gunakan: " . implode(', ', $allowedExtensions));
        }
    }

    $stmtCheck = $pdo->prepare("
        SELECT t.id, t.judul, t.deadline,
               ug.id as guru_user_id, ug.nama as nama_guru,
               um.id as murid_user_id, um.nama as nama_murid
        FROM ttugas t
        INNER JOIN tjadwal_les j ON j.id = t.jadwal_id
        INNER JOIN tguru g       ON g.id = j.guru_id
        INNER JOIN tuser ug      ON ug.id = g.user_id
        INNER JOIN tmurid m      ON m.id  = j.murid_id
        INNER JOIN tuser um      ON um.id = m.user_id
        WHERE t.id = :tugas_id");
    $stmtCheck->execute([':tugas_id' => $tugasId]);
    $tugasInfo = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$tugasInfo) throw new Exception('Tugas tidak ditemukan. ID: ' . $tugasId);

    $uploadedFiles = [];
    foreach ($fileList as $file) {
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $namaFile = 'tugas' . $tugasId . '_' . $muridId . '_' . uniqid() . '.' . $ext;
        $filePath = $uploadDir . $namaFile;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            foreach ($uploadedFiles as $f) {
                if (file_exists($uploadDir . $f['nama_file'])) unlink($uploadDir . $f['nama_file']);
            }
            throw new Exception("Gagal mengupload file '{$file['name']}'");
        }

        $uploadedFiles[] = [
            'nama_file' => $namaFile,
            'nama_asli' => $file['name'],
            'ukuran'    => $file['size'],
        ];
    }

    $waktuKumpul = date('Y-m-d H:i:s');
    $pdo->beginTransaction();

    try {
        $deadline    = new DateTime($tugasInfo['deadline']);
        $submitTime  = new DateTime($waktuKumpul);
        $isTerlambat = $submitTime > $deadline;
        $newStatus   = $isTerlambat ? 'terlambat' : 'dikumpulkan';

        $pdo->prepare("
            UPDATE ttugas
            SET waktu_pengumpulan = ?, status = ?, updated_at = ?
            WHERE id = ?"
        )->execute([$waktuKumpul, $newStatus, $waktuKumpul, $tugasId]);

        $stmtFile = $pdo->prepare("
            INSERT INTO ttugas_file (tugas_id, nama_file, nama_asli, ukuran, created_at)
            VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($uploadedFiles as $f) {
            $stmtFile->execute([$tugasId, $f['nama_file'], $f['nama_asli'], $f['ukuran'], $waktuKumpul]);
        }

        // Notifikasi
        $notifJenis = $isTerlambat ? 'tugas_terlambat' : 'tugas_kumpul';
        $context = [
            'nama_murid'   => $tugasInfo['nama_murid'],
            'nama_tugas'   => $tugasInfo['judul'],
            'waktu_kumpul' => date('d/m/Y H:i', strtotime($waktuKumpul)),
            'guru_user_id' => $tugasInfo['guru_user_id'],
            'murid_user_id'=> $tugasInfo['murid_user_id'],
        ];
        if ($isTerlambat) {
            $context['deadline'] = date('d/m/Y H:i', strtotime($tugasInfo['deadline']));
        }

        createNotifikasi($pdo, [
            'jenis'            => $notifJenis,
            'reference_type'   => 'tugas',
            'reference_id'     => $tugasId,
            'role_pengirim'    => 'murid',
            'user_pengirim_id' => $tugasInfo['murid_user_id'],
            'context'          => $context
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => $isTerlambat ? 'Tugas berhasil dikumpulkan (Terlambat)' : 'Tugas berhasil dikumpulkan',
            'data'    => [
                'tugas_id'         => $tugasId,
                'murid_id'         => $muridId,
                'status'           => $newStatus,
                'is_terlambat'     => $isTerlambat,
                'waktu_pengumpulan'=> $waktuKumpul,
                'files'            => array_map(fn($f) => [
                    'nama_file' => $f['nama_file'],
                    'nama_asli' => $f['nama_asli'],
                    'ukuran_kb' => round($f['ukuran'] / 1024, 1)
                ], $uploadedFiles)
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        // Hapus semua file yang sudah terupload
        foreach ($uploadedFiles as $f) {
            if (file_exists($uploadDir . $f['nama_file'])) unlink($uploadDir . $f['nama_file']);
        }
        throw $e;
    }

} catch (PDOException $e) {
    error_log("DB error submit_tugas: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error submit_tugas: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}