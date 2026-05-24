<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../Notifikasi/notifikasi_helper.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['tugas_id'])) {
    echo json_encode(['success' => false, 'message' => 'tugas_id wajib diisi']);
    exit;
}

$tugasId = (int) $data['tugas_id'];
$komentarGuru = isset($data['komentar_guru']) ? trim($data['komentar_guru']) : null;
$nilaiLabel = $data['nilai_label'] ?? null;
$subNilai = $data['sub_nilai'] ?? [];   // [{sub_id, nilai}] untuk numerikal
$guruUserId = $data['guru_user_id'] ?? null;

try {
    $pdo->beginTransaction();

    // Ambil info tugas
    $stmtTugas = $pdo->prepare("
        SELECT t.id, t.is_dinilai, t.judul, t.goal_id,
               ug.id AS guru_user_id, ug.nama AS nama_guru,
               um.id AS murid_user_id, um.nama AS nama_murid
        FROM ttugas t
        INNER JOIN tjadwal_les j ON j.id = t.jadwal_id
        INNER JOIN tguru g       ON g.id = j.guru_id
        INNER JOIN tuser ug      ON ug.id = g.user_id
        INNER JOIN tmurid m      ON m.id  = j.murid_id
        INNER JOIN tuser um      ON um.id = m.user_id
        WHERE t.id = ?
    ");
    $stmtTugas->execute([$tugasId]);
    $tugas = $stmtTugas->fetch(PDO::FETCH_ASSOC);

    if (!$tugas)
        throw new Exception('Tugas tidak ditemukan');

    // Cek file sudah dikumpulkan
    $stmtCekFile = $pdo->prepare("SELECT COUNT(*) FROM ttugas_file WHERE tugas_id = ?");
    $stmtCekFile->execute([$tugasId]);
    if ($stmtCekFile->fetchColumn() == 0 && $tugas['is_dinilai'] == 0) {
        throw new Exception('Tugas belum dikumpulkan oleh murid');
    }

    $now = date('Y-m-d H:i:s');

    if (!$guruUserId)
        $guruUserId = $tugas['guru_user_id'];

    if ($tugas['is_dinilai'] == 1) {

        // Ambil tipe penilaian dari goal
        $stmtTipe = $pdo->prepare("
            SELECT jg.tipe_penilaian, jg.id AS jenis_goal_id
            FROM tgoal g
            INNER JOIN tjenis_goal jg ON jg.id = g.jenis_goal_id
            WHERE g.id = ?
        ");
        $stmtTipe->execute([$tugas['goal_id']]);
        $tipeRow = $stmtTipe->fetch(PDO::FETCH_ASSOC);
        $tipe = $tipeRow ? $tipeRow['tipe_penilaian'] : 'numerikal';
        $jenisGoalId = $tipeRow ? (int) $tipeRow['jenis_goal_id'] : null;

        if ($tipe === 'numerikal') {
            $subNilaiFiltered = array_filter(
                $subNilai,
                fn($s) =>
                isset($s['nilai']) && $s['nilai'] !== '' && $s['nilai'] !== null
            );
            if (empty($subNilaiFiltered)) {
                throw new Exception('Minimal satu nilai sub-kategori wajib diisi');
            }

            // Ambil detail_nilai_id untuk tugas ini
            $stmtDnId = $pdo->prepare("
                SELECT dn.id, rg.nilai_max
                FROM tpenilaian p
                INNER JOIN tdetail_nilai dn ON dn.penilaian_id = p.id
                INNER JOIN trubrik_goal rg  ON rg.id = dn.rubrik_goal_id
                WHERE p.tugas_id = ? AND dn.sumber = 'tugas'
                LIMIT 1
            ");
            $stmtDnId->execute([$tugasId]);
            $dnRow = $stmtDnId->fetch(PDO::FETCH_ASSOC);
            if (!$dnRow)
                throw new Exception('Data penilaian tidak ditemukan');

            $detailNilaiId = (int) $dnRow['id'];
            $nilaiMax = (float) $dnRow['nilai_max'];


            // Validasi setiap nilai sub
            foreach ($subNilaiFiltered as $s) {
                $v = (float) $s['nilai'];
                if ($v < 0 || ($nilaiMax > 0 && $v > $nilaiMax)) {
                    throw new Exception("Nilai harus antara 0 dan $nilaiMax");
                }
            }

            // Hitung agregat (rata-rata)
            $nilaiValues = array_map(fn($s) => (float) $s['nilai'], $subNilaiFiltered);
            $nilaiAgregat = array_sum($nilaiValues) / count($nilaiValues);

            // Update nilai agregat di tdetail_nilai
            $pdo->prepare("
                UPDATE tdetail_nilai SET nilai = ?, updated_at = NOW() WHERE id = ?
            ")->execute([$nilaiAgregat, $detailNilaiId]);

            // Insert/update per sub ke tdetail_nilai_sub
            $stmtSubUpsert = $pdo->prepare("
                INSERT INTO tdetail_nilai_sub (detail_nilai_id, rubrik_subkategori_id, nilai, created_at)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE nilai = VALUES(nilai), updated_at = NOW()
            ");
            foreach ($subNilaiFiltered as $s) {
                $stmtSubUpsert->execute([
                    $detailNilaiId,
                    (int) $s['sub_id'],
                    (float) $s['nilai'],
                    $now
                ]);
            }

        } else {
            $subIds = $data['sub_ids'] ?? [];
            $subIdsFiltered = array_filter($subIds, fn($s) => !empty($s['nilai_label']));

            if (empty($subIdsFiltered)) {
                throw new Exception('Minimal satu nilai sub-kategori wajib dipilih');
            }

            // Ambil label terbanyak sebagai label rubrik
            // Ambil opsi nilai untuk nearest encode
            $stmtOpsi = $pdo->prepare("
                SELECT label, encode_value FROM tgoal_opsi_nilai
                WHERE goal_id = ? ORDER BY urutan_ke
            ");
            $stmtOpsi->execute([$tugas['goal_id']]);
            $opsiRows = $stmtOpsi->fetchAll(PDO::FETCH_ASSOC);

            if (empty($opsiRows) && $jenisGoalId) {
                $stmtOpsiDef = $pdo->prepare("
                    SELECT label, urutan_ke FROM trubrik_opsi_nilai
                    WHERE jenis_goal_id = ? ORDER BY urutan_ke
                ");
                $stmtOpsiDef->execute([$jenisGoalId]);
                $defRows = $stmtOpsiDef->fetchAll(PDO::FETCH_ASSOC);
                $total = count($defRows);
                $opsiRows = array_map(fn($o) => [
                    'label' => $o['label'],
                    'encode_value' => $total > 1
                        ? round(($o['urutan_ke'] - 1) / ($total - 1) * 3, 3)
                        : 0.0
                ], $defRows);
            }

            $encodeMap = [];
            foreach ($opsiRows as $o) {
                $encodeMap[$o['label']] = (float) $o['encode_value'];
            }

            $totalEncode = 0;
            $count = 0;
            foreach ($subIdsFiltered as $s) {
                $totalEncode += $encodeMap[$s['nilai_label']] ?? 0;
                $count++;
            }
            $avgEncode = $count > 0 ? $totalEncode / $count : 0;

            $nilaiLabel = null;
            $minJarak = PHP_FLOAT_MAX;
            $minUrutan = PHP_INT_MIN;
            foreach ($opsiRows as $o) {
                $jarak = abs((float) $o['encode_value'] - $avgEncode);
                $urutan = (int) ($o['urutan_ke'] ?? 0);
                if ($jarak < $minJarak || ($jarak === $minJarak && $urutan > $minUrutan)) {
                    $minJarak = $jarak;
                    $minUrutan = $urutan;
                    $nilaiLabel = $o['label'];
                }
            }

            // Update nilai_label di tdetail_nilai
            $pdo->prepare("
                UPDATE tdetail_nilai dn
                INNER JOIN tpenilaian p ON p.id = dn.penilaian_id
                SET dn.nilai_label = ?, dn.updated_at = NOW()
                WHERE p.tugas_id = ? AND dn.sumber = 'tugas'
            ")->execute([$nilaiLabel, $tugasId]);

            // Insert per sub ke tdetail_nilai_sub
            $stmtDnId2 = $pdo->prepare("
                SELECT dn.id FROM tpenilaian p
                INNER JOIN tdetail_nilai dn ON dn.penilaian_id = p.id
                WHERE p.tugas_id = ? AND dn.sumber = 'tugas' LIMIT 1
            ");
            $stmtDnId2->execute([$tugasId]);
            $dnId = (int) $stmtDnId2->fetchColumn();

            if ($dnId) {
                $stmtSubKat = $pdo->prepare("
                    INSERT INTO tdetail_nilai_sub
                        (detail_nilai_id, rubrik_subkategori_id, nilai, nilai_label, created_at)
                    VALUES (?, ?, NULL, ?, ?)
                    ON DUPLICATE KEY UPDATE nilai_label = VALUES(nilai_label), updated_at = NOW()
                ");
                foreach ($subIdsFiltered as $s) {
                    $stmtSubKat->execute([$dnId, (int) $s['sub_id'], $s['nilai_label'], $now]);
                }
            }
        }

    } else {
        if (!$komentarGuru)
            throw new Exception('Komentar guru wajib diisi');
    }

    // Update status tugas
    $pdo->prepare("
        UPDATE ttugas SET status = 'selesai', komentar_guru = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$komentarGuru, $tugasId]);

    $pdo->commit();

    try {
        if ($tugas['is_dinilai'] == 1) {
            createNotifikasi($pdo, [
                'jenis' => 'tugas_dinilai',
                'reference_type' => 'tugas',
                'reference_id' => $tugasId,
                'role_pengirim' => 'guru',
                'user_pengirim_id' => $guruUserId,
                'context' => [
                    'nama_tugas' => $tugas['judul'],
                    'nama_murid' => $tugas['nama_murid'],
                    'nama_guru' => $tugas['nama_guru'],
                    'nilai' => !empty($subNilai)
                        ? implode(', ', array_map(fn($s) => $s['nilai'], $subNilai))
                        : ($nilaiLabel ?? '-'),
                    'guru_user_id' => $tugas['guru_user_id'],
                    'murid_user_id' => $tugas['murid_user_id']
                ]
            ]);
        }
    } catch (Exception $eNotif) {
        error_log('[selesai_tugas] Notifikasi gagal: ' . $eNotif->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Tugas berhasil diselesaikan',
        'data' => [
            'tugas_id' => $tugasId,
            'nilai' => !empty($subNilai) ? $subNilai : null,
            'nilai_label' => $nilaiLabel,
            'status' => 'selesai',
            'is_dinilai' => (int) $tugas['is_dinilai']
        ]
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}