<?php
require_once __DIR__ . '/../config/database.php';

$created_at = date('Y-m-d H:i:s');

echo "=== MULAI INSERT DUMMY DATA ===\n\n";

// ============================================================================
// 1. INSERT tjenis_goal (Jenis Target/Goal)
// ============================================================================

echo "1. Membuat Jenis Goal...\n";

$dataJenisGoal = [
    [
        'nama' => 'ABRSM Grade 1',
        'deskripsi' => 'Associated Board of the Royal Schools of Music - Grade 1 Piano Examination'
    ],
    [
        'nama' => 'ABRSM Grade 2',
        'deskripsi' => 'Associated Board of the Royal Schools of Music - Grade 2 Piano Examination'
    ],
    [
        'nama' => 'ABRSM Grade 3',
        'deskripsi' => 'Associated Board of the Royal Schools of Music - Grade 3 Piano Examination'
    ],
    [
        'nama' => 'Recital Performance',
        'deskripsi' => 'Target untuk persiapan recital atau konser'
    ],
    [
        'nama' => 'Competition Preparation',
        'deskripsi' => 'Persiapan untuk kompetisi piano'
    ]
];

$sqlJenisGoal = "INSERT INTO tjenis_goal (nama, deskripsi, created_at) 
                 VALUES (:nama, :deskripsi, :created_at)";
$stmtJenisGoal = $pdo->prepare($sqlJenisGoal);

$jenis_goal_ids = [];
foreach ($dataJenisGoal as $jenis) {
    try {
        $stmtJenisGoal->execute([
            ':nama' => $jenis['nama'],
            ':deskripsi' => $jenis['deskripsi'],
            ':created_at' => $created_at
        ]);
        $jenis_goal_ids[$jenis['nama']] = $pdo->lastInsertId();
        echo "   ✓ Jenis Goal: {$jenis['nama']}\n";
    } catch (PDOException $e) {
        echo "   ✗ Error jenis goal: " . $e->getMessage() . "\n";
    }
}

echo "   Total: " . count($jenis_goal_ids) . " jenis goal dibuat\n\n";

// ============================================================================
// 2. INSERT trubrik_goal (Rubrik/Kategori untuk setiap Jenis Goal)
// ============================================================================

echo "2. Membuat Rubrik Goal...\n";

$dataRubrikGoal = [
    // ABRSM Grade 1
    [
        'jenis_goal_nama' => 'ABRSM Grade 1',
        'kategoris' => [
            'Scales and Arpeggios',
            'Piece 1',
            'Piece 2',
            'Piece 3',
            'Sight Reading',
            'Aural Tests'
        ]
    ],
    // ABRSM Grade 2
    [
        'jenis_goal_nama' => 'ABRSM Grade 2',
        'kategoris' => [
            'Scales and Arpeggios',
            'Piece 1',
            'Piece 2',
            'Piece 3',
            'Sight Reading',
            'Aural Tests'
        ]
    ],
    // ABRSM Grade 3
    [
        'jenis_goal_nama' => 'ABRSM Grade 3',
        'kategoris' => [
            'Scales and Arpeggios',
            'Piece 1',
            'Piece 2',
            'Piece 3',
            'Sight Reading',
            'Aural Tests'
        ]
    ],
    // Recital Performance
    [
        'jenis_goal_nama' => 'Recital Performance',
        'kategoris' => [
            'Opening Piece',
            'Classical Piece',
            'Romantic Piece',
            'Contemporary Piece',
            'Stage Presence',
            'Overall Performance'
        ]
    ],
    // Competition Preparation
    [
        'jenis_goal_nama' => 'Competition Preparation',
        'kategoris' => [
            'Required Piece 1',
            'Required Piece 2',
            'Own Choice Piece',
            'Technical Skills',
            'Interpretation',
            'Performance Quality'
        ]
    ]
];

$sqlRubrikGoal = "INSERT INTO trubrik_goal (jenis_goal_id, kategori, urutan_ke, created_at) 
                  VALUES (:jenis_goal_id, :kategori, :urutan_ke, :created_at)";
$stmtRubrikGoal = $pdo->prepare($sqlRubrikGoal);

