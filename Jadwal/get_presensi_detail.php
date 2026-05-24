<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cors.php';

$data       = json_decode(file_get_contents("php://input"), true);
$presensiId = $data['presensi_id'] ?? null;

if (!$presensiId) {
    echo json_encode(['success' => false, 'message' => 'presensi_id wajib diisi']);
    exit;
}

try {
    $stmtP = $pdo->prepare("
        SELECT p.id, p.jadwal_id, p.jenis, p.status,
               p.waktu_presensi, p.kegiatan, p.catatan_guru, p.tanggal,
               m.id       AS murid_id,
               um.nama    AS nama_murid,
               ug.id      AS guru_user_id,
               ug.nama    AS nama_guru,
               DATE_FORMAT(s.jam_mulai,  '%H:%i') AS jam_mulai,
               DATE_FORMAT(s.jam_selesai,'%H:%i') AS jam_selesai
        FROM tpresensi_les p
        INNER JOIN tjadwal_les j  ON j.id  = p.jadwal_id
        INNER JOIN tslot_jadwal s ON s.id  = j.jadwal_slot_id
        INNER JOIN tmurid m       ON m.id  = j.murid_id
        INNER JOIN tuser um       ON um.id = m.user_id
        INNER JOIN tguru g        ON g.id  = j.guru_id
        INNER JOIN tuser ug       ON ug.id = g.user_id
        WHERE p.id = :id
    ");
    $stmtP->execute([':id' => $presensiId]);
    $presensi = $stmtP->fetch(PDO::FETCH_ASSOC);

    if (!$presensi) {
        echo json_encode(['success' => false, 'message' => 'Data presensi tidak ditemukan']);
        exit;
    }

    $sudahPresensi = !empty($presensi['waktu_presensi']);

    $penilaian = null;
    if ($sudahPresensi) {
        $stmtPN = $pdo->prepare("
            SELECT p.id AS penilaian_id, p.goal_id, p.catatan AS catatan_penilaian,
                   jg.tipe_penilaian,
                   g.nama AS nama_goal
            FROM tpenilaian p
            INNER JOIN tgoal g       ON g.id  = p.goal_id
            INNER JOIN tjenis_goal jg ON jg.id = g.jenis_goal_id
            WHERE p.presensi_id = :presensi_id
            LIMIT 1
        ");
        $stmtPN->execute([':presensi_id' => $presensiId]);
        $pRow = $stmtPN->fetch(PDO::FETCH_ASSOC);

        if ($pRow) {
            $stmtDN = $pdo->prepare("
                SELECT dn.id AS detail_nilai_id, dn.rubrik_goal_id,
                       dn.nilai, dn.nilai_label, dn.sumber,
                       rg.kategori, rg.deskripsi, rg.nilai_max
                FROM tdetail_nilai dn
                INNER JOIN trubrik_goal rg ON rg.id = dn.rubrik_goal_id
                WHERE dn.penilaian_id = :penilaian_id AND dn.sumber = 'kelas'
                ORDER BY rg.urutan_ke
            ");
            $stmtDN->execute([':penilaian_id' => $pRow['penilaian_id']]);
            $detailRows = $stmtDN->fetchAll(PDO::FETCH_ASSOC);

            $detailNilai = [];
            foreach ($detailRows as $dn) {
                $stmtSub = $pdo->prepare("
                    SELECT dns.rubrik_subkategori_id AS sub_id, dns.nilai,
                           rs.nama, rs.nilai_max AS sub_nilai_max
                    FROM tdetail_nilai_sub dns
                    INNER JOIN trubrik_subkategori rs ON rs.id = dns.rubrik_subkategori_id
                    WHERE dns.detail_nilai_id = :dn_id
                    ORDER BY rs.urutan_ke
                ");
                $stmtSub->execute([':dn_id' => $dn['detail_nilai_id']]);
                $subRows = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

                $detailNilai[] = [
                    'rubrik_goal_id' => (int)$dn['rubrik_goal_id'],
                    'kategori' => $dn['kategori'],
                    'deskripsi' => $dn['deskripsi'],
                    'nilai' => $dn['nilai'] !== null ? (float)$dn['nilai'] : null,
                    'nilai_label'  => $dn['nilai_label'],
                    'sub_nilai' => array_map(fn($s) => [
                        'sub_id' => (int)$s['sub_id'],
                        'nama' => $s['nama'],
                        'nilai_max' => (float)$s['sub_nilai_max'],
                        'nilai' => $s['nilai'] !== null ? (float)$s['nilai'] : null
                    ], $subRows)
                ];
            }

            $opsiNilai = [];
            if ($pRow['tipe_penilaian'] === 'kategorik') {
                $stmtOpsi = $pdo->prepare("
                    SELECT label, urutan_ke FROM tgoal_opsi_nilai
                    WHERE goal_id = ? ORDER BY urutan_ke
                ");
                $stmtOpsi->execute([$pRow['goal_id']]);
                $opsiNilai = $stmtOpsi->fetchAll(PDO::FETCH_ASSOC);
                if (empty($opsiNilai)) {
                    $stmtOpsiDef = $pdo->prepare("
                        SELECT label, urutan_ke FROM trubrik_opsi_nilai
                        WHERE jenis_goal_id = (
                            SELECT jenis_goal_id FROM tgoal WHERE id = ?
                        ) ORDER BY urutan_ke
                    ");
                    $stmtOpsiDef->execute([$pRow['goal_id']]);
                    $opsiNilai = $stmtOpsiDef->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            $penilaian = [
                'penilaian_id'      => (int)$pRow['penilaian_id'],
                'goal_id'           => (int)$pRow['goal_id'],
                'nama_goal'         => $pRow['nama_goal'],
                'tipe_penilaian'    => $pRow['tipe_penilaian'],
                'catatan_penilaian' => $pRow['catatan_penilaian'],
                'opsi_nilai'        => $opsiNilai,
                'detail_nilai'      => $detailNilai
            ];
        }
    }

    $tugasList = [];
    if ($sudahPresensi) {
        $stmtT = $pdo->prepare("
            SELECT t.id, t.judul, t.deskripsi_tugas AS deskripsi,
                   t.deadline, t.status, t.is_dinilai, t.goal_id,
                   g.nama AS nama_goal
            FROM ttugas t
            LEFT JOIN tgoal g ON g.id = t.goal_id
            WHERE t.jadwal_id = :jadwal_id
              AND DATE(t.created_at) = :tanggal
            ORDER BY t.created_at
        ");
        $stmtT->execute([
            ':jadwal_id' => $presensi['jadwal_id'],
            ':tanggal'   => $presensi['tanggal']
        ]);
        $tRows = $stmtT->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tRows as $t) {
            // Materi referensi per tugas
            $stmtTM = $pdo->prepare("
                SELECT m.id, m.nama, m.deskripsi
                FROM ttugas_materi tm
                INNER JOIN tmateri m ON m.id = tm.materi_id
                WHERE tm.tugas_id = ?
            ");
            $stmtTM->execute([$t['id']]);
            $materis = $stmtTM->fetchAll(PDO::FETCH_ASSOC);

            $tugasList[] = [
                'tugas_id'   => (int)$t['id'],
                'judul'      => $t['judul'],
                'deskripsi'  => $t['deskripsi'],
                'deadline'   => $t['deadline'],
                'status'     => $t['status'],
                'is_dinilai' => (bool)$t['is_dinilai'],
                'goal_id'    => $t['goal_id'] ? (int)$t['goal_id'] : null,
                'nama_goal'  => $t['nama_goal'],
                'materis'    => $materis
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'presensi_id'    => (int)$presensi['id'],
            'jadwal_id'      => (int)$presensi['jadwal_id'],
            'murid_id'       => $presensi['murid_id'],
            'nama_murid'     => $presensi['nama_murid'],
            'nama_guru'      => $presensi['nama_guru'],
            'tanggal'        => $presensi['tanggal'],
            'jam_mulai'      => $presensi['jam_mulai'],
            'jam_selesai'    => $presensi['jam_selesai'],
            'waktu_presensi' => $presensi['waktu_presensi'],
            'sudah_presensi' => $sudahPresensi,
            'kegiatan'       => $presensi['kegiatan'],
            'catatan_guru'   => $presensi['catatan_guru'],
            'penilaian'      => $penilaian,
            'tugas_list'     => $tugasList
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
