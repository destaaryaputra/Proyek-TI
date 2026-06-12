<?php

declare(strict_types=1);

// Halaman laporan untuk rekap absensi, nilai, detail ketidakhadiran, dan notifikasi.
require_once __DIR__ . '/../app/bootstrap.php';

require_login();

$user = current_user();
$role = (string) ($user['role'] ?? '');

function report_scope_from_user(PDO $pdo, array $user): array
{
    $scope = [
        'role' => (string) ($user['role'] ?? ''),
        'id_guru' => 0,
        'id_siswa' => 0,
    ];

    $userId = (int) ($user['id_user'] ?? 0);
    if ($userId <= 0) {
        return $scope;
    }

    if ($scope['role'] === 'guru') {
        $stmt = $pdo->prepare('SELECT id_guru FROM tabel_guru WHERE id_user = ? LIMIT 1');
        $stmt->execute([$userId]);
        $scope['id_guru'] = (int) ($stmt->fetchColumn() ?: 0);
    }

    if ($scope['role'] === 'siswa') {
        $stmt = $pdo->prepare('SELECT id_siswa FROM tabel_siswa WHERE id_user = ? LIMIT 1');
        $stmt->execute([$userId]);
        $scope['id_siswa'] = (int) ($stmt->fetchColumn() ?: 0);
    }

    return $scope;
}

function report_apply_scope_filter(string $sql, array &$params, array $scope, string $teacherAlias): string
{
    if ($scope['role'] === 'guru') {
        $sql .= ' AND ' . $teacherAlias . '.id_guru = ?';
        $params[] = (int) ($scope['id_guru'] ?? 0);
    }

    if ($scope['role'] === 'siswa') {
        $sql .= ' AND s.id_siswa = ?';
        $params[] = (int) ($scope['id_siswa'] ?? 0);
    }

    return $sql;
}

function report_url(array $params): string
{
    return url('laporan.php?' . http_build_query(array_filter($params, static fn ($value) => $value !== null && $value !== '')));
}

$scope = report_scope_from_user($pdo, $user);
$idSiswaScope = (int) ($scope['id_siswa'] ?? 0);

// Filter laporan dipakai admin dan guru; role lain dibatasi sesuai data miliknya.
$filterKelas = get_int('id_kelas', 0);
$filterMapel = get_int('id_mapel', 0);
$filterPeriode = get_string('periode');
$exportSection = get_string('section');
$printMode = get_string('print') === '1';
$exportMode = get_string('export') === 'excel';
$printEmbedded = $printMode && (get_string('embed') === '1');
if ($printMode) {
    $bodyClass = 'report-print-mode';
}

if (!function_exists('micro_export_buttons')) {
    function micro_export_buttons(string $section, int $filterKelas, int $filterMapel, string $filterPeriode): string {
        $params = array_filter([
            'id_kelas' => $filterKelas ?: null,
            'id_mapel' => $filterMapel ?: null,
            'periode' => $filterPeriode !== '' ? $filterPeriode : null,
            'section' => $section,
        ]);
        $excelUrl = report_url(array_merge($params, ['export' => 'excel']));
        $printUrl = report_url(array_merge($params, ['print' => '1']));

        return '
        <div class="micro-export-actions">
            <a href="' . e($excelUrl) . '" title="Export tabel ini ke Excel" class="action-link icon-action"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="8" y1="13" x2="16" y2="13"></line><line x1="8" y1="17" x2="16" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg></a>
            <a href="' . e($printUrl) . '" title="Cetak tabel ini (PDF)" class="action-link icon-action js-open-print-modal" rel="noopener"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg></a>
        </div>';
    }
}

$kelasList = $pdo->query('SELECT id_kelas, nama_kelas FROM tabel_kelas ORDER BY nama_kelas ASC')->fetchAll();
$mapelList = $pdo->query('SELECT id_mapel, nama_mapel FROM tabel_mapel ORDER BY nama_mapel ASC')->fetchAll();

if ($role === 'guru' && (int) ($scope['id_guru'] ?? 0) > 0) {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT k.id_kelas, k.nama_kelas
         FROM tabel_jadwal j
         JOIN tabel_kelas k ON k.id_kelas = j.id_kelas
         WHERE j.id_guru = ?
         ORDER BY k.nama_kelas ASC'
    );
    $stmt->execute([(int) $scope['id_guru']]);
    $kelasList = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT DISTINCT m.id_mapel, m.nama_mapel
         FROM tabel_jadwal j
         JOIN tabel_mapel m ON m.id_mapel = j.id_mapel
         WHERE j.id_guru = ?
         ORDER BY m.nama_mapel ASC'
    );
    $stmt->execute([(int) $scope['id_guru']]);
    $mapelList = $stmt->fetchAll();
}
$periodeList = $pdo->query('SELECT DISTINCT periode FROM tabel_nilai ORDER BY periode DESC')->fetchAll(PDO::FETCH_COLUMN);

$sqlAbsensiAgregat = "SELECT kh.id_siswa,
SUM(CASE WHEN kh.status='hadir' THEN 1 ELSE 0 END) AS hadir,
SUM(CASE WHEN kh.status='izin' THEN 1 ELSE 0 END) AS izin,
SUM(CASE WHEN kh.status='sakit' THEN 1 ELSE 0 END) AS sakit,
SUM(CASE WHEN kh.status='alpa' THEN 1 ELSE 0 END) AS alpa,
COUNT(kh.id_kehadiran) AS total
FROM tabel_kehadiran kh
WHERE 1=1";
$paramsAbsensi = [];
$absensiTidakAdaScope = false;
$absensiWajibPunyaData = false;

if ($role === 'guru') {
    $idGuruScope = (int) ($scope['id_guru'] ?? 0);
    if ($idGuruScope > 0) {
        $sqlAbsensiAgregat .= ' AND kh.id_guru = ?';
        $paramsAbsensi[] = $idGuruScope;
        $absensiWajibPunyaData = true;
    } else {
        $absensiTidakAdaScope = true;
    }
}