$total_rubrik = 0;
foreach ($dataRubrikGoal as $rubrik) {
    $jenis_goal_nama = $rubrik['jenis_goal_nama'];
    $jenis_goal_id = $jenis_goal_ids[$jenis_goal_nama] ?? null;
    
    if ($jenis_goal_id) {
        $urutan = 1;
        foreach ($rubrik['kategoris'] as $kategori) {
            try {
                $stmtRubrikGoal->execute([
                    ':jenis_goal_id' => $jenis_goal_id,
                    ':kategori' => $kategori,
                    ':urutan_ke' => $urutan,
                    ':created_at' => $created_at
                ]);
                echo "   ✓ Rubrik: {$jenis_goal_nama} - {$kategori}\n";
                $urutan++;
                $total_rubrik++;
            } catch (PDOException $e) {
                echo "   ✗ Error rubrik: " . $e->getMessage() . "\n";
            }
        }
    }
}

echo "   Total: {$total_rubrik} rubrik dibuat\n\n";

// ============================================================================
// 3. INSERT tgoal (Assign beberapa goal ke murid)
// ============================================================================

echo "3. Membuat Goal untuk Murid...\n";

$dataGoal = [
    [
        'murid_id' => 'M0001',
        'jenis_goal_nama' => 'ABRSM Grade 2',
        'catatan_umum' => 'Target ujian semester depan',
        'tanggal_target' => '2026-06-15',
        'status' => 'berjalan'
    ],
    [
        'murid_id' => 'M0002',
        'jenis_goal_nama' => 'ABRSM Grade 1',
        'catatan_umum' => 'Persiapan ujian pertama',
        'tanggal_target' => '2026-05-20',
        'status' => 'berjalan'
    ],
    [
        'murid_id' => 'M0003',
        'jenis_goal_nama' => 'ABRSM Grade 3',
        'catatan_umum' => null,
        'tanggal_target' => '2026-07-10',
        'status' => 'berjalan'
    ],
    [
        'murid_id' => 'M0004',
        'jenis_goal_nama' => 'Recital Performance',
        'catatan_umum' => 'Persiapan recital akhir tahun',
        'tanggal_target' => '2026-12-20',
        'status' => 'berjalan'
    ],
    [
        'murid_id' => 'M0007',
        'jenis_goal_nama' => 'Competition Preparation',
        'catatan_umum' => 'Kompetisi nasional',
        'tanggal_target' => '2026-08-15',
        'status' => 'berjalan'
    ]
];

$sqlGoal = "INSERT INTO tgoal (murid_id, jenis_goal_id, catatan_umum, tanggal_target, status, created_at) 
            VALUES (:murid_id, :jenis_goal_id, :catatan_umum, :tanggal_target, :status, :created_at)";
$stmtGoal = $pdo->prepare($sqlGoal);

$goal_ids = [];
foreach ($dataGoal as $goal) {
    $jenis_goal_id = $jenis_goal_ids[$goal['jenis_goal_nama']] ?? null;
    
    if ($jenis_goal_id) {
        try {
            $stmtGoal->execute([
                ':murid_id' => $goal['murid_id'],
                ':jenis_goal_id' => $jenis_goal_id,
                ':catatan_umum' => $goal['catatan_umum'],
                ':tanggal_target' => $goal['tanggal_target'],
                ':status' => $goal['status'],
                ':created_at' => $created_at
            ]);
            
            $goal_id = $pdo->lastInsertId();
            $goal_ids[] = $goal_id;
            echo "   ✓ Goal: {$goal['murid_id']} - {$goal['jenis_goal_nama']}\n";
        } catch (PDOException $e) {
            echo "   ✗ Error goal: " . $e->getMessage() . "\n";
        }
    }
}

echo "   Total: " . count($goal_ids) . " goal dibuat\n\n";

// ============================================================================
// 4. INSERT tdetail_goal (Detail kategori untuk beberapa goal)
// ============================================================================

echo "4. Membuat Detail Goal...\n";

// Contoh detail untuk M0001 - ABRSM Grade 2
$sqlGetRubrik = "SELECT r.id, r.kategori 
                 FROM trubrik_goal r
                 INNER JOIN tgoal g ON g.jenis_goal_id = r.jenis_goal_id
                 WHERE g.murid_id = :murid_id
                 ORDER BY r.urutan_ke";

