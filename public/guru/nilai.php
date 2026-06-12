<?php

declare(strict_types=1);

// Input nilai guru dengan pengiriman notifikasi jika skor di bawah KKM.
require_once __DIR__ . '/../../app/bootstrap.php';

require_role(['guru']);

$stmt = $pdo->prepare('SELECT id_guru, nama, jenis_kelamin FROM tabel_guru WHERE id_user = ? LIMIT 1');
$stmt->execute([current_user()['id_user']]);
$guru = $stmt->fetch();

if (!$guru) {
    die('Profil guru tidak ditemukan.');
}

$guruId = (int) $guru['id_guru'];
$message = '';
$error = '';

$stmt = $pdo->prepare('SELECT DISTINCT k.id_kelas, k.nama_kelas FROM tabel_jadwal j JOIN tabel_kelas k ON k.id_kelas = j.id_kelas WHERE j.id_guru = ? ORDER BY k.nama_kelas');
$stmt->execute([$guruId]);
$kelasList = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT k.id_kelas, k.nama_kelas, m.id_mapel, m.nama_mapel FROM tabel_jadwal j JOIN tabel_kelas k ON k.id_kelas = j.id_kelas JOIN tabel_mapel m ON m.id_mapel = j.id_mapel WHERE j.id_guru = ? ORDER BY k.nama_kelas, m.nama_mapel');
$stmt->execute([$guruId]);
$jadwalAjarRows = $stmt->fetchAll();

$mapelPerKelasById = [];
foreach ($jadwalAjarRows as $jadwalRow) {
    $idKelas = (int) ($jadwalRow['id_kelas'] ?? 0);
    $idMapel = (int) ($jadwalRow['id_mapel'] ?? 0);
    $namaMapel = (string) ($jadwalRow['nama_mapel'] ?? '-');

    if ($idKelas > 0 && $idMapel > 0) {
        if (!isset($mapelPerKelasById[$idKelas])) {
            $mapelPerKelasById[$idKelas] = [];
        }

        $exists = false;
        foreach ($mapelPerKelasById[$idKelas] as $mapelRow) {
            if ((int) $mapelRow['id_mapel'] === $idMapel) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $mapelPerKelasById[$idKelas][] = [
                'id_mapel' => $idMapel,
                'nama_mapel' => $namaMapel,
            ];
        }
    }
}

$allowedKelasIds = array_map(static fn(array $kelas): int => (int) $kelas['id_kelas'], $kelasList);

if (!$kelasList) {
    $error = 'Belum ada kelas yang diampu untuk guru ini.';
}

$selectedKelas = (int) (post_int('id_kelas', (int) ($_GET['id_kelas'] ?? ($kelasList[0]['id_kelas'] ?? 0))));
$allowedJenisPenilaian = ['tugas', 'kuis', 'uts', 'uas'];
$jenisInput = request_method_is('POST')
    ? post_string('jenis_penilaian', get_string('jenis_penilaian', 'tugas'))
    : get_string('jenis_penilaian', 'tugas');
$jenisValid = in_array($jenisInput, $allowedJenisPenilaian, true);
$jenis = $jenisValid ? $jenisInput : 'tugas';
$selectedKelasAllowed = $selectedKelas > 0 && in_array($selectedKelas, $allowedKelasIds, true);

if ($selectedKelas > 0 && !$selectedKelasAllowed && $error === '') {
    $error = 'Kelas yang dipilih tidak sesuai dengan jadwal guru.';
}

// Buat daftar pilihan otomatis dari 1 tahun ke depan mundur ke 3 tahun ke belakang
$baseYear = (int) date('Y');
$periodeOptions = [];
for ($y = $baseYear + 1; $y >= $baseYear - 3; $y--) {
    $periodeOptions[] = $y . ' Genap';
    $periodeOptions[] = $y . ' Ganjil';
}

// Gabungkan dengan periode lama yang mungkin sudah ada di tabel nilai agar sejarah tidak hilang
$periodeExisting = $pdo->query('SELECT DISTINCT periode FROM tabel_nilai ORDER BY periode DESC')->fetchAll(PDO::FETCH_COLUMN);
$periodeOptions = array_values(array_unique(array_merge($periodeOptions, $periodeExisting)));

// Tebak periode aktif berdasarkan bulan (Juli-Des = Ganjil, Jan-Jun = Genap)
$tebakanPeriode = date('Y') . ((int) date('n') >= 7 ? ' Ganjil' : ' Genap');
$periode = post_string('periode', get_string('periode', $tebakanPeriode));
if ($periode !== '' && !in_array($periode, $periodeOptions, true)) {
    array_unshift($periodeOptions, $periode);
}