if ($filterMapel > 0 && in_array($role, ['admin', 'guru'], true)) {
    $sqlAbsensiAgregat .= ' AND kh.id_mapel = ?';
    $paramsAbsensi[] = $filterMapel;
}

$sqlAbsensiAgregat .= ' GROUP BY kh.id_siswa';

$sqlAbsensi = "SELECT s.nama, s.nis, k.nama_kelas,
COALESCE(a.hadir, 0) AS hadir,
COALESCE(a.izin, 0) AS izin,
COALESCE(a.sakit, 0) AS sakit,
COALESCE(a.alpa, 0) AS alpa,
COALESCE(a.total, 0) AS total
FROM tabel_siswa s
LEFT JOIN tabel_kelas k ON k.id_kelas = s.id_kelas
LEFT JOIN ($sqlAbsensiAgregat) a ON a.id_siswa = s.id_siswa
WHERE 1=1";

$sqlNilai = "SELECT s.nama, s.nis, k.nama_kelas, m.nama_mapel,
AVG(n.skor) AS rata_nilai
FROM tabel_siswa s
LEFT JOIN tabel_kelas k ON k.id_kelas = s.id_kelas
LEFT JOIN tabel_nilai n ON n.id_siswa = s.id_siswa
LEFT JOIN tabel_mapel m ON m.id_mapel = n.id_mapel
WHERE 1=1";
$paramsNilai = [];

if ($filterPeriode !== '') {
    $sqlNilai .= ' AND n.periode = ?';
    $paramsNilai[] = $filterPeriode;
}

$sqlNilai = report_apply_scope_filter($sqlNilai, $paramsNilai, $scope, 'n');

if ($role === 'siswa') {
    if ($idSiswaScope > 0) {
        $sqlAbsensi .= ' AND s.id_siswa = ?';
        $paramsAbsensi[] = $idSiswaScope;
    } else {
        $absensiTidakAdaScope = true;
    }
}

if ($absensiTidakAdaScope) {
    $sqlAbsensi .= ' AND 1=0';
}

if ($absensiWajibPunyaData) {
    $sqlAbsensi .= ' AND a.id_siswa IS NOT NULL';
}

if ($filterKelas > 0 && in_array($role, ['admin', 'guru'], true)) {
    $sqlAbsensi .= ' AND s.id_kelas = ?';
    $paramsAbsensi[] = $filterKelas;

    $sqlNilai .= ' AND s.id_kelas = ?';
    $paramsNilai[] = $filterKelas;
}

if ($filterMapel > 0 && in_array($role, ['admin', 'guru'], true)) {
    $sqlNilai .= ' AND n.id_mapel = ?';
    $paramsNilai[] = $filterMapel;
}

$sqlAbsensi .= ' ORDER BY s.nama ASC';
$sqlNilai .= ' GROUP BY s.id_siswa, s.nama, s.nis, k.nama_kelas, m.nama_mapel ORDER BY s.nama ASC';

$stmt = $pdo->prepare($sqlAbsensi);
$stmt->execute($paramsAbsensi);
$absensiRows = $stmt->fetchAll();

$sqlAbsensiMapel = "SELECT s.nama, s.nis, k.nama_kelas, m.nama_mapel,
SUM(CASE WHEN kh.status='hadir' THEN 1 ELSE 0 END) AS hadir,
SUM(CASE WHEN kh.status='izin' THEN 1 ELSE 0 END) AS izin,
SUM(CASE WHEN kh.status='sakit' THEN 1 ELSE 0 END) AS sakit,
SUM(CASE WHEN kh.status='alpa' THEN 1 ELSE 0 END) AS alpa,
COUNT(kh.id_kehadiran) AS total
FROM tabel_kehadiran kh
JOIN tabel_siswa s ON s.id_siswa = kh.id_siswa
LEFT JOIN tabel_kelas k ON k.id_kelas = s.id_kelas
LEFT JOIN tabel_mapel m ON m.id_mapel = kh.id_mapel
WHERE 1=1";
$paramsAbsensiMapel = [];
$sqlAbsensiMapel = report_apply_scope_filter($sqlAbsensiMapel, $paramsAbsensiMapel, $scope, 'kh');

if ($filterKelas > 0 && in_array($role, ['admin', 'guru'], true)) {
    $sqlAbsensiMapel .= ' AND s.id_kelas = ?';
    $paramsAbsensiMapel[] = $filterKelas;
}

if ($filterMapel > 0 && in_array($role, ['admin', 'guru'], true)) {
    $sqlAbsensiMapel .= ' AND kh.id_mapel = ?';
    $paramsAbsensiMapel[] = $filterMapel;
}

$sqlAbsensiMapel .= ' GROUP BY s.id_siswa, s.nama, s.nis, k.nama_kelas, m.id_mapel, m.nama_mapel ORDER BY s.nama ASC, m.nama_mapel ASC';
$stmt = $pdo->prepare($sqlAbsensiMapel);
$stmt->execute($paramsAbsensiMapel);
$absensiMapelRows = $stmt->fetchAll();

$stmt = $pdo->prepare($sqlNilai);
$stmt->execute($paramsNilai);
$nilaiRows = $stmt->fetchAll();

$nilaiSiswaMatrix = [];

