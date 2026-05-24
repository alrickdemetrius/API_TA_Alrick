<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);
$idtugas = $data['tugas_id'] ?? null;

if (!$idtugas) {
    echo json_encode(['success' => false, 'message' => 'tugas_id wajib diisi']);
    exit;
}

try {
    // ── Info dasar tugas ────────────────────────────────────────────────────
    $stmtTugas = $pdo->prepare("
    SELECT t.id, t.judul, t.deskripsi_tugas AS deskripsi,
           t.deadline, t.status AS status_tugas,
           t.waktu_pengumpulan AS submit_time,
           t.komentar_guru,
           t.is_dinilai, t.goal_id,
           m.id   AS murid_id,   um.nama AS nama_murid,
           ug.nama AS nama_guru
    FROM ttugas t
    INNER JOIN tjadwal_les j ON j.id  = t.jadwal_id
    INNER JOIN tmurid m      ON m.id  = j.murid_id
    INNER JOIN tuser um      ON um.id = m.user_id
    INNER JOIN tguru g       ON g.id  = j.guru_id
    INNER JOIN tuser ug      ON ug.id = g.user_id
    WHERE t.id = ?
");
    $stmtTugas->execute([$idtugas]);
    $tugas = $stmtTugas->fetch(PDO::FETCH_ASSOC);

    if (!$tugas) {
        echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
        exit;
    }

    // ── File pengumpulan ────────────────────────────────────────────────────
    $stmtFiles = $pdo->prepare("
        SELECT id, nama_file, nama_asli, ukuran, created_at
        FROM ttugas_file
        WHERE tugas_id = ?
        ORDER BY created_at ASC
    ");
    $stmtFiles->execute([$idtugas]);
    $files = array_map(fn($f) => [
        'id' => (int) $f['id'],
        'nama_file' => $f['nama_file'],
        'nama_asli' => $f['nama_asli'],
        'ukuran' => (int) $f['ukuran'],
        'ukuran_kb' => round((int) $f['ukuran'] / 1024, 1),
        'created_at' => $f['created_at']
    ], $stmtFiles->fetchAll(PDO::FETCH_ASSOC));

    // ── Materi referensi ────────────────────────────────────────────────────
    $stmtMateris = $pdo->prepare("
        SELECT m.id, m.nama, m.deskripsi, m.local_file, m.link, m.sheet,
               m.kategori_id, k.nama AS nama_kategori
        FROM ttugas_materi tm
        INNER JOIN tmateri m          ON m.id = tm.materi_id
        LEFT  JOIN tkategori_materi k ON k.id = m.kategori_id
        WHERE tm.tugas_id = ?
        ORDER BY m.nama
    ");
    $stmtMateris->execute([$idtugas]);
    $materis = array_map(fn($r) => [
        'id' => (int) $r['id'],
        'nama' => $r['nama'],
        'deskripsi' => $r['deskripsi'],
        'local_file' => $r['local_file'],
        'link' => $r['link'],
        'sheet' => $r['sheet'],
        'kategori_id' => (int) $r['kategori_id'],
        'nama_kategori' => $r['nama_kategori']
    ], $stmtMateris->fetchAll(PDO::FETCH_ASSOC));

    // ── Penilaian ───────────────────────────────────────────────────────────
    $penilaian = null;
    if ($tugas['is_dinilai'] == 1 && $tugas['goal_id']) {

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

        // Ambil opsi nilai untuk kategorik
        $opsiNilai = [];
        if ($tipe === 'kategorik' && $jenisGoalId) {
            // Coba dari tgoal_opsi_nilai dulu (custom per goal)
            $stmtOpsiGoal = $pdo->prepare("
        SELECT label, urutan_ke, encode_value
        FROM tgoal_opsi_nilai WHERE goal_id = ? ORDER BY urutan_ke
    ");
            $stmtOpsiGoal->execute([$tugas['goal_id']]);
            $opsiNilai = $stmtOpsiGoal->fetchAll(PDO::FETCH_ASSOC);

            if (empty($opsiNilai)) {
                $stmtOpsiDef = $pdo->prepare("
            SELECT label, urutan_ke FROM trubrik_opsi_nilai
            WHERE jenis_goal_id = ? ORDER BY urutan_ke
        ");
                $stmtOpsiDef->execute([$jenisGoalId]);
                $defRows = $stmtOpsiDef->fetchAll(PDO::FETCH_ASSOC);
                $total = count($defRows);
                $opsiNilai = array_map(fn($o) => [
                    'label' => $o['label'],
                    'urutan_ke' => (int) $o['urutan_ke'],
                    'encode_value' => $total > 1 ? round(($o['urutan_ke'] - 1) / ($total - 1) * 3, 3) : 0.0
                ], $defRows);
            }
        }

        $stmtP = $pdo->prepare("
            SELECT p.id AS penilaian_id, p.catatan AS catatan_penilaian,
                dn.id AS detail_nilai_id, dn.rubrik_goal_id,
                dn.nilai, dn.nilai_label,
                rg.kategori AS rubrik_kategori,
                rg.deskripsi AS rubrik_deskripsi,
                rg.nilai_max
            FROM tpenilaian p
            LEFT JOIN tdetail_nilai dn ON dn.penilaian_id = p.id AND dn.sumber = 'tugas'
            LEFT JOIN trubrik_goal rg  ON rg.id = dn.rubrik_goal_id
            WHERE p.tugas_id = ?
            LIMIT 1
        ");
        $stmtP->execute([$idtugas]);
        $rP = $stmtP->fetch(PDO::FETCH_ASSOC);

        if ($rP) {
            $subKategori = [];

            if ($rP['rubrik_goal_id'] && $tugas['goal_id']) {
                $subRows = [];
                if ($rP['detail_nilai_id']) {
                    // Sudah dinilai: coba ambil dari tdetail_nilai_sub
                    $stmtSub = $pdo->prepare("
                    SELECT dns.rubrik_subkategori_id AS sub_id,
                           dns.nilai, dns.nilai_label,
                           rs.nama, rs.nilai_max AS sub_nilai_max, rs.urutan_ke
                    FROM tdetail_nilai_sub dns
                    INNER JOIN trubrik_subkategori rs ON rs.id = dns.rubrik_subkategori_id
                    WHERE dns.detail_nilai_id = ?
                    ORDER BY rs.urutan_ke
                ");
                    $stmtSub->execute([$rP['detail_nilai_id']]);
                    $subRows = $stmtSub->fetchAll(PDO::FETCH_ASSOC);
                }
                if (empty($subRows)) {
                    $stmtSub = $pdo->prepare("
                    SELECT rs.id AS sub_id, NULL AS nilai,
                           rs.nama, rs.nilai_max AS sub_nilai_max, rs.urutan_ke
                    FROM trubrik_subkategori rs
                    WHERE rs.rubrik_goal_id = ? AND rs.goal_id = ?
                    ORDER BY rs.urutan_ke
                ");
                    $stmtSub->execute([$rP['rubrik_goal_id'], $tugas['goal_id']]);
                    $subRows = $stmtSub->fetchAll(PDO::FETCH_ASSOC);
                }

                $subKategori = array_map(fn($s) => [
                    'sub_id'      => (int)$s['sub_id'],
                    'nama'        => $s['nama'],
                    'nilai'       => $s['nilai'] !== null ? (float)$s['nilai'] : null,
                    'nilai_label' => $s['nilai_label'] ?? null,
                    'nilai_max'   => (float)$s['sub_nilai_max']
                ], $subRows);
            }

            $penilaian = [
                'penilaian_id' => (int) $rP['penilaian_id'],
                'detail_nilai_id' => $rP['detail_nilai_id'] ? (int) $rP['detail_nilai_id'] : null,
                'rubrik_goal_id' => $rP['rubrik_goal_id'] ? (int) $rP['rubrik_goal_id'] : null,
                'rubrik_kategori' => $rP['rubrik_kategori'],
                'rubrik_deskripsi' => $rP['rubrik_deskripsi'],
                'nilai_min' => 0,
                'nilai_max' => $rP['nilai_max'] ? (float) $rP['nilai_max'] : 100,
                'nilai' => $rP['nilai'] !== null ? (float) $rP['nilai'] : null,
                'nilai_label' => $rP['nilai_label'],
                'sub_kategori' => $subKategori,
                'catatan_penilaian' => $rP['catatan_penilaian'],
                'tipe_penilaian' => $tipe,
                'opsi_nilai' => $opsiNilai
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'murid_id' => $tugas['murid_id'],
            'nama_murid' => $tugas['nama_murid'],
            'nama_guru' => $tugas['nama_guru'],
            'judul' => $tugas['judul'],
            'deskripsi' => $tugas['deskripsi'],
            'deadline' => $tugas['deadline'],
            'status_tugas' => $tugas['status_tugas'],
            'submit_time' => $tugas['submit_time'],
            'komentar_guru' => $tugas['komentar_guru'],
            'is_dinilai' => (int) $tugas['is_dinilai'],
            'goal_id' => $tugas['goal_id'] ? (int) $tugas['goal_id'] : null,
            'files' => $files,
            'has_files' => count($files) > 0,
            'materis' => $materis,
            'penilaian' => $penilaian
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}