<?php

declare(strict_types=1);

// Dashboard role-based yang menampilkan ringkasan sesuai jenis akun.
require_once __DIR__ . '/../app/bootstrap.php';

require_login();

$user = current_user();
$role = $user['role'];

if ($role === 'guru') {
    $stmt = $pdo->prepare('SELECT id_guru, nama, jenis_kelamin FROM tabel_guru WHERE id_user = ? LIMIT 1');
    $stmt->execute([$user['id_user']]);
    $guru = $stmt->fetch();
}

if ($role === 'siswa') {
    $stmt = $pdo->prepare('SELECT s.id_siswa, s.id_kelas, s.nama, s.nis, k.nama_kelas FROM tabel_siswa s LEFT JOIN tabel_kelas k ON k.id_kelas = s.id_kelas WHERE s.id_user = ? LIMIT 1');
    $stmt->execute([$user['id_user']]);
    $siswa = $stmt->fetch();
}

if ($role === 'admin') {
    $bodyClass = 'dashboard-admin-page';
}

$title = 'Dashboard';
include LAYOUT_PATH . '/header.php';
?>
<div class="card page-hero">
    <h2>Dashboard <?= e(ucfirst($role)) ?></h2>
    <p class="page-lead">Peran aktif: <span class="badge"><?= e(strtoupper($role)) ?></span></p>
    <?php if ($role === 'siswa' && isset($siswa)): ?>
        <div class="siswa-greeting-info">
            <span>NIS: <?= e($siswa['nis'] ?? '-') ?></span>
            <span>Kelas: <?= e($siswa['nama_kelas'] ?? '-') ?></span>
        </div>
    <?php endif; ?>
</div>

<div class="content-panels">