if ($role === 'siswa' && $idSiswaScope > 0) {
    $sqlNilaiSiswa = 'SELECT m.nama_mapel, n.jenis_penilaian, n.skor
    FROM tabel_nilai n
    JOIN tabel_mapel m ON m.id_mapel = n.id_mapel
    WHERE n.id_siswa = ?';
    $paramsNilaiSiswa = [$idSiswaScope];

    if ($filterPeriode !== '') {
        $sqlNilaiSiswa .= ' AND n.periode = ?';
        $paramsNilaiSiswa[] = $filterPeriode;
    }

    $sqlNilaiSiswa .= ' ORDER BY m.nama_mapel ASC, n.created_at ASC, n.id_nilai ASC';

    $stmt = $pdo->prepare($sqlNilaiSiswa);
    $stmt->execute($paramsNilaiSiswa);
    $nilaiSiswaRows = $stmt->fetchAll();

    foreach ($nilaiSiswaRows as $nilaiRow) {
        $namaMapel = (string) ($nilaiRow['nama_mapel'] ?? '-');

        if (!isset($nilaiSiswaMatrix[$namaMapel])) {
            $nilaiSiswaMatrix[$namaMapel] = [
                'tugas' => [],
                'kuis' => [],
                'uts' => null,
                'uas' => null,
            ];
        }

        $jenis = (string) ($nilaiRow['jenis_penilaian'] ?? '');
        $skor = (float) ($nilaiRow['skor'] ?? 0);

        if ($jenis === 'tugas') {
            $nilaiSiswaMatrix[$namaMapel]['tugas'][] = $skor;
            continue;
        }

        if ($jenis === 'kuis') {
            $nilaiSiswaMatrix[$namaMapel]['kuis'][] = $skor;
            continue;
        }

        if ($jenis === 'uts') {
            $nilaiSiswaMatrix[$namaMapel]['uts'] = $skor;
            continue;
        }

        if ($jenis === 'uas') {
            $nilaiSiswaMatrix[$namaMapel]['uas'] = $skor;
        }
    }
}

$sqlAbsensiDetail = "SELECT s.nama, s.nis, k.nama_kelas, m.nama_mapel, kh.tanggal, kh.status
FROM tabel_kehadiran kh
JOIN tabel_siswa s ON s.id_siswa = kh.id_siswa
LEFT JOIN tabel_kelas k ON k.id_kelas = s.id_kelas
LEFT JOIN tabel_mapel m ON m.id_mapel = kh.id_mapel
WHERE kh.status IN ('izin', 'sakit', 'alpa')";
$paramsAbsensiDetail = [];

$sqlAbsensiDetail = report_apply_scope_filter($sqlAbsensiDetail, $paramsAbsensiDetail, $scope, 'kh');

if ($filterKelas > 0 && in_array($role, ['admin', 'guru'], true)) {
    $sqlAbsensiDetail .= ' AND s.id_kelas = ?';
    $paramsAbsensiDetail[] = $filterKelas;
}

if ($filterMapel > 0 && in_array($role, ['admin', 'guru'], true)) {
    $sqlAbsensiDetail .= ' AND kh.id_mapel = ?';
    $paramsAbsensiDetail[] = $filterMapel;
}

$sqlAbsensiDetail .= ' ORDER BY kh.tanggal DESC, s.nama ASC LIMIT 200';

$stmt = $pdo->prepare($sqlAbsensiDetail);
$stmt->execute($paramsAbsensiDetail);
$absensiDetailRows = $stmt->fetchAll();

$notifs = [];
if ($role === 'siswa') {
    $stmt = $pdo->prepare('SELECT pesan, tanggal FROM tabel_notifikasi WHERE id_user = ? ORDER BY id_notifikasi DESC LIMIT 20');
    $stmt->execute([$user['id_user']]);
    $notifs = $stmt->fetchAll();
}

$summaryHadir = 0;
$summaryIzin = 0;
$summarySakit = 0;
$summaryAlpa = 0;
$summaryTotalAbsensi = 0;
foreach ($absensiRows as $row) {
    $summaryHadir += (int) ($row['hadir'] ?? 0);
    $summaryIzin += (int) ($row['izin'] ?? 0);
    $summarySakit += (int) ($row['sakit'] ?? 0);
    $summaryAlpa += (int) ($row['alpa'] ?? 0);
    $summaryTotalAbsensi += (int) ($row['total'] ?? 0);
}
$summaryPersenHadir = $summaryTotalAbsensi > 0 ? round(($summaryHadir / $summaryTotalAbsensi) * 100, 1) : 0;

$summaryNilaiTotal = 0.0;
$summaryNilaiCount = 0;
if ($role === 'siswa') {
    foreach ($nilaiSiswaMatrix as $nilaiMapel) {
        foreach (['tugas', 'kuis'] as $jenisList) {
            foreach ($nilaiMapel[$jenisList] as $skor) {
                $summaryNilaiTotal += (float) $skor;
                $summaryNilaiCount++;
            }
        }
        foreach (['uts', 'uas'] as $jenisSingle) {
            if ($nilaiMapel[$jenisSingle] !== null) {
                $summaryNilaiTotal += (float) $nilaiMapel[$jenisSingle];
                $summaryNilaiCount++;
            }
        }
    }
} else {
    foreach ($nilaiRows as $row) {
        if ($row['rata_nilai'] !== null) {
            $summaryNilaiTotal += (float) $row['rata_nilai'];
            $summaryNilaiCount++;
        }
    }
}
$summaryRataNilai = $summaryNilaiCount > 0 ? round($summaryNilaiTotal / $summaryNilaiCount, 1) : 0;
$summaryTidakHadir = $summaryIzin + $summarySakit + $summaryAlpa;
$summaryMapelCount = count(array_unique(array_filter(array_map(static fn ($row): string => (string) ($row['nama_mapel'] ?? ''), $absensiMapelRows))));