$dataDetailGoal = [
    [
        'murid_id' => 'M0001',
        'details' => [
            ['kategori' => 'Scales and Arpeggios', 'catatan' => 'C Major, G Major, D Major'],
            ['kategori' => 'Piece 1', 'catatan' => 'Sonatina in C - Clementi'],
            ['kategori' => 'Piece 2', 'catatan' => 'Minuet in G - Bach'],
            ['kategori' => 'Piece 3', 'catatan' => 'The Entertainer - Joplin']
        ]
    ],
    [
        'murid_id' => 'M0002',
        'details' => [
            ['kategori' => 'Scales and Arpeggios', 'catatan' => 'C Major hands separately'],
            ['kategori' => 'Piece 1', 'catatan' => 'Twinkle Twinkle Variations'],
            ['kategori' => 'Piece 2', 'catatan' => 'Ode to Joy - Beethoven']
        ]
    ]
];

$sqlDetailGoal = "INSERT INTO tdetail_goal (goal_id, rubrik_goal_id, catatan, created_at) 
                  VALUES (:goal_id, :rubrik_goal_id, :catatan, :created_at)";
$stmtDetailGoal = $pdo->prepare($sqlDetailGoal);

$total_detail = 0;
foreach ($dataDetailGoal as $detailData) {
    // Get goal_id untuk murid ini
    $sqlGetGoal = "SELECT id FROM tgoal WHERE murid_id = :murid_id ORDER BY created_at DESC LIMIT 1";
    $stmtGetGoal = $pdo->prepare($sqlGetGoal);
    $stmtGetGoal->execute([':murid_id' => $detailData['murid_id']]);
    $goal_id = $stmtGetGoal->fetchColumn();
    
    if ($goal_id) {
        // Get rubrik untuk goal ini
        $stmtGetRubrik = $pdo->prepare($sqlGetRubrik);
        $stmtGetRubrik->execute([':murid_id' => $detailData['murid_id']]);
        $rubrik_list = $stmtGetRubrik->fetchAll(PDO::FETCH_ASSOC);
        
        // Map kategori ke rubrik_id
        $rubrik_map = [];
        foreach ($rubrik_list as $r) {
            $rubrik_map[$r['kategori']] = $r['id'];
        }
        
        // Insert detail
        foreach ($detailData['details'] as $detail) {
            $rubrik_id = $rubrik_map[$detail['kategori']] ?? null;
            
            if ($rubrik_id) {
                try {
                    $stmtDetailGoal->execute([
                        ':goal_id' => $goal_id,
                        ':rubrik_goal_id' => $rubrik_id,
                        ':catatan' => $detail['catatan'],
                        ':created_at' => $created_at
                    ]);
                    echo "   ✓ Detail: {$detailData['murid_id']} - {$detail['kategori']}\n";
                    $total_detail++;
                } catch (PDOException $e) {
                    echo "   ✗ Error detail goal: " . $e->getMessage() . "\n";
                }
            }
        }
    }
}

echo "   Total: {$total_detail} detail goal dibuat\n\n";

// ============================================================================
// 5. INSERT tSlot_jadwal (Senin-Jumat, 09:00-17:00, 1 jam per slot)
// ============================================================================

echo "5. Membuat Slot Jadwal...\n";

$dataSlots = [];
$hari_list = ['1', '2', '3', '4', '5']; // Senin - Jumat
$jam_mulai_list = ['09:00:00', '10:00:00', '11:00:00', '12:00:00', '13:00:00', '14:00:00', '15:00:00', '16:00:00'];

foreach ($hari_list as $hari) {
    foreach ($jam_mulai_list as $jam_mulai) {
        $time = new DateTime($jam_mulai);
        $time->add(new DateInterval('PT1H')); // Tambah 1 jam
        $jam_selesai = $time->format('H:i:s');
        
        $dataSlots[] = [
            'hari' => $hari,
            'jam_mulai' => $jam_mulai,
            'jam_selesai' => $jam_selesai
        ];
    }
}

$sqlSlot = "INSERT INTO tslot_jadwal (hari, jam_mulai, jam_selesai, created_at) 
            VALUES (:hari, :jam_mulai, :jam_selesai, :created_at)";
$stmtSlot = $pdo->prepare($sqlSlot);