$mapelList = $selectedKelasAllowed ? ($mapelPerKelasById[$selectedKelas] ?? []) : [];

if ($selectedKelasAllowed && !$mapelList && $error === '') {
    $error = 'Belum ada mata pelajaran terjadwal untuk kelas yang dipilih.';
}

$allowedMapelIds = array_map(static fn(array $mapel): int => (int) $mapel['id_mapel'], $mapelList);
$selectedMapelInput = (int) (post_int('id_mapel', (int) ($_GET['id_mapel'] ?? ($mapelList[0]['id_mapel'] ?? 0))));
$mapelIdValid = in_array($selectedMapelInput, $allowedMapelIds, true);
$selectedMapel = $selectedMapelInput;
if (!$mapelIdValid && !request_method_is('POST')) {
    $selectedMapel = (int) ($mapelList[0]['id_mapel'] ?? 0);
}
$selectedMapelAllowed = $selectedMapel > 0 && in_array($selectedMapel, $allowedMapelIds, true);

$siswaList = [];
if ($selectedKelasAllowed) {
    $stmt = $pdo->prepare('SELECT id_siswa, nama, nis FROM tabel_siswa WHERE id_kelas = ? ORDER BY nama ASC');
    $stmt->execute([$selectedKelas]);
    $siswaList = $stmt->fetchAll();
}
$allowedSiswaIds = array_map(static fn(array $siswa): int => (int) $siswa['id_siswa'], $siswaList);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $scores = $_POST['skor'] ?? [];

    if (!verify_csrf_token(post_string('csrf_token'))) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } elseif ($selectedKelas <= 0 || $selectedMapel <= 0 || $periode === '') {
        $error = 'Kelas, mata pelajaran, dan periode wajib diisi.';
    } elseif (!$selectedKelasAllowed) {
        $error = 'Kelas yang dipilih tidak sesuai dengan jadwal guru.';
    } elseif (!$mapelIdValid) {
        $error = 'Mata pelajaran yang dipilih tidak sesuai dengan kelas dan jadwal guru.';
    } elseif (!$jenisValid) {
        $error = 'Jenis penilaian tidak valid.';
    } elseif (!in_array($periode, $periodeOptions, true)) {
        $error = 'Periode tidak valid, silakan pilih dari daftar periode.';
    } else {
        $savedCount = 0;
        $stmtUpsert = $pdo->prepare(
            'INSERT INTO tabel_nilai (id_siswa, id_mapel, id_guru, jenis_penilaian, skor, periode) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE skor = VALUES(skor), id_guru = VALUES(id_guru)'
        );

        $stmtSiswaUser = $pdo->prepare('SELECT id_user FROM tabel_siswa WHERE id_siswa = ? LIMIT 1');
        $stmtNotif = $pdo->prepare('INSERT INTO tabel_notifikasi (id_user, pesan, tanggal) VALUES (?, ?, CURDATE())');
        $stmtCekNotif = $pdo->prepare('SELECT COUNT(*) FROM tabel_notifikasi WHERE id_user = ? AND pesan = ? AND tanggal = CURDATE()');

        foreach ($scores as $idSiswa => $skor) {
            if ($skor === '') {
                continue;
            }

            if (!in_array((int) $idSiswa, $allowedSiswaIds, true)) {
                continue;
            }

            $nilai = (float) $skor;
            if ($nilai < 0 || $nilai > 100) {
                continue;
            }

            $stmtUpsert->execute([(int) $idSiswa, $selectedMapel, $guruId, $jenis, $nilai, $periode]);
            $savedCount++;

            if ($nilai < 75) {
                $stmtSiswaUser->execute([(int) $idSiswa]);
                $siswaData = $stmtSiswaUser->fetch();

                $mapelNama = '-';
                foreach ($mapelList as $m) {
                    if ((int) $m['id_mapel'] === $selectedMapel) {
                        $mapelNama = (string) $m['nama_mapel'];
                        break;
                    }
                }

                $pesan = sprintf('Peringatan: Nilai %s (%s) = %.2f berada di bawah KKM.', $mapelNama, strtoupper($jenis), $nilai);

                if (!empty($siswaData['id_user'])) {
                    $stmtCekNotif->execute([(int) $siswaData['id_user'], $pesan]);
                    if ((int) $stmtCekNotif->fetchColumn() === 0) {
                        $stmtNotif->execute([(int) $siswaData['id_user'], $pesan]);
                    }
                }
            }
        }

        if ($savedCount > 0) {
            $message = sprintf('Data nilai berhasil disimpan (%d siswa).', $savedCount);
        } else {
            $error = 'Tidak ada nilai yang disimpan. Isi minimal satu skor valid (0-100).';
        }
    }
}