if ($exportMode) {
    $filename = 'laporan-akademik-' . date('Ymd-His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    ?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Akademik</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; }
        h1, h2 { margin: 0 0 8px; }
        p { margin: 0 0 12px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 16px; }
        th, td { border: 1px solid #777; padding: 6px 8px; text-align: left; }
        th { background: #e8f1fb; }
    </style>
</head>
<body>
    <h1>Laporan Akademik</h1>
    <p>Dibuat pada: <?= e(date('d M Y, H:i')) ?></p>

    <?php if ($exportSection === '' || $exportSection === 'absensi'): ?>
    <h2>Rekap Kehadiran</h2>
    <table>
        <thead>
        <tr>
            <th>No.</th>
            <th>Nama</th>
            <th>NIS</th>
            <th>Kelas</th>
            <th>Hadir</th>
            <th>Izin</th>
            <th>Sakit</th>
            <th>Alpa</th>
            <th>Total</th>
        </tr>
        </thead>
        <tbody>
        <?php $no = 1; foreach ($absensiRows as $row): ?>
            <tr>
                <td><?= $no++ ?></td>
                <td><?= e((string) $row['nama']) ?></td>
                <td><?= e((string) $row['nis']) ?></td>
                <td><?= e((string) ($row['nama_kelas'] ?? '-')) ?></td>
                <td><?= e((string) $row['hadir']) ?></td>
                <td><?= e((string) $row['izin']) ?></td>
                <td><?= e((string) $row['sakit']) ?></td>
                <td><?= e((string) $row['alpa']) ?></td>
                <td><?= e((string) $row['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($exportSection === '' || $exportSection === 'absensi_mapel'): ?>
    <h2>Rekap Kehadiran per Mata Pelajaran</h2>
    <table>
        <thead>
        <tr>
            <th>No.</th>
            <th>Nama</th>
            <th>NIS</th>
            <th>Kelas</th>
            <th>Mata Pelajaran</th>
            <th>Hadir</th>
            <th>Izin</th>
            <th>Sakit</th>
            <th>Alpa</th>
            <th>Total</th>
        </tr>
        </thead>
        <tbody>
        <?php $no = 1; foreach ($absensiMapelRows as $row): ?>
            <tr>
                <td><?= $no++ ?></td>
                <td><?= e((string) $row['nama']) ?></td>
                <td><?= e((string) $row['nis']) ?></td>
                <td><?= e((string) ($row['nama_kelas'] ?? '-')) ?></td>
                <td><?= e((string) ($row['nama_mapel'] ?? '-')) ?></td>
                <td><?= e((string) $row['hadir']) ?></td>
                <td><?= e((string) $row['izin']) ?></td>
                <td><?= e((string) $row['sakit']) ?></td>
                <td><?= e((string) $row['alpa']) ?></td>
                <td><?= e((string) $row['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($exportSection === '' || $exportSection === 'detail_absensi'): ?>
    <h2>Detail Ketidakhadiran</h2>
    <table>
        <thead>
        <tr>
            <th>No.</th>
            <th>Tanggal</th>
            <th>Nama</th>
            <th>NIS</th>
            <th>Kelas</th>
            <th>Mata Pelajaran</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php $no = 1; foreach ($absensiDetailRows as $row): ?>
            <tr>
                <td><?= $no++ ?></td>
                <td><?= e((string) $row['tanggal']) ?></td>
                <td><?= e((string) $row['nama']) ?></td>
                <td><?= e((string) $row['nis']) ?></td>
                <td><?= e((string) ($row['nama_kelas'] ?? '-')) ?></td>
                <td><?= e((string) ($row['nama_mapel'] ?? '-')) ?></td>
                <td><?= e((string) ucfirst((string) $row['status'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($exportSection === '' || $exportSection === 'nilai'): ?>
    <h2>Rekap Nilai</h2>
    <?php if ($role === 'siswa'): ?>
        <table>
            <thead>
            <tr>
                <th>No.</th>
                <th>Mata Pelajaran</th>
                <th>Tugas</th>
                <th>Kuis</th>
                <th>UTS</th>
                <th>UAS</th>
            </tr>
            </thead>
            <tbody>
            <?php $no = 1; foreach ($nilaiSiswaMatrix as $namaMapel => $nilaiMapel): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= e((string) $namaMapel) ?></td>
                    <?php
                    $tugasItems = [];
                    foreach ($nilaiMapel['tugas'] as $index => $nilaiTugas) {
                        $tugasItems[] = 'Tugas ' . ((string) ($index + 1)) . ': ' . number_format((float) $nilaiTugas, 2);
                    }
                        $kuisItems = [];
                        foreach ($nilaiMapel['kuis'] as $index => $nilaiKuis) {
                            $kuisItems[] = 'Kuis ' . ((string) ($index + 1)) . ': ' . number_format((float) $nilaiKuis, 2);
                        }
                    ?>
                    <td><?= $tugasItems ? e(implode(' | ', $tugasItems)) : '-' ?></td>
                        <td><?= $kuisItems ? e(implode(' | ', $kuisItems)) : '-' ?></td>
                    <td><?= $nilaiMapel['uts'] !== null ? e(number_format((float) $nilaiMapel['uts'], 2)) : '-' ?></td>
                    <td><?= $nilaiMapel['uas'] !== null ? e(number_format((float) $nilaiMapel['uas'], 2)) : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>No.</th>
                <th>Nama</th>
                <th>NIS</th>
                <th>Kelas</th>
                <th>Mata Pelajaran</th>
                <th>Nilai</th>
            </tr>
            </thead>
            <tbody>
            <?php $no = 1; foreach ($nilaiRows as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= e((string) $row['nama']) ?></td>
                    <td><?= e((string) $row['nis']) ?></td>
                    <td><?= e((string) ($row['nama_kelas'] ?? '-')) ?></td>
                    <td><?= e((string) ($row['nama_mapel'] ?? '-')) ?></td>
                    <td><?= $row['rata_nilai'] !== null ? e(number_format((float) $row['rata_nilai'], 2)) : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; endif; ?>

    <?php if ($role === 'siswa' && ($exportSection === '' || $exportSection === 'notifikasi')): ?>
    <h2>Notifikasi Saya</h2>
    <table>
        <thead>
        <tr>
            <th>No.</th>
            <th>Tanggal</th>
            <th>Pesan</th>
        </tr>
        </thead>
        <tbody>
        <?php $no = 1; foreach ($notifs as $notif): ?>
            <tr>
                <td><?= $no++ ?></td>
                <td><?= e((string) $notif['tanggal']) ?></td>
                <td><?= e((string) $notif['pesan']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</body>
</html>
<?php
    exit;
}

if ($printMode) {
    $returnUrl = report_url([
        'id_kelas' => $filterKelas,
        'id_mapel' => $filterMapel,
        'periode' => $filterPeriode,
    ]);
    ?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Laporan Akademik - EduTrack</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        html, body { background: #ffffff !important; }
        body { font-family: Arial, sans-serif; font-size: 11px; color: #111; margin: 0; }
        h2 { margin: 0 0 8px; font-size: 14px; page-break-after: avoid; }
        section { margin: 0 0 20px; page-break-before: always; }
        section:first-of-type { page-break-before: auto; }
        table { border-collapse: collapse; width: 100%; table-layout: auto; page-break-inside: auto; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        th, td {
            border: 1px solid #666;
            padding: 5px 6px;
            text-align: left;
            vertical-align: top;
            white-space: normal;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        th { background: #e8f1fb; }

        @media print {
            html, body {
                background: #ffffff !important;
            }
        }
    </style>
</head>
<body>
    <div style="margin-bottom: 15px; border-bottom: 2px solid #333; padding-bottom: 10px; page-break-after: avoid;">
        <h1 style="margin: 0 0 5px; font-size: 18px; color: #111;">Laporan Akademik - EduTrack Sekolah</h1>
        <p style="margin: 0; color: #444;">Dibuat pada: <?= e(date('d M Y, H:i')) ?></p>
    </div>

    <?php if ($exportSection === '' || $exportSection === 'absensi'): ?>
    <section>
        <h2>Rekap Kehadiran</h2>
        <table>
            <thead>
            <tr>
                <th>No.</th>
                <th>Nama</th>
                <th>NIS</th>
                <th>Kelas</th>
                <th>Hadir</th>
                <th>Izin</th>
                <th>Sakit</th>
                <th>Alpa</th>
                <th>Total</th>
            </tr>
            </thead>
            <tbody>
            <?php $no = 1; foreach ($absensiRows as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= e((string) $row['nama']) ?></td>
                    <td><?= e((string) $row['nis']) ?></td>
                    <td><?= e((string) ($row['nama_kelas'] ?? '-')) ?></td>
                    <td><?= e((string) $row['hadir']) ?></td>
                    <td><?= e((string) $row['izin']) ?></td>
                    <td><?= e((string) $row['sakit']) ?></td>
                    <td><?= e((string) $row['alpa']) ?></td>
                    <td><?= e((string) $row['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($exportSection === '' || $exportSection === 'absensi_mapel'): ?>
    <section>
        <h2>Rekap Kehadiran per Mata Pelajaran</h2>
        <table>
            <thead>
            <tr>
                <th>No.</th>
                <th>Nama</th>
                <th>NIS</th>
                <th>Kelas</th>
                <th>Mata Pelajaran</th>
                <th>Hadir</th>
                <th>Izin</th>
                <th>Sakit</th>
                <th>Alpa</th>
                <th>Total</th>
            </tr>
            </thead>
            <tbody>
            <?php $no = 1; foreach ($absensiMapelRows as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= e((string) $row['nama']) ?></td>
                    <td><?= e((string) $row['nis']) ?></td>
                    <td><?= e((string) ($row['nama_kelas'] ?? '-')) ?></td>
                    <td><?= e((string) ($row['nama_mapel'] ?? '-')) ?></td>
                    <td><?= e((string) $row['hadir']) ?></td>
                    <td><?= e((string) $row['izin']) ?></td>
                    <td><?= e((string) $row['sakit']) ?></td>
                    <td><?= e((string) $row['alpa']) ?></td>
                    <td><?= e((string) $row['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($exportSection === '' || $exportSection === 'detail_absensi'): ?>
    <section>
        <h2>Detail Ketidakhadiran per Mata Pelajaran</h2>
        <table>
            <thead>
            <tr>
                <th>No.</th>
                <th>Tanggal</th>
                <th>Nama</th>
                <th>NIS</th>
                <th>Kelas</th>
                <th>Mata Pelajaran</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php $no = 1; foreach ($absensiDetailRows as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= e((string) $row['tanggal']) ?></td>
                    <td><?= e((string) $row['nama']) ?></td>
                    <td><?= e((string) $row['nis']) ?></td>
                    <td><?= e((string) ($row['nama_kelas'] ?? '-')) ?></td>
                    <td><?= e((string) ($row['nama_mapel'] ?? '-')) ?></td>
                    <td><?= e((string) ucfirst((string) $row['status'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($exportSection === '' || $exportSection === 'nilai'): ?>
    <section>
        <h2>Rekap Nilai</h2>
        <?php if ($role === 'siswa'): ?>
            <table>
                <thead>
                <tr>
                    <th>No.</th>
                    <th>Mata Pelajaran</th>
                    <th>Tugas</th>
                    <th>Kuis</th>
                    <th>UTS</th>
                    <th>UAS</th>
                </tr>
                </thead>
                <tbody>
                <?php $no = 1; foreach ($nilaiSiswaMatrix as $namaMapel => $nilaiMapel): ?>
                    <tr>
                        <td><?= $no++ ?></td>
                        <td><?= e((string) $namaMapel) ?></td>
                        <?php
                        $tugasItems = [];
                        foreach ($nilaiMapel['tugas'] as $index => $nilaiTugas) {
                            $tugasItems[] = 'Tugas ' . ((string) ($index + 1)) . ': ' . number_format((float) $nilaiTugas, 2);
                        }
                        $kuisItems = [];
                        foreach ($nilaiMapel['kuis'] as $index => $nilaiKuis) {
                            $kuisItems[] = 'Kuis ' . ((string) ($index + 1)) . ': ' . number_format((float) $nilaiKuis, 2);
                        }
                        ?>
                        <td><?= $tugasItems ? e(implode(' | ', $tugasItems)) : '-' ?></td>
                        <td><?= $kuisItems ? e(implode(' | ', $kuisItems)) : '-' ?></td>
                        <td><?= $nilaiMapel['uts'] !== null ? e(number_format((float) $nilaiMapel['uts'], 2)) : '-' ?></td>
                        <td><?= $nilaiMapel['uas'] !== null ? e(number_format((float) $nilaiMapel['uas'], 2)) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>No.</th>
                    <th>Nama</th>
                    <th>NIS</th>
                    <th>Kelas</th>
                    <th>Mata Pelajaran</th>
                    <th>Nilai</th>
                </tr>
                </thead>
                <tbody>
                <?php $no = 1; foreach ($nilaiRows as $row): ?>
                    <tr>
                        <td><?= $no++ ?></td>
                        <td><?= e((string) $row['nama']) ?></td>
                        <td><?= e((string) $row['nis']) ?></td>
                        <td><?= e((string) ($row['nama_kelas'] ?? '-')) ?></td>
                        <td><?= e((string) ($row['nama_mapel'] ?? '-')) ?></td>
                        <td><?= $row['rata_nilai'] !== null ? e(number_format((float) $row['rata_nilai'], 2)) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($role === 'siswa' && ($exportSection === '' || $exportSection === 'notifikasi')): ?>
    <section>
        <h2>Notifikasi Saya</h2>
        <table>
            <thead>
            <tr>
                <th>No.</th>
                <th>Tanggal</th>
                <th>Pesan</th>
            </tr>
            </thead>
            <tbody>
            <?php $no = 1; foreach ($notifs as $notif): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= e((string) $notif['tanggal']) ?></td>
                    <td><?= e((string) $notif['pesan']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if (!$printEmbedded): ?>
    <script>
        (function () {
            var returnUrl = <?= json_encode($returnUrl, JSON_UNESCAPED_SLASHES) ?>;
            var hasHandledClose = false;

            function finishPrintFlow() {
                if (hasHandledClose) {
                    return;
                }
                hasHandledClose = true;

                if (window.opener && !window.opener.closed) {
                    window.close();
                    return;
                }

                window.location.replace(returnUrl);
            }

            window.addEventListener('afterprint', function () {
                finishPrintFlow();
            });

            window.addEventListener('load', function () {
                window.print();
            });

            // Fallback untuk browser yang tidak selalu men-trigger afterprint.
            window.addEventListener('focus', function () {
                setTimeout(function () {
                    if (!document.hidden) {
                        finishPrintFlow();
                    }
                }, 300);
            });
        })();
    </script>
    <?php endif; ?>
</body>
</html>
<?php
    exit;
}

$title = 'Laporan Akademik';
include LAYOUT_PATH . '/header.php';
?>
<div class="content-panels report-stack">
<section class="card panel-span-12 page-hero">
    <h2>Laporan Absensi dan Nilai Akademik</h2>
    <p class="page-lead">Gunakan filter untuk melihat laporan per kelas, mata pelajaran, dan periode dengan lebih terarah.</p>
    <div class="action-links">
        <a class="action-link" href="<?= e(report_url([
            'id_kelas' => $filterKelas,
            'id_mapel' => $filterMapel,
            'periode' => $filterPeriode,
            'export' => 'excel',
        ])) ?>">Export Semua (Excel)</a>
        <a class="action-link js-open-print-modal" href="<?= e(report_url([
            'id_kelas' => $filterKelas,
            'id_mapel' => $filterMapel,
            'periode' => $filterPeriode,
            'print' => '1',
        ])) ?>" rel="noopener">Cetak Semua (PDF)</a>
    </div>
</section>

<?php if (in_array($role, ['admin', 'guru'], true)): ?>
    <form method="get" class="filter-toolbar panel-span-12" style="background: var(--surface); margin-bottom: 0;">
        <div class="filter-field">
            <label for="id_kelas">Kelas</label>
            <select id="id_kelas" name="id_kelas">
                <option value="0">Semua Kelas</option>
                <?php foreach ($kelasList as $kelas): ?>
                    <option value="<?= e((string) $kelas['id_kelas']) ?>" <?= (int) $kelas['id_kelas'] === $filterKelas ? 'selected' : '' ?>>
                        <?= e($kelas['nama_kelas']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="id_mapel">Mata Pelajaran</label>
            <select id="id_mapel" name="id_mapel">
                <option value="0">Semua Mata Pelajaran</option>
                <?php foreach ($mapelList as $mapel): ?>
                    <option value="<?= e((string) $mapel['id_mapel']) ?>" <?= (int) $mapel['id_mapel'] === $filterMapel ? 'selected' : '' ?>>
                        <?= e($mapel['nama_mapel']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="periode">Periode</label>
            <select id="periode" name="periode">
                <option value="">Semua Periode</option>
                <?php foreach ($periodeList as $periodeOption): ?>
                    <option value="<?= e((string) $periodeOption) ?>" <?= (string) $periodeOption === $filterPeriode ? 'selected' : '' ?>>
                        <?= e((string) $periodeOption) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit">Terapkan Filter</button>
            <a class="action-link" href="<?= e(url('laporan.php')) ?>">Reset Filter</a>
        </div>
    </form>
<?php endif; ?>

<section class="card panel-span-12 report-summary-card">
    <div class="section-heading">
        <div>
            <h2>Ringkasan Laporan</h2>
            <p class="footer-note">Snapshot cepat dari data pada filter yang sedang aktif.</p>
        </div>
        <span class="badge"><?= e($role === 'siswa' ? 'Personal' : 'Akademik') ?></span>
    </div>
    <div class="report-summary-grid">
        <div class="report-summary-item primary">
            <span>Kehadiran</span>
            <strong><?= e((string) $summaryPersenHadir) ?>%</strong>
            <div class="summary-meter"><span style="width: <?= e((string) min(100, max(0, $summaryPersenHadir))) ?>%"></span></div>
            <small><?= e((string) $summaryHadir) ?> hadir dari <?= e((string) $summaryTotalAbsensi) ?> catatan</small>
        </div>
        <div class="report-summary-item">
            <span>Ketidakhadiran</span>
            <strong><?= e((string) $summaryTidakHadir) ?></strong>
            <small>Izin <?= e((string) $summaryIzin) ?>, sakit <?= e((string) $summarySakit) ?>, alpa <?= e((string) $summaryAlpa) ?></small>
        </div>
        <div class="report-summary-item">
            <span>Rata-rata Nilai</span>
            <strong><?= e(number_format($summaryRataNilai, 1)) ?></strong>
            <small><?= e((string) $summaryNilaiCount) ?> data nilai terhitung</small>
        </div>
        <div class="report-summary-item">
            <span>Mata Pelajaran</span>
            <strong><?= e((string) $summaryMapelCount) ?></strong>
            <small>Dengan data kehadiran pada filter ini</small>
        </div>
    </div>
</section>

<section class="card panel-span-12">
    <div class="section-heading">
        <div>
            <h2>Rekap Kehadiran</h2>
            <p class="footer-note">Ringkasan status hadir, izin, sakit, dan alpa. Total = jumlah semua rekaman kehadiran.</p>
        </div>
        <?= micro_export_buttons('absensi', $filterKelas, $filterMapel, $filterPeriode) ?>
    </div>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th class="text-center" style="width: 40px;">No.</th>
            <th>Nama</th>
            <th class="text-center">NIS</th>
            <th class="text-center">Kelas</th>
            <th class="text-center">Hadir</th>
            <th class="text-center">Izin</th>
            <th class="text-center">Sakit</th>
            <th class="text-center">Alpa</th>
            <th class="text-center">Total</th>
        </tr>
        </thead>
        <tbody>
        <?php $no = 1; foreach ($absensiRows as $row): ?>
            <tr>
                <td class="text-center"><?= $no++ ?></td>
                <td><?= e($row['nama']) ?></td>
                <td class="text-center"><?= e($row['nis']) ?></td>
                <td class="text-center"><span class="badge"><?= e($row['nama_kelas'] ?? '-') ?></span></td>
                <td class="text-center"><?= e((string) $row['hadir']) ?></td>
                <td class="text-center"><?= e((string) $row['izin']) ?></td>
                <td class="text-center"><?= e((string) $row['sakit']) ?></td>
                <td class="text-center"><?= e((string) $row['alpa']) ?></td>
                <td class="text-center"><strong><?= e((string) $row['total']) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card panel-span-12">
    <div class="section-heading">
        <div>
            <h2>Rekap Kehadiran per Mata Pelajaran</h2>
            <p class="footer-note">Kehadiran dikelompokkan berdasarkan siswa dan mata pelajaran agar evaluasi per mapel lebih akurat.</p>
        </div>
        <?= micro_export_buttons('absensi_mapel', $filterKelas, $filterMapel, $filterPeriode) ?>
    </div>
    <?php if (!$absensiMapelRows): ?>
        <div class="empty-state">
            <strong>Belum ada data kehadiran per mata pelajaran</strong>
            <p>Data akan tampil setelah guru menginput absensi berdasarkan kelas dan mata pelajaran.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th class="text-center" style="width: 40px;">No.</th>
                <th>Nama</th>
                <th class="text-center">NIS</th>
                <th class="text-center">Kelas</th>
                <th>Mata Pelajaran</th>
                <th class="text-center">Hadir</th>
                <th class="text-center">Izin</th>
                <th class="text-center">Sakit</th>
                <th class="text-center">Alpa</th>
                <th class="text-center">Total</th>
            </tr>
            </thead>
            <tbody>
            <?php $no = 1; foreach ($absensiMapelRows as $row): ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><?= e($row['nama']) ?></td>
                    <td class="text-center"><?= e($row['nis']) ?></td>
                    <td class="text-center"><span class="badge"><?= e($row['nama_kelas'] ?? '-') ?></span></td>
                    <td><?= e($row['nama_mapel'] ?? '-') ?></td>
                    <td class="text-center"><?= e((string) $row['hadir']) ?></td>
                    <td class="text-center"><?= e((string) $row['izin']) ?></td>
                    <td class="text-center"><?= e((string) $row['sakit']) ?></td>
                    <td class="text-center"><?= e((string) $row['alpa']) ?></td>
                    <td class="text-center"><strong><?= e((string) $row['total']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<section class="card panel-span-12">
    <div class="section-heading">
        <div>
            <h2>Detail Ketidakhadiran per Mata Pelajaran</h2>
            <p class="footer-note">Menampilkan catatan tidak hadir (izin, sakit, alpa) beserta mata pelajaran.</p>
        </div>
        <?= micro_export_buttons('detail_absensi', $filterKelas, $filterMapel, $filterPeriode) ?>
    </div>
    <?php if (!$absensiDetailRows): ?>
        <div class="empty-state">
            <strong>Tidak ada data ketidakhadiran</strong>
            <p>Semua siswa hadir 100% pada filter yang Anda pilih saat ini.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th class="text-center" style="width: 40px;">No.</th>
                <th class="text-center">Tanggal</th>
                <th>Nama</th>
                <th class="text-center">NIS</th>
                <th class="text-center">Kelas</th>
                <th>Mata Pelajaran</th>
                <th class="text-center">Status</th>
            </tr>
            </thead>
            <tbody>
            <?php $no = 1; foreach ($absensiDetailRows as $row): ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td class="text-center"><?= e(date('d M Y', strtotime($row['tanggal']))) ?></td>
                    <td><?= e($row['nama']) ?></td>
                    <td class="text-center"><?= e($row['nis']) ?></td>
                    <td class="text-center"><span class="badge"><?= e($row['nama_kelas'] ?? '-') ?></span></td>
                    <td><?= e($row['nama_mapel'] ?? '-') ?></td>
                    <td class="text-center">
                        <span class="badge <?= $row['status'] === 'alpa' ? 'danger' : 'warning' ?>">
                            <?= e(strtoupper((string) $row['status'])) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<section class="card panel-span-12">
    <div class="section-heading">
        <div>
            <h2>Rekap Nilai</h2>
            <?php if ($role === 'siswa'): ?>
                <p class="footer-note">Menampilkan nilai per mata pelajaran. Detail Tugas 1, 2, 3, dan seterusnya ada di kolom Tugas.</p>
            <?php else: ?>
                <p class="footer-note">Nilai per siswa dan mata pelajaran.</p>
            <?php endif; ?>
        </div>
        <?= micro_export_buttons('nilai', $filterKelas, $filterMapel, $filterPeriode) ?>
    </div>
    <?php if ($role === 'siswa'): ?>
        <?php if (!$nilaiSiswaMatrix): ?>
            <div class="empty-state">
                <strong>Belum ada data nilai</strong>
                <p>Tidak ada nilai yang tercatat untuk filter akademik saat ini.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="text-center" style="width: 40px;">No.</th>
                    <th>Mata Pelajaran</th>
                <th class="text-center">Tugas</th>
                <th class="text-center">Kuis</th>
                <th class="text-center">UTS</th>
                <th class="text-center">UAS</th>
                </tr>
                </thead>
                <tbody>
                <?php $no = 1; foreach ($nilaiSiswaMatrix as $namaMapel => $nilaiMapel): ?>
                    <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><strong><?= e($namaMapel) ?></strong></td>
                        <?php
                        $tugasItems = [];
                        foreach ($nilaiMapel['tugas'] as $index => $nilaiTugas) {
                            $tugasItems[] = 'Tugas ' . ((string) ($index + 1)) . ': ' . number_format((float) $nilaiTugas, 2);
                        }
                        $kuisItems = [];
                        foreach ($nilaiMapel['kuis'] as $index => $nilaiKuis) {
                            $kuisItems[] = 'Kuis ' . ((string) ($index + 1)) . ': ' . number_format((float) $nilaiKuis, 2);
                        }
                        ?>
                    <td class="text-center"><?= $tugasItems ? e(implode(' | ', $tugasItems)) : '-' ?></td>
                    <td class="text-center"><?= $kuisItems ? e(implode(' | ', $kuisItems)) : '-' ?></td>
                    <td class="text-center"><strong><?= $nilaiMapel['uts'] !== null ? e(number_format((float) $nilaiMapel['uts'], 2)) : '-' ?></strong></td>
                    <td class="text-center"><strong><?= $nilaiMapel['uas'] !== null ? e(number_format((float) $nilaiMapel['uas'], 2)) : '-' ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th class="text-center" style="width: 40px;">No.</th>
                <th>Nama</th>
            <th class="text-center">NIS</th>
            <th class="text-center">Kelas</th>
                <th>Mata Pelajaran</th>
            <th class="text-center">Rata-rata Nilai</th>
            </tr>
            </thead>
            <tbody>
            <?php $no = 1; foreach ($nilaiRows as $row): ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><?= e($row['nama']) ?></td>
                <td class="text-center"><?= e($row['nis']) ?></td>
                <td class="text-center"><span class="badge"><?= e($row['nama_kelas'] ?? '-') ?></span></td>
                    <td><?= e($row['nama_mapel'] ?? '-') ?></td>
                <td class="text-center"><strong><?= $row['rata_nilai'] !== null ? e(number_format((float) $row['rata_nilai'], 2)) : '-' ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($role === 'siswa'): ?>
<section class="card panel-span-12" id="notifikasi-saya">
    <div class="section-heading">
        <div>
            <h2>Notifikasi Saya</h2>
            <p class="footer-note">Informasi peringatan akademik dan kedisiplinan terbaru.</p>
        </div>
        <?= micro_export_buttons('notifikasi', $filterKelas, $filterMapel, $filterPeriode) ?>
    </div>
    <?php if (!$notifs): ?>
        <div class="empty-state">
            <strong>Belum ada notifikasi</strong>
            <p>Tidak ada peringatan akademik terbaru untuk Anda.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th class="text-center" style="width: 40px;">No.</th>
                <th>Tanggal</th>
                <th>Pesan</th>
            </tr>
            </thead>
            <tbody>
            <?php $no = 1; foreach ($notifs as $notif): ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><?= e($notif['tanggal']) ?></td>
                    <td><?= e($notif['pesan']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
</div>
<div id="print-preview-modal" class="print-preview-modal">
    <div id="print-preview-backdrop" class="print-preview-backdrop"></div>
    <div role="dialog" aria-modal="true" aria-label="Preview mode cetak" class="print-preview-dialog">
        <div class="print-preview-header">
            <strong>Mode Cetak</strong>
            <div class="print-preview-actions">
                <button type="button" id="print-preview-run" class="btn-compact">Cetak Sekarang</button>
                <button type="button" id="print-preview-close" class="btn-compact danger">Tutup</button>
            </div>
        </div>
        <iframe id="print-preview-frame" title="Preview Cetak" class="print-preview-frame"></iframe>
    </div>
</div>
<script>
(() => {
    const printLinks = document.querySelectorAll('a.js-open-print-modal');
    const modal = document.getElementById('print-preview-modal');
    const frame = document.getElementById('print-preview-frame');
    const closeBtn = document.getElementById('print-preview-close');
    const printBtn = document.getElementById('print-preview-run');
    const backdrop = document.getElementById('print-preview-backdrop');

    if (printLinks.length === 0 || !modal || !frame || !closeBtn || !printBtn || !backdrop) {
        return;
    }

    const openModal = (href) => {
        const url = new URL(href, window.location.origin);
        url.searchParams.set('embed', '1');
        frame.src = url.toString();
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };

    const closeModal = () => {
        modal.style.display = 'none';
        frame.src = 'about:blank';
        document.body.style.overflow = '';
    };

    printLinks.forEach(link => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            openModal(link.href);
        });
    });

    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);

    printBtn.addEventListener('click', () => {
        if (!frame.contentWindow) {
            return;
        }
        frame.contentWindow.focus();
        frame.contentWindow.print();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.style.display === 'flex') {
            closeModal();
        }
    });
})();
</script>
<?php include LAYOUT_PATH . '/footer.php'; ?>