$slot_ids = [];
foreach ($dataSlots as $slot) {
    try {
        $stmtSlot->execute([
            ':hari' => $slot['hari'],
            ':jam_mulai' => $slot['jam_mulai'],
            ':jam_selesai' => $slot['jam_selesai'],
            ':created_at' => $created_at
        ]);
        $slot_ids[] = $pdo->lastInsertId();
        
        $hari_name = ['', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'][$slot['hari']];
        echo "   ✓ Slot {$hari_name} {$slot['jam_mulai']}-{$slot['jam_selesai']}\n";
    } catch (PDOException $e) {
        echo "   ✗ Error slot: " . $e->getMessage() . "\n";
    }
}

echo "   Total: " . count($slot_ids) . " slots dibuat\n\n";

// ============================================================================
// 6. INSERT tKetersediaan_guru (Random tapi lebih banyak aktif)
// ============================================================================

echo "6. Membuat Ketersediaan Guru...\n";

$dataGuru = ['G0001', 'G0002', 'G0003'];
$dataKetersediaan = [];

foreach ($dataGuru as $guru_id) {
    // Setiap guru available di 70-80% dari total slots (random)
    $total_slots = count($slot_ids);
    $num_available = rand(round($total_slots * 0.7), round($total_slots * 0.8));
    
    // Shuffle untuk random selection
    $random_slots = $slot_ids;
    shuffle($random_slots);
    $selected_slots = array_slice($random_slots, 0, $num_available);
    
    foreach ($slot_ids as $slot_id) {
        $status_aktif = in_array($slot_id, $selected_slots) ? 1 : 0;
        $dataKetersediaan[] = [
            'guru_id' => $guru_id,
            'slot_jadwal_id' => $slot_id,
            'status_aktif' => $status_aktif
        ];
    }
}

$sqlKetersediaan = "INSERT INTO tketersediaan_guru (guru_id, slot_jadwal_id, status_aktif, created_at) 
                    VALUES (:guru_id, :slot_jadwal_id, :status_aktif, :created_at)";
$stmtKetersediaan = $pdo->prepare($sqlKetersediaan);

$count_aktif = 0;
foreach ($dataKetersediaan as $k) {
    try {
        $stmtKetersediaan->execute([
            ':guru_id' => $k['guru_id'],
            ':slot_jadwal_id' => $k['slot_jadwal_id'],
            ':status_aktif' => $k['status_aktif'],
            ':created_at' => $created_at
        ]);
        if ($k['status_aktif']) $count_aktif++;
    } catch (PDOException $e) {
        echo "   ✗ Error ketersediaan: " . $e->getMessage() . "\n";
    }
}

echo "   ✓ Total ketersediaan: " . count($dataKetersediaan) . " records\n";
echo "   ✓ Aktif: {$count_aktif}, Tidak Aktif: " . (count($dataKetersediaan) - $count_aktif) . "\n\n";

// ============================================================================
// 7. INSERT tJadwal_les (1 murid = 1 guru sesuai grade)
// ============================================================================

echo "7. Membuat Jadwal Les...\n";

$dataMuridGrade = [
    ['murid_id' => 'M0001', 'grade' => 2],  // → G0001
    ['murid_id' => 'M0002', 'grade' => 1],  // → G0001
    ['murid_id' => 'M0003', 'grade' => 3],  // → G0001
    ['murid_id' => 'M0004', 'grade' => 4],  // → G0002
    ['murid_id' => 'M0005', 'grade' => 5],  // → G0002
    ['murid_id' => 'M0006', 'grade' => 6],  // → G0002
    ['murid_id' => 'M0007', 'grade' => 7],  // → G0003
    ['murid_id' => 'M0008', 'grade' => 8],  // → G0003
    ['murid_id' => 'M0009', 'grade' => 3],  // → G0001
    ['murid_id' => 'M0010', 'grade' => 5],  // → G0002
];

$dataJadwalLes = [];

foreach ($dataMuridGrade as $murid) {
    // Tentukan guru berdasarkan grade
    if ($murid['grade'] >= 1 && $murid['grade'] <= 3) {
        $guru_id = 'G0001';
    } elseif ($murid['grade'] >= 4 && $murid['grade'] <= 6) {
        $guru_id = 'G0002';
    } else {
        $guru_id = 'G0003';
    }
    
    // Get available slots untuk guru ini
    $sqlGetSlots = "SELECT slot_jadwal_id FROM tketersediaan_guru 
                    WHERE guru_id = :guru_id AND status_aktif = 1";
    $stmtGetSlots = $pdo->prepare($sqlGetSlots);
    $stmtGetSlots->execute([':guru_id' => $guru_id]);
    $available_slots = $stmtGetSlots->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($available_slots) > 0) {
        // Pilih slot random untuk murid ini
        $random_slot = $available_slots[array_rand($available_slots)];
        
        $dataJadwalLes[] = [
            'guru_id' => $guru_id,
            'murid_id' => $murid['murid_id'],
            'jadwal_slot_id' => $random_slot,
            'status_aktif' => 1
        ];
    }
}