$nilaiExisting = [];
if ($selectedKelasAllowed && $selectedMapelAllowed && $periode !== '') {
    $stmt = $pdo->prepare('SELECT n.id_siswa, n.skor FROM tabel_nilai n JOIN tabel_siswa s ON s.id_siswa = n.id_siswa WHERE s.id_kelas = ? AND n.id_mapel = ? AND n.jenis_penilaian = ? AND n.periode = ?');
    $stmt->execute([$selectedKelas, $selectedMapel, $jenis, $periode]);
    foreach ($stmt->fetchAll() as $row) {
        $nilaiExisting[(int) $row['id_siswa']] = $row['skor'];
    }
}

$title = 'Input Nilai';
include LAYOUT_PATH . '/header.php';
?>
<div class="card page-hero">
    <h2>Input Nilai Siswa</h2>
    <p class="page-lead">Silakan pilih kelas, mata pelajaran, jenis penilaian, dan periode untuk memasukkan nilai.</p>
</div>
<section class="card">

    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="get" class="filter-toolbar">
        <div class="filter-field">
            <label for="id_kelas">Kelas</label>
            <select id="id_kelas" name="id_kelas" required>
                <?php foreach ($kelasList as $kelas): ?>
                    <option value="<?= e((string) $kelas['id_kelas']) ?>" <?= (int) $kelas['id_kelas'] === $selectedKelas ? 'selected' : '' ?>>
                        <?= e($kelas['nama_kelas']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-field">
            <label for="id_mapel">Mata Pelajaran</label>
            <select id="id_mapel" name="id_mapel" required <?= !$mapelList ? 'disabled' : '' ?>>
                <?php foreach ($mapelList as $mapel): ?>
                    <option value="<?= e((string) $mapel['id_mapel']) ?>" <?= (int) $mapel['id_mapel'] === $selectedMapel ? 'selected' : '' ?>>
                        <?= e($mapel['nama_mapel']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-field">
            <label for="jenis_penilaian">Jenis Penilaian</label>
            <select id="jenis_penilaian" name="jenis_penilaian" required>
                <option value="tugas" <?= $jenis === 'tugas' ? 'selected' : '' ?>>Tugas</option>
                <option value="kuis" <?= $jenis === 'kuis' ? 'selected' : '' ?>>Kuis</option>
                <option value="uts" <?= $jenis === 'uts' ? 'selected' : '' ?>>UTS</option>
                <option value="uas" <?= $jenis === 'uas' ? 'selected' : '' ?>>UAS</option>
            </select>
        </div>

        <div class="filter-field">
            <label for="periode">Periode</label>
            <select id="periode" name="periode" required>
                <?php foreach ($periodeOptions as $periodeOption): ?>
                    <option value="<?= e($periodeOption) ?>" <?= $periodeOption === $periode ? 'selected' : '' ?>><?= e($periodeOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-actions" style="margin-left: auto;">
            <button type="submit">Tampilkan Daftar Siswa</button>
        </div>
    </form>

    <?php if ($selectedKelasAllowed && $selectedMapelAllowed): ?>
    <?php if (!$siswaList): ?>
        <div class="empty-state">
            <strong>Belum ada siswa di kelas ini</strong>
            <p>Hubungi admin untuk menambahkan siswa ke kelas sebelum nilai dapat disimpan.</p>
        </div>
    <?php else: ?>
    <form method="post" class="grid nilai-form" style="margin-top: 24px;">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id_kelas" value="<?= e((string) $selectedKelas) ?>">
        <input type="hidden" name="id_mapel" value="<?= e((string) $selectedMapel) ?>">
        <input type="hidden" name="jenis_penilaian" value="<?= e($jenis) ?>">
        <input type="hidden" name="periode" value="<?= e($periode) ?>">

        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>NIS</th>
                <th>Nama</th>
                <th>Skor (0-100)</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($siswaList as $siswa): ?>
                <tr>
                    <td><?= e($siswa['nis']) ?></td>
                    <td><?= e($siswa['nama']) ?></td>
                    <td>
                        <input type="number" min="0" max="100" step="0.01" name="skor[<?= e((string) $siswa['id_siswa']) ?>]" value="<?= e((string) ($nilaiExisting[(int) $siswa['id_siswa']] ?? '')) ?>">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div class="nilai-actions">
            <button class="btn-compact" type="submit" <?= !$kelasList || !$mapelList ? 'disabled' : '' ?>>Simpan Nilai</button>
        </div>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php include LAYOUT_PATH . '/footer.php'; ?>