<?php if ($role === 'admin'): ?>
    <?php
    $adminStatRow = $pdo->query(
        'SELECT
            (SELECT COUNT(*) FROM tabel_users) AS total_pengguna,
            (SELECT COUNT(*) FROM tabel_guru) AS total_guru,
            (SELECT COUNT(*) FROM tabel_siswa) AS total_siswa,
            (SELECT COUNT(*) FROM tabel_kelas) AS total_kelas,
            (SELECT COUNT(*) FROM tabel_mapel) AS total_mapel,
            (SELECT COUNT(*) FROM tabel_jadwal) AS total_jadwal'
    )->fetch() ?: [];

    $roleRows = $pdo->query("SELECT role, COUNT(*) AS total FROM tabel_users GROUP BY role ORDER BY FIELD(role, 'admin', 'guru', 'siswa')")->fetchAll();
    $roleCounts = ['admin' => 0, 'guru' => 0, 'siswa' => 0];
    foreach ($roleRows as $row) {
        $roleCounts[(string) $row['role']] = (int) $row['total'];
    }
    $totalRoleCount = max(1, array_sum($roleCounts));

    $kelasRows = $pdo->query(
        'SELECT k.nama_kelas, COUNT(s.id_siswa) AS total_siswa
         FROM tabel_kelas k
         LEFT JOIN tabel_siswa s ON s.id_kelas = k.id_kelas
         GROUP BY k.id_kelas, k.nama_kelas, k.tingkat
         ORDER BY CAST(k.tingkat AS UNSIGNED), k.nama_kelas'
    )->fetchAll();
    $maxKelasCount = max(1, ...array_map(static fn ($row) => (int) $row['total_siswa'], $kelasRows ?: [['total_siswa' => 0]]));

    $attendanceRows = $pdo->query(
        "SELECT status, COUNT(*) AS total
         FROM tabel_kehadiran
         GROUP BY status
         ORDER BY FIELD(status, 'hadir', 'izin', 'sakit', 'alpa')"
    )->fetchAll();
    $attendanceCounts = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0];
    foreach ($attendanceRows as $row) {
        $attendanceCounts[(string) $row['status']] = (int) $row['total'];
    }
    $attendanceTotal = max(1, array_sum($attendanceCounts));

    $trendStmt = $pdo->prepare(
        'SELECT tanggal, COUNT(*) AS total
         FROM tabel_kehadiran
         WHERE tanggal BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
         GROUP BY tanggal'
    );
    $trendStmt->execute();
    $trendRaw = [];
    foreach ($trendStmt->fetchAll() as $row) {
        $trendRaw[(string) $row['tanggal']] = (int) $row['total'];
    }
    $attendanceTrend = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime('-' . $i . ' days'));
        $attendanceTrend[] = [
            'label' => date('d/m', strtotime($date)),
            'value' => $trendRaw[$date] ?? 0,
        ];
    }
    $maxTrendCount = max(1, ...array_column($attendanceTrend, 'value'));

    $scoreRows = $pdo->query(
        "SELECT jenis_penilaian, AVG(skor) AS rata_rata, COUNT(*) AS total
         FROM tabel_nilai
         GROUP BY jenis_penilaian
         ORDER BY FIELD(jenis_penilaian, 'tugas', 'kuis', 'uts', 'uas')"
    )->fetchAll();
    $scoreLabels = ['tugas' => 'Tugas', 'kuis' => 'Kuis', 'uts' => 'UTS', 'uas' => 'UAS'];
    $scoreSummary = [];
    foreach ($scoreRows as $row) {
        $scoreSummary[] = [
            'label' => $scoreLabels[(string) $row['jenis_penilaian']] ?? ucfirst((string) $row['jenis_penilaian']),
            'value' => (float) $row['rata_rata'],
            'total' => (int) $row['total'],
        ];
    }

    $healthChecks = [
        [
            'label' => 'Siswa tanpa kelas',
            'value' => (int) $pdo->query('SELECT COUNT(*) FROM tabel_siswa WHERE id_kelas IS NULL')->fetchColumn(),
            'target' => url('admin/pengguna.php#data-siswa'),
        ],
        [
            'label' => 'Akun siswa tanpa profil',
            'value' => (int) $pdo->query("SELECT COUNT(*) FROM tabel_users u LEFT JOIN tabel_siswa s ON s.id_user = u.id_user WHERE u.role = 'siswa' AND s.id_siswa IS NULL")->fetchColumn(),
            'target' => url('admin/pengguna.php#daftar-user'),
        ],
        [
            'label' => 'Akun guru tanpa profil',
            'value' => (int) $pdo->query("SELECT COUNT(*) FROM tabel_users u LEFT JOIN tabel_guru g ON g.id_user = u.id_user WHERE u.role = 'guru' AND g.id_guru IS NULL")->fetchColumn(),
            'target' => url('admin/pengguna.php#daftar-user'),
        ],
        [
            'label' => 'Jadwal kosong',
            'value' => (int) $pdo->query('SELECT CASE WHEN COUNT(*) = 0 THEN 1 ELSE 0 END FROM tabel_jadwal')->fetchColumn(),
            'target' => url('admin/akademik.php#tambah-jadwal'),
        ],
    ];
    $issueCount = array_sum(array_column($healthChecks, 'value'));
    $todayAttendanceCount = (int) $pdo->query('SELECT COUNT(*) FROM tabel_kehadiran WHERE tanggal = CURDATE()')->fetchColumn();
    $pendingNotifCount = (int) $pdo->query('SELECT COUNT(*) FROM tabel_notifikasi WHERE dibaca = FALSE')->fetchColumn();
    $emptyScheduleFlag = (int) $pdo->query('SELECT CASE WHEN COUNT(*) = 0 THEN 1 ELSE 0 END FROM tabel_jadwal')->fetchColumn();
    $priorityActions = [
        [
            'level' => $issueCount > 0 ? 'high' : 'ok',
            'title' => $issueCount > 0 ? 'Rapikan data bermasalah' : 'Data inti aman',
            'description' => $issueCount > 0
                ? $issueCount . ' isu data perlu ditindaklanjuti agar laporan dan akses user tetap akurat.'
                : 'Tidak ada isu data kritis yang terdeteksi saat ini.',
            'target' => $issueCount > 0 ? url('admin/pengguna.php') : url('admin/akademik.php'),
            'action' => $issueCount > 0 ? 'Cek data' : 'Lihat akademik',
        ],
        [
            'level' => $emptyScheduleFlag > 0 ? 'high' : 'normal',
            'title' => $emptyScheduleFlag > 0 ? 'Jadwal belum tersedia' : 'Jadwal pembelajaran tersedia',
            'description' => $emptyScheduleFlag > 0
                ? 'Buat jadwal dulu supaya guru bisa mengisi absensi dan nilai dengan konteks yang benar.'
                : (int) ($adminStatRow['total_jadwal'] ?? 0) . ' jadwal sudah tersimpan di sistem.',
            'target' => url('admin/akademik.php#tambah-jadwal'),
            'action' => $emptyScheduleFlag > 0 ? 'Buat jadwal' : 'Kelola jadwal',
        ],
        [
            'level' => $todayAttendanceCount === 0 ? 'medium' : 'normal',
            'title' => $todayAttendanceCount === 0 ? 'Belum ada absensi hari ini' : 'Absensi hari ini masuk',
            'description' => $todayAttendanceCount === 0
                ? 'Pantau guru atau jadwal aktif jika seharusnya sudah ada pembelajaran hari ini.'
                : $todayAttendanceCount . ' catatan absensi sudah masuk hari ini.',
            'target' => url('laporan.php?jenis=kehadiran'),
            'action' => 'Buka laporan',
        ],
        [
            'level' => $pendingNotifCount > 0 ? 'medium' : 'ok',
            'title' => $pendingNotifCount > 0 ? 'Notifikasi belum dibaca' : 'Notifikasi terkendali',
            'description' => $pendingNotifCount > 0
                ? $pendingNotifCount . ' notifikasi pengguna belum dibaca.'
                : 'Tidak ada backlog notifikasi penting.',
            'target' => url('notifikasi.php'),
            'action' => 'Cek notifikasi',
        ],
    ];
    $activePriorityActions = array_values(array_filter(
        $priorityActions,
        static fn (array $item): bool => in_array($item['level'], ['high', 'medium'], true)
    ));

    $stats = [
        [
            'label' => 'Total Pengguna',
            'value' => (int) ($adminStatRow['total_pengguna'] ?? 0),
            'meta' => 'Akun aktif di sistem',
            'target' => url('admin/pengguna.php#daftar-user'),
        ],
        [
            'label' => 'Total Guru',
            'value' => (int) ($adminStatRow['total_guru'] ?? 0),
            'meta' => 'Tenaga pengajar',
            'target' => url('admin/pengguna.php#daftar-user'),
        ],
        [
            'label' => 'Total Siswa',
            'value' => (int) ($adminStatRow['total_siswa'] ?? 0),
            'meta' => 'Peserta didik',
            'target' => url('admin/pengguna.php#data-siswa'),
        ],
        [
            'label' => 'Total Kelas',
            'value' => (int) ($adminStatRow['total_kelas'] ?? 0),
            'meta' => 'Rombongan belajar',
            'target' => url('admin/akademik.php#daftar-kelas'),
        ],
        [
            'label' => 'Total Mata Pelajaran',
            'value' => (int) ($adminStatRow['total_mapel'] ?? 0),
            'meta' => 'Katalog akademik',
            'target' => url('admin/akademik.php#daftar-mapel'),
        ],
        [
            'label' => 'Total Jadwal',
            'value' => (int) ($adminStatRow['total_jadwal'] ?? 0),
            'meta' => 'Slot pembelajaran',
            'target' => url('admin/akademik.php#daftar-jadwal'),
        ],
    ];
    ?>
    <section class="card panel-span-12 admin-command">
        <div>
            <h2>Panel Kontrol Administrator</h2>
            <p>Ringkasan operasional sekolah berdasarkan data pengguna, akademik, absensi, dan nilai.</p>
        </div>
        <div class="admin-command-actions">
            <a class="action-link" href="<?= e(url('admin/pengguna.php')) ?>">Kelola Pengguna</a>
            <a class="action-link" href="<?= e(url('admin/akademik.php')) ?>">Kelola Akademik</a>
            <a class="action-link" href="<?= e(url('laporan.php')) ?>">Buka Laporan</a>
        </div>
    </section>

    <?php if ($activePriorityActions): ?>
        <section class="card panel-span-12 admin-priority-card">
            <div class="section-heading">
                <div>
                    <h2>Perlu Ditindaklanjuti</h2>
                    <p>Masalah yang berdampak langsung ke data, jadwal, atau monitoring harian.</p>
                </div>
                <span class="badge warning"><?= e((string) count($activePriorityActions)) ?> perlu dicek</span>
            </div>
            <div class="priority-grid">
                <?php foreach ($activePriorityActions as $index => $item): ?>
                    <a class="priority-item <?= e((string) $item['level']) ?>" href="<?= e((string) $item['target']) ?>">
                        <span class="priority-rank"><?= e((string) ($index + 1)) ?></span>
                        <div>
                            <strong><?= e((string) $item['title']) ?></strong>
                            <p><?= e((string) $item['description']) ?></p>
                        </div>
                        <span class="priority-action"><?= e((string) $item['action']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="card panel-span-12 admin-kpi-card">
        <div class="dashboard-block-title">
            <h2>Ringkasan KPI</h2>
            <p>Angka utama untuk memahami skala data sistem.</p>
        </div>
        <div class="stats-grid">
            <?php foreach ($stats as $stat): ?>
                <a class="stat stat-link" href="<?= e((string) $stat['target']) ?>">
                    <div><?= e((string) $stat['label']) ?></div>
                    <div class="value"><?= e((string) $stat['value']) ?></div>
                    <span><?= e((string) $stat['meta']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card panel-span-6 admin-chart-card admin-secondary-card">
        <div class="section-heading">
            <div>
                <h2>Distribusi Role</h2>
                <p>Komposisi akun berdasarkan hak akses.</p>
            </div>
        </div>
        <div class="donut-chart" style="--admin: <?= e((string) round(($roleCounts['admin'] / $totalRoleCount) * 100, 2)) ?>; --guru: <?= e((string) round(($roleCounts['guru'] / $totalRoleCount) * 100, 2)) ?>;">
            <div class="donut-center">
                <strong><?= e((string) array_sum($roleCounts)) ?></strong>
                <span>Akun</span>
            </div>
        </div>
        <div class="chart-legend">
            <?php foreach (['admin' => 'Admin', 'guru' => 'Guru', 'siswa' => 'Siswa'] as $key => $label): ?>
                <div>
                    <span class="legend-dot <?= e($key) ?>"></span>
                    <span><?= e($label) ?></span>
                    <strong><?= e((string) $roleCounts[$key]) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card panel-span-6 admin-chart-card admin-primary-card">
        <div class="section-heading">
            <div>
                <h2>Kesehatan Data</h2>
                <p>Validasi cepat untuk data yang perlu ditindaklanjuti.</p>
            </div>
            <span class="badge <?= $issueCount > 0 ? 'warning' : 'success' ?>"><?= e((string) $issueCount) ?> isu</span>
        </div>
        <div class="health-list">
            <?php foreach ($healthChecks as $check): ?>
                <a href="<?= e((string) $check['target']) ?>" class="health-item <?= $check['value'] > 0 ? 'needs-action' : 'ok' ?>">
                    <span><?= e((string) $check['label']) ?></span>
                    <strong><?= e((string) $check['value']) ?></strong>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card panel-span-8 admin-chart-card admin-primary-card">
        <div class="section-heading">
            <div>
                <h2>Tren Absensi 7 Hari</h2>
                <p>Jumlah catatan kehadiran yang masuk per hari.</p>
            </div>
        </div>
        <div class="bar-chart" aria-label="Grafik tren absensi 7 hari">
            <?php foreach ($attendanceTrend as $point): ?>
                <?php $height = max(6, (int) round(((int) $point['value'] / $maxTrendCount) * 100)); ?>
                <div class="bar-item">
                    <span class="bar-value"><?= e((string) $point['value']) ?></span>
                    <div class="bar-track"><span style="height: <?= e((string) $height) ?>%"></span></div>
                    <span class="bar-label"><?= e((string) $point['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card panel-span-4 admin-chart-card admin-primary-card">
        <div class="section-heading">
            <div>
                <h2>Status Absensi</h2>
                <p>Komposisi seluruh data kehadiran.</p>
            </div>
        </div>
        <div class="stacked-meter">
            <?php foreach ($attendanceCounts as $status => $count): ?>
                <span class="<?= e($status) ?>" style="width: <?= e((string) max(0, round(($count / $attendanceTotal) * 100, 2))) ?>%"></span>
            <?php endforeach; ?>
        </div>
        <div class="metric-list">
            <?php foreach (['hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit', 'alpa' => 'Alpa'] as $status => $label): ?>
                <div>
                    <span><?= e($label) ?></span>
                    <strong><?= e((string) $attendanceCounts[$status]) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card panel-span-6 admin-chart-card admin-secondary-card">
        <div class="section-heading">
            <div>
                <h2>Siswa per Kelas</h2>
                <p>Kepadatan peserta didik di setiap kelas.</p>
            </div>
        </div>
        <?php if (!$kelasRows): ?>
            <div class="empty-state"><strong>Belum ada kelas</strong><p>Tambahkan data kelas dari menu akademik.</p></div>
        <?php else: ?>
            <div class="horizontal-bars">
                <?php foreach ($kelasRows as $kelas): ?>
                    <?php $width = max(3, (int) round(((int) $kelas['total_siswa'] / $maxKelasCount) * 100)); ?>
                    <div class="hbar-row">
                        <span><?= e((string) $kelas['nama_kelas']) ?></span>
                        <div><span style="width: <?= e((string) $width) ?>%"></span></div>
                        <strong><?= e((string) $kelas['total_siswa']) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card panel-span-6 admin-chart-card admin-secondary-card">
        <div class="section-heading">
            <div>
                <h2>Rata-rata Nilai</h2>
                <p>Performa akademik berdasarkan jenis penilaian.</p>
            </div>
        </div>
        <?php if (!$scoreSummary): ?>
            <div class="empty-state"><strong>Belum ada nilai</strong><p>Data nilai akan muncul setelah guru melakukan input.</p></div>
        <?php else: ?>
            <div class="score-list">
                <?php foreach ($scoreSummary as $score): ?>
                    <div class="score-item">
                        <div>
                            <strong><?= e((string) $score['label']) ?></strong>
                            <span><?= e((string) $score['total']) ?> data</span>
                        </div>
                        <div class="score-meter"><span style="width: <?= e((string) max(0, min(100, round($score['value'], 2)))) ?>%"></span></div>
                        <strong><?= e(number_format($score['value'], 1)) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($role === 'guru' && isset($guru['id_guru'])): ?>
    <?php
    $idGuru = (int) $guru['id_guru'];

    $stmt = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM tabel_kehadiran WHERE id_guru = ? AND tanggal = CURDATE()) AS absen_hari_ini,
            (SELECT COUNT(*) FROM tabel_nilai WHERE id_guru = ?) AS total_nilai,
            (SELECT COUNT(DISTINCT id_kelas) FROM tabel_jadwal WHERE id_guru = ?) AS kelas_diampu'
    );
    $stmt->execute([$idGuru, $idGuru, $idGuru]);
    $guruStats = $stmt->fetch() ?: [];

    $absenHariIni = (int) ($guruStats['absen_hari_ini'] ?? 0);
    $totalNilai = (int) ($guruStats['total_nilai'] ?? 0);
    $kelasDiampu = (int) ($guruStats['kelas_diampu'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS total
         FROM tabel_kehadiran
         WHERE id_guru = ? AND tanggal = CURDATE()
         GROUP BY status
         ORDER BY FIELD(status, 'hadir', 'izin', 'sakit', 'alpa')"
    );
    $stmt->execute([$idGuru]);
    $guruAttendanceToday = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $guruAttendanceToday[(string) $row['status']] = (int) $row['total'];
    }
    $guruAttendanceTodayTotal = max(1, array_sum($guruAttendanceToday));

    $stmt = $pdo->prepare(
        "SELECT j.hari, j.jam, k.nama_kelas, m.nama_mapel
         FROM tabel_jadwal j
         JOIN tabel_kelas k ON k.id_kelas = j.id_kelas
         JOIN tabel_mapel m ON m.id_mapel = j.id_mapel
         WHERE j.id_guru = ?
         ORDER BY FIELD(j.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'), j.jam
         LIMIT 8"
    );
    $stmt->execute([$idGuru]);
    $jadwalGuru = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT s.nama AS siswa, m.nama_mapel, n.jenis_penilaian, n.skor, n.periode
         FROM tabel_nilai n
         JOIN tabel_siswa s ON s.id_siswa = n.id_siswa
         JOIN tabel_mapel m ON m.id_mapel = n.id_mapel
         WHERE n.id_guru = ?
         ORDER BY n.id_nilai DESC
         LIMIT 6"
    );
    $stmt->execute([$idGuru]);
    $nilaiGuruTerbaru = $stmt->fetchAll();
    ?>
    <section class="card panel-span-12 role-command">
        <div>
            <h2>Ruang Kerja Guru</h2>
            <p><?= e($guru['nama'] ?? 'Guru') ?>, pantau kelas yang diampu dan lanjutkan input data harian dari sini.</p>
        </div>
        <div class="admin-command-actions">
            <a class="action-link" href="<?= e(url('guru/absensi.php')) ?>">Input Absensi</a>
            <a class="action-link" href="<?= e(url('guru/nilai.php')) ?>">Input Nilai</a>
            <a class="action-link" href="<?= e(url('laporan.php')) ?>">Lihat Laporan</a>
        </div>
    </section>

    <section class="card panel-span-12 role-kpi-card">
        <h2>Statistik Mengajar</h2>
        <div class="stats-grid">
            <div class="stat">
                <div>Absensi Hari Ini</div>
                <div class="value"><?= e((string) $absenHariIni) ?></div>
                <span>Catatan tersimpan hari ini</span>
            </div>
            <div class="stat">
                <div>Nilai Tersimpan</div>
                <div class="value"><?= e((string) $totalNilai) ?></div>
                <span>Total input nilai</span>
            </div>
            <div class="stat">
                <div>Kelas Diampu</div>
                <div class="value"><?= e((string) $kelasDiampu) ?></div>
                <span>Kelas aktif di jadwal</span>
            </div>
        </div>
    </section>

    <section class="card panel-span-6 role-panel">
        <div class="section-heading">
            <div>
                <h2>Jadwal Mengajar</h2>
                <p>Jadwal terdekat berdasarkan data akademik.</p>
            </div>
        </div>
        <?php if (!$jadwalGuru): ?>
            <div class="empty-state"><strong>Belum ada jadwal</strong><p>Hubungi admin agar jadwal mengajar ditambahkan.</p></div>
        <?php else: ?>
            <div class="compact-list">
                <?php foreach ($jadwalGuru as $jadwal): ?>
                    <div class="compact-item">
                        <div>
                            <strong><?= e($jadwal['hari']) ?>, <?= e($jadwal['jam']) ?></strong>
                            <span><?= e($jadwal['nama_mapel']) ?></span>
                        </div>
                        <span class="badge"><?= e($jadwal['nama_kelas']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card panel-span-6 role-panel">
        <div class="section-heading">
            <div>
                <h2>Absensi Hari Ini</h2>
                <p>Komposisi status yang sudah tersimpan.</p>
            </div>
        </div>
        <div class="stacked-meter role-meter">
            <?php foreach ($guruAttendanceToday as $status => $count): ?>
                <span class="<?= e($status) ?>" style="width: <?= e((string) round(($count / $guruAttendanceTodayTotal) * 100, 2)) ?>%"></span>
            <?php endforeach; ?>
        </div>
        <div class="metric-list">
            <?php foreach (['hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit', 'alpa' => 'Alpa'] as $status => $label): ?>
                <div>
                    <span><?= e($label) ?></span>
                    <strong><?= e((string) $guruAttendanceToday[$status]) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card panel-span-12 role-panel">
        <div class="section-heading">
            <div>
                <h2>Nilai Terbaru yang Anda Input</h2>
                <p>Aktivitas nilai terakhir untuk validasi cepat.</p>
            </div>
        </div>
        <?php if (!$nilaiGuruTerbaru): ?>
            <div class="empty-state"><strong>Belum ada nilai</strong><p>Nilai yang Anda input akan tampil di sini.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Siswa</th>
                        <th>Mata Pelajaran</th>
                        <th>Jenis</th>
                        <th>Periode</th>
                        <th>Skor</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($nilaiGuruTerbaru as $nilai): ?>
                        <tr>
                            <td><?= e($nilai['siswa']) ?></td>
                            <td><?= e($nilai['nama_mapel']) ?></td>
                            <td><?= e(strtoupper($nilai['jenis_penilaian'])) ?></td>
                            <td><?= e($nilai['periode']) ?></td>
                            <td><strong><?= e((string) $nilai['skor']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($role === 'guru' && !isset($guru['id_guru'])): ?>
    <section class="card panel-span-12">
        <div class="empty-state"><strong>Profil guru belum lengkap</strong><p>Akun Anda belum terhubung ke data guru. Hubungi admin untuk melengkapi profil.</p></div>
    </section>
<?php endif; ?>

<?php if ($role === 'siswa' && isset($siswa['id_siswa'])): ?>
    <?php
    $idSiswa = (int) $siswa['id_siswa'];

    $stmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) AS hadir,
            COUNT(*) AS total_absen
         FROM tabel_kehadiran
         WHERE id_siswa = ?"
    );
    $stmt->execute([$idSiswa]);
    $kehadiranStats = $stmt->fetch() ?: [];
    $hadir = (int) ($kehadiranStats['hadir'] ?? 0);
    $totalAbsen = (int) ($kehadiranStats['total_absen'] ?? 0);
    $persentaseHadir = $totalAbsen > 0 ? round(($hadir / $totalAbsen) * 100, 1) : 0;

    $stmt = $pdo->prepare('SELECT AVG(skor) FROM tabel_nilai WHERE id_siswa = ?');
    $stmt->execute([$idSiswa]);
    $rataNilai = (float) ($stmt->fetchColumn() ?? 0);

    $stmt = $pdo->prepare('SELECT m.nama_mapel, n.jenis_penilaian, n.skor, n.periode FROM tabel_nilai n JOIN tabel_mapel m ON m.id_mapel = n.id_mapel WHERE n.id_siswa = ? ORDER BY n.jenis_penilaian, n.id_nilai DESC LIMIT 20');
    $stmt->execute([$idSiswa]);
    $nilaiTerbaru = $stmt->fetchAll();
    
    // Kelompokkan per jenis_penilaian, ambil yang terbaru
    $nilaiByType = [];
    foreach ($nilaiTerbaru as $n) {
        $key = $n['jenis_penilaian'];
        if (!isset($nilaiByType[$key])) {
            $nilaiByType[$key] = [];
        }
        $nilaiByType[$key][] = $n;
    }
    $totalMapelDiikuti = count(array_unique(array_column($nilaiTerbaru, 'nama_mapel')));
    $nilaiByMapel = [];
    foreach ($nilaiTerbaru as $n) {
        $mapel = (string) ($n['nama_mapel'] ?? '-');
        if (!isset($nilaiByMapel[$mapel])) {
            $nilaiByMapel[$mapel] = ['total' => 0.0, 'count' => 0];
        }
        $nilaiByMapel[$mapel]['total'] += (float) ($n['skor'] ?? 0);
        $nilaiByMapel[$mapel]['count']++;
    }

    $stmt = $pdo->prepare(
        "SELECT j.hari, j.jam, m.nama_mapel, g.nama AS guru
         FROM tabel_jadwal j
         JOIN tabel_mapel m ON m.id_mapel = j.id_mapel
         JOIN tabel_guru g ON g.id_guru = j.id_guru
         WHERE j.id_kelas = ?
         ORDER BY FIELD(j.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'), j.jam
         LIMIT 8"
    );
    $stmt->execute([(int) ($siswa['id_kelas'] ?? 0)]);
    $jadwalSiswa = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT pesan, tanggal, dibaca FROM tabel_notifikasi WHERE id_user = ? ORDER BY dibaca ASC, created_at DESC LIMIT 5');
    $stmt->execute([(int) $user['id_user']]);
    $notifikasiSiswa = $stmt->fetchAll();
    ?>
    <section class="card panel-span-12 role-command">
        <div>
            <h2>Ringkasan Siswa</h2>
            <p><?= e($siswa['nama'] ?? 'Siswa') ?>, pantau kehadiran, nilai, jadwal, dan notifikasi akademik Anda.</p>
        </div>
        <div class="admin-command-actions">
            <a class="action-link" href="<?= e(url('laporan.php')) ?>">Lihat Laporan</a>
            <a class="action-link" href="<?= e(url('notifikasi.php')) ?>">Notifikasi</a>
            <a class="action-link" href="<?= e(url('profil.php')) ?>">Profil</a>
        </div>
    </section>

    <section class="card panel-span-12 role-kpi-card">
        <h2>Statistik Akademik</h2>
        <div class="stats-grid">
            <div class="stat">
                <div>Kehadiran</div>
                <div class="value"><?= e((string) $hadir) ?> / <?= e((string) $totalAbsen) ?></div>
                <span><?= e((string) $persentaseHadir) ?>% hadir</span>
            </div>
            <div class="stat">
                <div>Rata-Rata Nilai</div>
                <div class="value"><?= e(number_format($rataNilai, 2)) ?></div>
                <span>Skala 0-100</span>
            </div>
            <div class="stat">
                <div>Mata Pelajaran</div>
                <div class="value"><?= e((string) $totalMapelDiikuti) ?></div>
                <span>Dengan nilai terdata</span>
            </div>
        </div>
    </section>

    <section class="card panel-span-12 student-progress-panel">
        <div class="section-heading">
            <div>
                <h2>Progress Akademik</h2>
                <p>Kehadiran dan nilai utama ditampilkan sebagai indikator visual agar mudah dipantau.</p>
            </div>
        </div>
        <div class="student-progress-grid">
            <div class="progress-ring-card">
                <div class="progress-ring" style="--value: <?= e((string) min(100, max(0, $persentaseHadir))) ?>;">
                    <strong><?= e((string) $persentaseHadir) ?>%</strong>
                    <span>Hadir</span>
                </div>
                <p><?= e((string) $hadir) ?> dari <?= e((string) $totalAbsen) ?> catatan kehadiran tercatat hadir.</p>
            </div>
            <div class="subject-score-list">
                <?php if (!$nilaiByMapel): ?>
                    <div class="empty-state"><strong>Belum ada nilai mapel</strong><p>Grafik nilai akan muncul setelah guru menginput nilai.</p></div>
                <?php else: ?>
                    <?php foreach ($nilaiByMapel as $mapel => $nilaiMapel): ?>
                        <?php $avgMapel = $nilaiMapel['count'] > 0 ? round($nilaiMapel['total'] / $nilaiMapel['count'], 1) : 0; ?>
                        <div class="subject-score-item">
                            <div>
                                <strong><?= e($mapel) ?></strong>
                                <span><?= e((string) $nilaiMapel['count']) ?> nilai</span>
                            </div>
                            <div class="score-meter"><span style="width: <?= e((string) min(100, max(0, $avgMapel))) ?>%"></span></div>
                            <strong><?= e((string) $avgMapel) ?></strong>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="card panel-span-6 role-panel">
        <div class="section-heading">
            <div>
                <h2>Jadwal Kelas</h2>
                <p>Jadwal pelajaran berdasarkan kelas Anda.</p>
            </div>
        </div>
        <?php if (!$jadwalSiswa): ?>
            <div class="empty-state"><strong>Belum ada jadwal</strong><p>Jadwal kelas akan muncul setelah admin mengatur data akademik.</p></div>
        <?php else: ?>
            <div class="compact-list">
                <?php foreach ($jadwalSiswa as $jadwal): ?>
                    <div class="compact-item">
                        <div>
                            <strong><?= e($jadwal['hari']) ?>, <?= e($jadwal['jam']) ?></strong>
                            <span><?= e($jadwal['nama_mapel']) ?> - <?= e($jadwal['guru']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card panel-span-6 role-panel">
        <div class="section-heading">
            <div>
                <h2>Notifikasi Terbaru</h2>
                <p>Informasi akademik yang perlu dibaca.</p>
            </div>
        </div>
        <?php if (!$notifikasiSiswa): ?>
            <div class="empty-state"><strong>Belum ada notifikasi</strong><p>Informasi penting dari sistem akan tampil di sini.</p></div>
        <?php else: ?>
            <div class="compact-list">
                <?php foreach ($notifikasiSiswa as $notif): ?>
                    <div class="compact-item <?= (int) $notif['dibaca'] === 0 ? 'unread' : '' ?>">
                        <div>
                            <strong><?= e(date('d/m/Y', strtotime((string) $notif['tanggal']))) ?></strong>
                            <span><?= e($notif['pesan']) ?></span>
                        </div>
                        <?php if ((int) $notif['dibaca'] === 0): ?><span class="badge warning">Baru</span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card panel-span-12 role-panel">
        <h2>Nilai Terbaru</h2>
        <?php if (!$nilaiTerbaru): ?>
            <div class="empty-state">
                <strong>Belum ada nilai</strong>
                <p>Nilai akademik Anda belum diterbitkan oleh guru mata pelajaran.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap siswa-nilai-wrap">
        <table>
            <thead>
            <tr>
                <th>Jenis</th>
                <th>Mata Pelajaran</th>
                <th>Periode</th>
                <th>Skor</th>
            </tr>
            </thead>
            <tbody>
            <?php 
            $typeLabels = ['tugas' => 'Tugas', 'kuis' => 'Kuis', 'uts' => 'UTS', 'uas' => 'UAS'];
            foreach ($typeLabels as $type => $label): 
                if (!isset($nilaiByType[$type])) continue;
                $count = 0;
                foreach ($nilaiByType[$type] as $n):
                    if ($count >= 3) break; // Tampilkan max 3 per jenis
                    $count++;
            ?>
                <tr>
                    <td><?= $count === 1 ? e($label) : '' ?></td>
                    <td><?= e($n['nama_mapel']) ?></td>
                    <td><?= e($n['periode']) ?></td>
                    <td><?= e((string) $n['skor']) ?></td>
                </tr>
            <?php endforeach; endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($role === 'siswa' && !isset($siswa['id_siswa'])): ?>
    <section class="card panel-span-12">
        <div class="empty-state"><strong>Profil siswa belum lengkap</strong><p>Akun Anda belum terhubung ke data siswa. Hubungi admin untuk melengkapi profil.</p></div>
    </section>
<?php endif; ?>

</div>

<?php include LAYOUT_PATH . '/footer.php'; ?>