$sqlJadwalLes = "INSERT INTO tjadwal_les (status_aktif, guru_id, jadwal_slot_id, murid_id, created_at) 
                 VALUES (:status_aktif, :guru_id, :jadwal_slot_id, :murid_id, :created_at)";
$stmtJadwalLes = $pdo->prepare($sqlJadwalLes);

$jadwal_ids = [];
foreach ($dataJadwalLes as $jadwal) {
    try {
        $stmtJadwalLes->execute([
            ':status_aktif' => $jadwal['status_aktif'],
            ':guru_id' => $jadwal['guru_id'],
            ':jadwal_slot_id' => $jadwal['jadwal_slot_id'],
            ':murid_id' => $jadwal['murid_id'],
            ':created_at' => $created_at
        ]);
        
        $jadwal_id = $pdo->lastInsertId();
        $jadwal_ids[$jadwal['murid_id']] = [
            'jadwal_id' => $jadwal_id,
            'guru_id' => $jadwal['guru_id'],
            'slot_id' => $jadwal['jadwal_slot_id']
        ];
        
        echo "   ✓ Jadwal {$jadwal['murid_id']} dengan {$jadwal['guru_id']}\n";
    } catch (PDOException $e) {
        echo "   ✗ Error jadwal: " . $e->getMessage() . "\n";
    }
}

echo "   Total: " . count($jadwal_ids) . " jadwal les dibuat\n\n";

// ============================================================================
// 8. INSERT tPresensi_les (3-13 Februari 2026, sesuai jadwal les)
// ============================================================================

echo "8. Membuat Presensi Les (3-13 Feb 2026)...\n";

// Get info slot untuk setiap jadwal
$sqlSlotInfo = "SELECT s.id, s.hari FROM tslot_jadwal s";
$stmtSlotInfo = $pdo->query($sqlSlotInfo);
$slot_info = [];
while ($row = $stmtSlotInfo->fetch(PDO::FETCH_ASSOC)) {
    $slot_info[$row['id']] = $row['hari'];
}

$dataPresensi = [];

// Loop tanggal 3-13 Februari 2026
$start_date = new DateTime('2026-02-03');
$end_date = new DateTime('2026-02-13');

while ($start_date <= $end_date) {
    $tanggal = $start_date->format('Y-m-d');
    $hari_numerik = $start_date->format('N'); // 1=Senin, 7=Minggu
    
    // Skip weekend
    if ($hari_numerik >= 6) {
        $start_date->add(new DateInterval('P1D'));
        continue;
    }
    
    // Buat presensi untuk setiap jadwal yang hari-nya cocok
    foreach ($jadwal_ids as $murid_id => $info) {
        $slot_hari = $slot_info[$info['slot_id']];
        
        // Jika hari slot cocok dengan hari tanggal ini
        if ($slot_hari == $hari_numerik) {
            $dataPresensi[] = [
                'jadwal_id' => $info['jadwal_id'],
                'tanggal' => $tanggal,
                'status_aktif' => 1,
                'waktu_presensi' => null // Kosongkan dulu
            ];
        }
    }
    
    $start_date->add(new DateInterval('P1D'));
}

$sqlPresensi = "INSERT INTO tpresensi_les (jadwal_id, pengganti_id, tanggal, status_aktif, waktu_presensi, created_at) 
                VALUES (:jadwal_id, NULL, :tanggal, :status_aktif, :waktu_presensi, :created_at)";
