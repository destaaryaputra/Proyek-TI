<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/database.php';

$pdo->beginTransaction();

try {
    $pdo->exec(
        "INSERT IGNORE INTO tabel_jadwal (id_jadwal, id_guru, id_kelas, id_mapel, hari, jam) VALUES
        (6, 1, 1, 3, 'Rabu', '10:45-12:15'),
        (7, 1, 1, 4, 'Jumat', '09:45-11:15'),
        (8, 2, 2, 1, 'Senin', '09:15-10:45'),
        (9, 3, 3, 9, 'Kamis', '10:45-12:15'),
        (10, 4, 4, 6, 'Selasa', '09:15-10:45')"
    );

    $pdo->exec(
        "INSERT IGNORE INTO tabel_kehadiran (id_siswa, id_guru, id_mapel, tanggal, status) VALUES
        (1, 1, 1, CURDATE(), 'hadir'),
        (8, 1, 1, CURDATE(), 'hadir'),
        (15, 1, 1, CURDATE(), 'sakit'),
        (22, 1, 1, CURDATE(), 'hadir'),
        (29, 1, 1, CURDATE(), 'izin'),
        (36, 1, 1, CURDATE(), 'hadir'),
        (43, 1, 1, CURDATE(), 'alpa'),
        (50, 1, 1, CURDATE(), 'hadir'),
        (57, 1, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'hadir'),
        (64, 1, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'hadir'),
        (71, 1, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'izin'),
        (78, 1, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'hadir'),
        (85, 1, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'sakit'),
        (92, 1, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'hadir'),
        (99, 1, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'hadir'),
        (106, 1, 4, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'hadir'),
        (113, 1, 4, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'hadir'),
        (120, 1, 4, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'alpa'),
        (127, 1, 4, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'hadir'),
        (134, 1, 4, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'izin'),
        (141, 1, 4, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'hadir'),
        (148, 1, 4, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'hadir'),
        (2, 2, 2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'hadir'),
        (9, 2, 2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'hadir'),
        (16, 2, 2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'sakit'),
        (23, 2, 2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'hadir'),
        (30, 2, 2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'alpa'),
        (3, 3, 3, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'hadir'),
        (10, 3, 3, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'hadir'),
        (17, 3, 3, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'izin'),
        (24, 3, 3, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'hadir'),
        (31, 3, 3, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'hadir')"
    );

    $pdo->exec(
        "INSERT INTO tabel_nilai (id_siswa, id_mapel, id_guru, jenis_penilaian, skor, periode) VALUES
        (1, 1, 1, 'kuis', 82.00, '2026 Genap'),
        (1, 1, 1, 'uts', 87.50, '2026 Genap'),
        (1, 1, 1, 'uas', 91.00, '2026 Genap'),
        (8, 1, 1, 'kuis', 88.00, '2026 Genap'),
        (8, 1, 1, 'uts', 90.00, '2026 Genap'),
        (8, 1, 1, 'uas', 93.50, '2026 Genap'),
        (15, 1, 1, 'kuis', 72.00, '2026 Genap'),
        (15, 1, 1, 'uts', 78.00, '2026 Genap'),
        (15, 1, 1, 'uas', 80.00, '2026 Genap'),
        (22, 1, 1, 'kuis', 86.00, '2026 Genap'),
        (22, 1, 1, 'uts', 84.50, '2026 Genap'),
        (22, 1, 1, 'uas', 89.00, '2026 Genap'),
        (29, 1, 1, 'kuis', 91.00, '2026 Genap'),
        (29, 1, 1, 'uts', 92.00, '2026 Genap'),
        (29, 1, 1, 'uas', 94.00, '2026 Genap'),
        (1, 3, 1, 'tugas', 84.00, '2026 Genap'),
        (1, 3, 1, 'kuis', 81.00, '2026 Genap'),
        (1, 3, 1, 'uts', 86.00, '2026 Genap'),
        (1, 3, 1, 'uas', 88.00, '2026 Genap'),
        (2, 2, 2, 'tugas', 79.00, '2026 Genap'),
        (2, 2, 2, 'kuis', 83.00, '2026 Genap'),
        (2, 2, 2, 'uts', 85.00, '2026 Genap'),
        (2, 2, 2, 'uas', 86.50, '2026 Genap'),
        (3, 9, 3, 'tugas', 77.00, '2026 Genap'),
        (3, 9, 3, 'kuis', 80.00, '2026 Genap'),
        (3, 9, 3, 'uts', 82.00, '2026 Genap'),
        (3, 9, 3, 'uas', 84.00, '2026 Genap')
        ON DUPLICATE KEY UPDATE skor = VALUES(skor), id_guru = VALUES(id_guru)"
    );

    $pdo->exec(
        "DELETE FROM tabel_notifikasi
         WHERE pesan IN (
            'Nilai Matematika terbaru sudah tersedia. Silakan cek laporan akademik Anda.',
            'Absensi Bahasa Inggris minggu ini sudah direkap oleh guru.',
            'Peringatan: terdapat catatan ALPA pada pembelajaran Fisika.',
            'Monitoring demo: data akademik dan grafik dashboard sudah siap untuk presentasi.'
         )"
    );

    $pdo->exec(
        "INSERT INTO tabel_notifikasi (id_user, pesan, tanggal, dibaca) VALUES
        (32, 'Nilai Matematika terbaru sudah tersedia. Silakan cek laporan akademik Anda.', CURDATE(), FALSE),
        (32, 'Absensi Bahasa Inggris minggu ini sudah direkap oleh guru.', DATE_SUB(CURDATE(), INTERVAL 1 DAY), TRUE),
        (74, 'Peringatan: terdapat catatan ALPA pada pembelajaran Fisika.', DATE_SUB(CURDATE(), INTERVAL 2 DAY), FALSE),
        (1, 'Monitoring demo: data akademik dan grafik dashboard sudah siap untuk presentasi.', CURDATE(), FALSE)"
    );

    $pdo->commit();
    echo "Data demo presentasi berhasil disinkronkan.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Gagal sinkron data demo: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
