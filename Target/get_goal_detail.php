<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data = json_decode(file_get_contents("php://input"), true);
$goalId = $data['goal_id'] ?? null;

if (!$goalId) {
    echo json_encode(['success' => false, 'message' => 'goal_id wajib diisi']);
    exit;
}

function fmtTgl($d)
{
    if (!$d)
        return '-';
    $b = [
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mar',
        4 => 'Apr',
        5 => 'Mei',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Agu',
        9 => 'Sep',
        10 => 'Okt',
        11 => 'Nov',
        12 => 'Des'
    ];
    $ts = strtotime($d);
    return date('j', $ts) . ' ' . $b[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

try {
    $stmtGoal = $pdo->prepare("
        SELECT g.id AS goal_id, g.murid_id, g.jenis_goal_id,
               g.tanggal_target, g.status, g.catatan_umum, g.hasil_akhir,
               u.nama AS nama_murid, u.tanggal_lahir, u.alamat, u.no_telp, u.email,
               m.grade,
               jg.nama AS nama_jenis_goal, jg.deskripsi AS deskripsi_jenis_goal,
               jg.tipe_penilaian
        FROM tgoal g
        INNER JOIN tmurid m     ON m.id  = g.murid_id
        INNER JOIN tuser u      ON u.id  = m.user_id
        INNER JOIN tjenis_goal jg ON jg.id = g.jenis_goal_id
        WHERE g.id = ?
    ");
    $stmtGoal->execute([$goalId]);
    $goal = $stmtGoal->fetch(PDO::FETCH_ASSOC);
    if (!$goal) {
        echo json_encode(['success' => false, 'message' => 'Goal tidak ditemukan']);
        exit;
    }

    $tipe = $goal['tipe_penilaian'];
    $jenisGoalId = (int) $goal['jenis_goal_id'];

    $stmtOpsiGoal = $pdo->prepare("
        SELECT label, urutan_ke, encode_value
        FROM tgoal_opsi_nilai WHERE goal_id = ? ORDER BY urutan_ke
    ");
    $stmtOpsiGoal->execute([$goalId]);
    $opsiNilai = $stmtOpsiGoal->fetchAll(PDO::FETCH_ASSOC);

    if (empty($opsiNilai) && $tipe === 'kategorik') {
        $stmtOpsiDef = $pdo->prepare("
            SELECT label, urutan_ke FROM trubrik_opsi_nilai
            WHERE jenis_goal_id = ? ORDER BY urutan_ke
        ");
        $stmtOpsiDef->execute([$jenisGoalId]);
        $defRows = $stmtOpsiDef->fetchAll(PDO::FETCH_ASSOC);
        $totalDef = count($defRows);
        $opsiNilai = array_map(fn($o) => [
            'label' => $o['label'],
            'urutan_ke' => (int) $o['urutan_ke'],
            'encode_value' => $totalDef > 1
                ? round(($o['urutan_ke'] - 1) / ($totalDef - 1) * 3, 3)
                : 0.0
        ], $defRows);
    }

    $labelToEncode = [];
    foreach ($opsiNilai as $o) {
        $labelToEncode[round((float) $o['encode_value'], 3)] = $o['label'];
    }

    $labelToEncodeVal = [];
    foreach ($opsiNilai as $o) {
        $labelToEncodeVal[$o['label']] = round((float) $o['encode_value'], 3);
    }

    $stmtRubrik = $pdo->prepare("
        SELECT id AS rubrik_id, kategori, deskripsi, urutan_ke, nilai_max
        FROM trubrik_goal WHERE jenis_goal_id = ? ORDER BY urutan_ke
    ");
    $stmtRubrik->execute([$jenisGoalId]);
    $semuaRubrik = $stmtRubrik->fetchAll(PDO::FETCH_ASSOC);

    $stmtSub = $pdo->prepare("
        SELECT id AS sub_id, rubrik_goal_id, nama, nilai_max, urutan_ke
        FROM trubrik_subkategori
        WHERE rubrik_goal_id IN (
            SELECT id FROM trubrik_goal WHERE jenis_goal_id = ?
        )
        ORDER BY rubrik_goal_id, urutan_ke
    ");
    $stmtSub->execute([$jenisGoalId]);
    $subRows = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

    $subByRubrik = [];
    foreach ($subRows as $s) {
        $subByRubrik[(int) $s['rubrik_goal_id']][] = [
            'sub_id' => (int) $s['sub_id'],
            'nama' => $s['nama'],
            'nilai_max' => (float) $s['nilai_max'],
            'urutan_ke' => (int) $s['urutan_ke']
        ];
    }

    $stmtDetail = $pdo->prepare("
        SELECT dg.id AS detail_id, dg.rubrik_goal_id, dg.catatan, rg.kategori
        FROM tdetail_goal dg
        INNER JOIN trubrik_goal rg ON rg.id = dg.rubrik_goal_id
        WHERE dg.goal_id = ? ORDER BY rg.urutan_ke
    ");
    $stmtDetail->execute([$goalId]);
    $detailRows = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

    $stmtMateris = $pdo->prepare("
        SELECT m.id, m.nama, m.deskripsi, m.local_file, m.link, m.sheet,
               m.kategori_id, k.nama AS nama_kategori
        FROM tdetail_goal_materi dgm
        INNER JOIN tmateri m          ON m.id = dgm.materi_id
        LEFT  JOIN tkategori_materi k ON k.id = m.kategori_id
        WHERE dgm.detail_goal_id = ? ORDER BY m.nama
    ");

    $detailKategoris = [];
    foreach ($detailRows as $row) {
        $stmtMateris->execute([$row['detail_id']]);
        $materis = array_map(fn($m) => [
            'id' => (int) $m['id'],
            'nama' => $m['nama'],
            'deskripsi' => $m['deskripsi'],
            'local_file' => $m['local_file'],
            'link' => $m['link'],
            'sheet' => $m['sheet'],
            'kategori_id' => (int) $m['kategori_id'],
            'nama_kategori' => $m['nama_kategori']
        ], $stmtMateris->fetchAll(PDO::FETCH_ASSOC));

        $rid = (int) $row['rubrik_goal_id'];
        $detailKategoris[] = [
            'detail_id' => (int) $row['detail_id'],
            'rubrik_goal_id' => $rid,
            'kategori' => $row['kategori'],
            'catatan' => $row['catatan'],
            'sub_kategori' => $subByRubrik[$rid] ?? [],
            'materis' => $materis
        ];
    }

    $kategoriNilai = [];
    foreach ($semuaRubrik as $rubrik) {
        $rid = (int) $rubrik['rubrik_id'];
        if ($tipe === 'numerikal') {
            $stmtN = $pdo->prepare("
                SELECT AVG(dn.nilai) AS rata_rata, COUNT(dn.id) AS jumlah
                FROM tdetail_nilai dn
                INNER JOIN tpenilaian p ON p.id = dn.penilaian_id
                WHERE dn.rubrik_goal_id = ? AND p.goal_id = ? AND dn.nilai IS NOT NULL
            ");
            $stmtN->execute([$rid, $goalId]);
            $nd = $stmtN->fetch(PDO::FETCH_ASSOC);
            $rataRata = $nd['rata_rata'] ? round((float) $nd['rata_rata'], 2) : null;
            $nilaiMax = (float) $rubrik['nilai_max'];
            $persen = ($rataRata !== null && $nilaiMax > 0)
                ? round($rataRata / $nilaiMax * 100, 1) : null;
            $kategoriNilai[] = [
                'rubrik_id' => $rid,
                'kategori' => $rubrik['kategori'],
                'deskripsi' => $rubrik['deskripsi'],
                'nilai_max' => $nilaiMax,
                'sub_kategori' => $subByRubrik[$rid] ?? [],
                'rata_rata' => $rataRata,
                'rata_rata_persen' => $persen,
                'jumlah_penilaian' => (int) $nd['jumlah']
            ];
        } else {
            $stmtN = $pdo->prepare("
                SELECT dn.nilai_label, COUNT(*) AS jumlah
                FROM tdetail_nilai dn
                INNER JOIN tpenilaian p ON p.id = dn.penilaian_id
                WHERE dn.rubrik_goal_id = ? AND p.goal_id = ? AND dn.nilai_label IS NOT NULL
                GROUP BY dn.nilai_label
            ");
            $stmtN->execute([$rid, $goalId]);
            $distribusi = $stmtN->fetchAll(PDO::FETCH_ASSOC);
            $totalPenilaian = array_sum(array_column($distribusi, 'jumlah'));

            $encodeMap = [];
            foreach ($opsiNilai as $o) {
                $encodeMap[$o['label']] = (float) ($o['encode_value'] ?? 0);
            }
            // avg encode
            $totalEncodeSum = 0;
            $totalJumlahEnc = 0;
            foreach ($distribusi as $d) {
                $enc = $encodeMap[$d['nilai_label']] ?? 0;
                $totalEncodeSum += $enc * (int) $d['jumlah'];
                $totalJumlahEnc += (int) $d['jumlah'];
            }
            $rataEncode = $totalJumlahEnc > 0 ? $totalEncodeSum / $totalJumlahEnc : 0;

            $labelKumulatif = null;
            $minJarak = PHP_FLOAT_MAX;
            $maxUrutan = PHP_INT_MIN;
            $epsilon = 1e-9;
            foreach ($opsiNilai as $o) {
                $jarak = abs((float) $o['encode_value'] - $rataEncode);
                $urutan = (int) ($o['urutan_ke'] ?? 0);
                if (
                    $jarak < $minJarak - $epsilon ||
                    ($jarak < $minJarak + $epsilon && $urutan > $maxUrutan)
                ) {
                    $minJarak = $jarak;
                    $maxUrutan = $urutan;
                    $labelKumulatif = $o['label'];
                }
            }

            $totalOpsi = count($opsiNilai);
            $urutanLabel = null;
            foreach ($opsiNilai as $o) {
                if ($o['label'] === $labelKumulatif) {
                    $urutanLabel = (int) $o['urutan_ke'];
                    break;
                }
            }
            $rataEncodePersen = ($urutanLabel !== null && $totalOpsi > 0)
                ? round($urutanLabel / $totalOpsi * 100, 1)
                : 0;

            $kategoriNilai[] = [
                'rubrik_id' => $rid,
                'kategori' => $rubrik['kategori'],
                'deskripsi' => $rubrik['deskripsi'],
                'nilai_max' => 0,
                'sub_kategori' => $subByRubrik[$rid] ?? [],
                'label_terbanyak' => $labelKumulatif,
                'rata_encode' => round($rataEncode, 3),
                'rata_encode_persen' => $rataEncodePersen,
                'distribusi' => array_map(fn($d) => [
                    'label' => $d['nilai_label'],
                    'jumlah' => (int) $d['jumlah']
                ], $distribusi),
                'jumlah_penilaian' => (int) $totalPenilaian
            ];
        }
    }

    // grafik
    $stmtGrafik = $pdo->prepare("
        SELECT dn.rubrik_goal_id, dn.nilai, dn.nilai_label, dn.sumber,
               CASE
                 WHEN dn.sumber = 'kelas' THEN pl.tanggal
                 WHEN dn.sumber = 'tugas' THEN t.deadline
                 ELSE DATE(p.created_at)
               END AS tanggal
        FROM tdetail_nilai dn
        INNER JOIN tpenilaian p     ON p.id  = dn.penilaian_id
        LEFT  JOIN tpresensi_les pl ON pl.id = p.presensi_id
        LEFT  JOIN ttugas t         ON t.id  = p.tugas_id
        WHERE p.goal_id = ?
          AND (dn.nilai IS NOT NULL OR dn.nilai_label IS NOT NULL)
        ORDER BY dn.created_at ASC
    ");
    $stmtGrafik->execute([$goalId]);
    $grafikRaw = $stmtGrafik->fetchAll(PDO::FETCH_ASSOC);

    $grafikData = [];
    foreach ($semuaRubrik as $rubrik) {
        $rid = (int) $rubrik['rubrik_id'];
        $points = array_values(array_filter($grafikRaw, fn($r) => (int) $r['rubrik_goal_id'] === $rid));
        $grafikData[$rid] = [
            'kategori' => $rubrik['kategori'],
            'nilai_max' => (float) $rubrik['nilai_max'],
            'data_points' => array_map(function ($r) use ($tipe, $labelToEncodeVal) {
                $e = ['tanggal' => $r['tanggal'], 'sumber' => $r['sumber']];
                if ($tipe === 'numerikal') {
                    $e['nilai'] = $r['nilai'] !== null ? (float) $r['nilai'] : null;
                } else {
                    $e['nilai_label'] = $r['nilai_label'];
                    $e['nilai_encode'] = isset($labelToEncodeVal[$r['nilai_label']])
                        ? (float) $labelToEncodeVal[$r['nilai_label']]
                        : null;
                }
                return $e;
            }, $points)
        ];
    }

    // history penilaian
    $stmtP = $pdo->prepare("
        SELECT p.id AS penilaian_id, p.catatan, p.created_at,
               CASE
                 WHEN p.presensi_id IS NOT NULL THEN 'Kelas'
                 WHEN p.tugas_id    IS NOT NULL THEN 'Tugas'
                 ELSE 'Lainnya'
               END AS sumber,
               COALESCE(pl.tanggal, t.deadline, DATE(p.created_at)) AS tanggal
        FROM tpenilaian p
        LEFT JOIN tpresensi_les pl ON pl.id = p.presensi_id
        LEFT JOIN ttugas t         ON t.id  = p.tugas_id
        WHERE p.goal_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmtP->execute([$goalId]);
    $penilaianList = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    $stmtDN = $pdo->prepare("
    SELECT dn.id AS detail_nilai_id, dn.rubrik_goal_id,
           rg.kategori, rg.nilai_max, dn.nilai, dn.nilai_label, dn.sumber
    FROM tdetail_nilai dn
    INNER JOIN trubrik_goal rg ON rg.id = dn.rubrik_goal_id
    WHERE dn.penilaian_id = ? ORDER BY rg.urutan_ke
");

    $stmtDNSub = $pdo->prepare("
    SELECT dns.nilai, dns.nilai_label, rs.nama, rs.nilai_max, rs.urutan_ke
    FROM tdetail_nilai_sub dns
    INNER JOIN trubrik_subkategori rs ON rs.id = dns.rubrik_subkategori_id
    WHERE dns.detail_nilai_id = ?
    ORDER BY rs.urutan_ke
");

    $stmtCekNilai = $pdo->prepare("
        SELECT
            SUM(CASE WHEN dn.nilai IS NOT NULL THEN 1 ELSE 0 END) AS ada_nilai,
            SUM(CASE WHEN dn.nilai_label IS NOT NULL THEN 1 ELSE 0 END) AS ada_label,
            SUM(CASE WHEN dns.nilai IS NOT NULL THEN 1 ELSE 0 END) AS ada_sub_nilai,
            SUM(CASE WHEN dns.nilai_label IS NOT NULL THEN 1 ELSE 0 END) AS ada_sub_label
        FROM tdetail_nilai dn
        LEFT JOIN tdetail_nilai_sub dns ON dns.detail_nilai_id = dn.id
        WHERE dn.penilaian_id = ?
    ");

    $riwayat = [];
    $counter = 1;
    foreach ($penilaianList as $p) {
        $stmtCekNilai->execute([$p['penilaian_id']]);
        $cek = $stmtCekNilai->fetch(PDO::FETCH_ASSOC);
        if ($tipe === 'numerikal') {
            if ((int) ($cek['ada_nilai'] ?? 0) === 0 && (int) ($cek['ada_sub_nilai'] ?? 0) === 0)
                continue;
        } else {
            if ((int) ($cek['ada_label'] ?? 0) === 0 && (int) ($cek['ada_sub_label'] ?? 0) === 0)
                continue;
        }

        $stmtDN->execute([$p['penilaian_id']]);
        $details = $stmtDN->fetchAll(PDO::FETCH_ASSOC);
        $riwayat[] = [
            'nomor' => $counter++,
            'penilaian_id' => (int) $p['penilaian_id'],
            'sumber' => $p['sumber'],
            'tanggal' => fmtTgl($p['tanggal']),
            'tanggal_raw' => $p['tanggal'],
            'created_at' => $p['created_at'],
            'catatan' => $p['catatan'],
            'detail_nilai' => array_map(function ($dn) use ($stmtDNSub) {
                $stmtDNSub->execute([$dn['detail_nilai_id']]);
                $subNilai = $stmtDNSub->fetchAll(PDO::FETCH_ASSOC);
                return [
                    'detail_nilai_id' => (int) $dn['detail_nilai_id'],
                    'rubrik_goal_id' => (int) $dn['rubrik_goal_id'],
                    'kategori' => $dn['kategori'],
                    'nilai_max' => (float) $dn['nilai_max'],
                    'nilai' => $dn['nilai'] !== null ? (float) $dn['nilai'] : null,
                    'nilai_label' => $dn['nilai_label'],
                    'sumber' => ucfirst($dn['sumber']),
                    'sub_nilai' => array_map(fn($s) => [
                        'nama' => $s['nama'],
                        'nilai' => $s['nilai'] !== null ? (float) $s['nilai'] : null,
                        'nilai_label' => $s['nilai_label'] ?? null,
                        'nilai_max' => (float) $s['nilai_max'],
                        'urutan_ke' => (int) $s['urutan_ke']
                    ], $subNilai)
                ];
            }, $details)
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'goal_id' => (int) $goal['goal_id'],
            'murid_id' => $goal['murid_id'],
            'jenis_goal_id' => $jenisGoalId,
            'nama_jenis_goal' => $goal['nama_jenis_goal'],
            'deskripsi_jenis_goal' => $goal['deskripsi_jenis_goal'],
            'tipe_penilaian' => $tipe,
            'opsi_nilai' => array_map(fn($o) => [
                'label' => $o['label'],
                'urutan_ke' => (int) $o['urutan_ke'],
                'encode_value' => round((float) ($o['encode_value'] ?? 0), 3)
            ], $opsiNilai),
            'tanggal_target' => $goal['tanggal_target'],
            'tanggal_target_formatted' => fmtTgl($goal['tanggal_target']),
            'status' => $goal['status'],
            'catatan_umum' => $goal['catatan_umum'],
            'hasil_akhir' => $goal['hasil_akhir'],
            'nama_murid' => $goal['nama_murid'],
            'tanggal_lahir' => $goal['tanggal_lahir'] ? date('d F Y', strtotime($goal['tanggal_lahir'])) : '-',
            'alamat' => $goal['alamat'],
            'no_telp' => $goal['no_telp'],
            'email' => $goal['email'],
            'grade' => (int) $goal['grade'],
            'detail_kategoris' => $detailKategoris,
            'kategoris_nilai' => $kategoriNilai,
            'grafik_data' => $grafikData,
            'riwayat_penilaian' => $riwayat,
            'total_penilaian' => count($riwayat)
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}