$stmtPresensi = $pdo->prepare($sqlPresensi);

$presensi_ids = [];
foreach ($dataPresensi as $presensi) {
    try {
        $stmtPresensi->execute([
            ':jadwal_id' => $presensi['jadwal_id'],
            ':tanggal' => $presensi['tanggal'],
            ':status_aktif' => $presensi['status_aktif'],
            ':waktu_presensi' => $presensi['waktu_presensi'],
            ':created_at' => $created_at
        ]);
        
        $presensi_id = $pdo->lastInsertId();
        $presensi_ids[] = [
            'id' => $presensi_id,
            'tanggal' => $presensi['tanggal'],
            'jadwal_id' => $presensi['jadwal_id']
        ];
        
    } catch (PDOException $e) {
        echo "   ✗ Error presensi: " . $e->getMessage() . "\n";
    }
}

echo "   ✓ Total presensi: " . count($presensi_ids) . " records\n\n";

// ============================================================================
// 9. INSERT tTugas (2 tugas untuk 2 murid berbeda, deadline 15 Feb 2026)
// ============================================================================

echo "9. Membuat Tugas (2 tugas)...\n";

// Filter presensi tanggal 3-4 Februari
$presensi_feb_3_4 = array_filter($presensi_ids, function($p) {
    return $p['tanggal'] >= '2026-02-03' && $p['tanggal'] <= '2026-02-04';
});

// Ambil 2 presensi berbeda untuk 2 murid
$selected_presensi = array_slice($presensi_feb_3_4, 0, 2);

$dataTugas = [
    [
        'presensi_id' => $selected_presensi[0]['id'] ?? null,
        'judul' => 'Latihan Scales C Major',
        'deskripsi_tugas' => 'Mainkan scales C Major dengan tempo 60 bpm, ascending dan descending. Rekam dan upload video latihan.',
        'deadline' => '2026-02-15 23:59:59',
        'status' => 'belum_dikerjakan',
        'is_dinilai' => 0
    ],
    [
        'presensi_id' => $selected_presensi[1]['id'] ?? null,
        'judul' => 'Hafalan Lagu Twinkle Twinkle Little Star',
        'deskripsi_tugas' => 'Hafalkan dan mainkan lagu Twinkle Twinkle Little Star tanpa melihat partitur. Upload rekaman.',
        'deadline' => '2026-02-15 23:59:59',
        'status' => 'belum_dikerjakan',
        'is_dinilai' => 0
    ]
];

$sqlTugas = "INSERT INTO ttugas (presensi_id, judul, deskripsi_tugas, deadline, status, is_dinilai, goal_id, created_at) 
             VALUES (:presensi_id, :judul, :deskripsi_tugas, :deadline, :status, :is_dinilai, NULL, :created_at)";
$stmtTugas = $pdo->prepare($sqlTugas);

foreach ($dataTugas as $tugas) {
    if ($tugas['presensi_id']) {
        try {
            $stmtTugas->execute([
                ':presensi_id' => $tugas['presensi_id'],
                ':judul' => $tugas['judul'],
                ':deskripsi_tugas' => $tugas['deskripsi_tugas'],
                ':deadline' => $tugas['deadline'],
                ':status' => $tugas['status'],
                ':is_dinilai' => $tugas['is_dinilai'],
                ':created_at' => $created_at
            ]);
            
            echo "   ✓ Tugas: {$tugas['judul']}\n";
        } catch (PDOException $e) {
            echo "   ✗ Error tugas: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n=== SELESAI ===\n";
echo "✓ Jenis Goal: " . count($jenis_goal_ids) . " records\n";
echo "✓ Rubrik Goal: {$total_rubrik} records\n";
echo "✓ Goal: " . count($goal_ids) . " records\n";
echo "✓ Detail Goal: {$total_detail} records\n";
echo "✓ Slot Jadwal: " . count($slot_ids) . " records\n";
echo "✓ Ketersediaan Guru: " . count($dataKetersediaan) . " records\n";
echo "✓ Jadwal Les: " . count($jadwal_ids) . " records\n";
echo "✓ Presensi Les: " . count($presensi_ids) . " records\n";
echo "✓ Tugas: 2 records\n";