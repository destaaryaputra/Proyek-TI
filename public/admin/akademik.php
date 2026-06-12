<?php

declare(strict_types=1);

// Modul admin untuk kelola data akademik dan refresh database dari file SQL.
require_once __DIR__ . '/../../app/bootstrap.php';

require_role(['admin']);

$message = '';
$error = '';
$editKelasId = get_int('edit_kelas');
$editMapelId = get_int('edit_mapel');
$editJadwalId = get_int('edit_jadwal');

/**
 * Jalankan file SQL seperti import phpMyAdmin menggunakan mysqli multi_query.
 */
// Import file SQL menggunakan multi_query supaya bisa memproses DROP, CREATE, dan INSERT sekaligus.
function run_sql_file(mysqli $mysqli, string $filePath): void
{
    if (!is_file($filePath)) {
        throw new RuntimeException('File SQL tidak ditemukan: ' . $filePath);
    }

    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException('Gagal membaca file SQL: ' . $filePath);
    }

    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $sql = trim((string) $sql);

    if ($sql === '') {
        return;
    }

    if (!$mysqli->multi_query($sql)) {
        throw new RuntimeException('Gagal menjalankan file SQL: ' . $filePath);
    }

    do {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());

    if ($mysqli->errno !== 0) {
        throw new RuntimeException('MySQL error saat import file SQL: ' . $mysqli->error);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token(post_string('csrf_token'))) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $action = post_string('action');

        try {
        if ($action === 'reset_data') {
            if (APP_ENV !== 'development') {
                throw new RuntimeException('Reset database hanya tersedia pada mode development.');
            }

            // Update database berarti jalankan ulang skema dan seed data dari folder database.
            $confirmText = post_string('confirm_reset');
            if ($confirmText !== 'RESET') {
                throw new RuntimeException('Untuk reset data, ketik RESET dengan huruf kapital.');
            }

            $schemaPath = DATABASE_PATH . '/schema.sql';
            $seedPath = DATABASE_PATH . '/seed.sql';

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            global $host, $dbUser, $dbPass;
            $mysqli = new mysqli($host ?? '127.0.0.1', $dbUser ?? 'root', $dbPass ?? '', 'mysql');
            $mysqli->set_charset('utf8mb4');

            run_sql_file($mysqli, $schemaPath);
            run_sql_file($mysqli, $seedPath);

            $mysqli->close();

            $message = 'Reset data berhasil. Database sudah diisi ulang dari migration dan seeder awal.';
        }

        if ($action === 'delete_kelas') {
            // Hapus kelas; siswa yang terkait akan mengikuti aturan foreign key database.
            $idKelas = post_int('id_kelas');
            if ($idKelas <= 0) {
                throw new RuntimeException('ID kelas tidak valid.');
            }
            $stmt = $pdo->prepare('DELETE FROM tabel_kelas WHERE id_kelas = ?');
            $stmt->execute([$idKelas]);
            $message = 'Data kelas berhasil dihapus.';
        }

        if ($action === 'delete_mapel') {
            // Hapus mapel; relasi jadwal, kehadiran, dan nilai bisa ikut terdampak.
            $idMapel = post_int('id_mapel');
            if ($idMapel <= 0) {
                throw new RuntimeException('ID mata pelajaran tidak valid.');
            }
            $stmt = $pdo->prepare('DELETE FROM tabel_mapel WHERE id_mapel = ?');
            $stmt->execute([$idMapel]);
            $message = 'Data mata pelajaran berhasil dihapus.';
        }

        if ($action === 'delete_jadwal') {
            // Hapus jadwal tanpa mengubah master data kelas/guru/mapel.
            $idJadwal = post_int('id_jadwal');
            if ($idJadwal <= 0) {
                throw new RuntimeException('ID jadwal tidak valid.');
            }
            $stmt = $pdo->prepare('DELETE FROM tabel_jadwal WHERE id_jadwal = ?');
            $stmt->execute([$idJadwal]);
            $message = 'Jadwal berhasil dihapus.';
        }

        if ($action === 'add_kelas') {
            $namaKelas = post_string('nama_kelas');
            $tingkat = post_string('tingkat');
            if ($namaKelas === '' || $tingkat === '') {
                throw new RuntimeException('Nama kelas dan tingkat wajib diisi.');
            }
            $stmt = $pdo->prepare('INSERT INTO tabel_kelas (nama_kelas, tingkat) VALUES (?, ?)');
            $stmt->execute([$namaKelas, $tingkat]);
            $message = 'Data kelas berhasil ditambahkan.';
        }

        if ($action === 'add_mapel') {
            $namaMapel = post_string('nama_mapel');
            if ($namaMapel === '') {
                throw new RuntimeException('Nama mata pelajaran wajib diisi.');
            }
            $stmt = $pdo->prepare('INSERT INTO tabel_mapel (nama_mapel) VALUES (?)');
            $stmt->execute([$namaMapel]);
            $message = 'Data mata pelajaran berhasil ditambahkan.';
        }

        if ($action === 'add_jadwal') {
            $idGuru = post_int('id_guru');
            $idKelas = post_int('id_kelas');
            $idMapel = post_int('id_mapel');
            $hari = post_string('hari');
            $jam = post_string('jam');
            if ($idGuru <= 0 || $idKelas <= 0 || $idMapel <= 0 || $hari === '' || $jam === '') {
                throw new RuntimeException('Semua field jadwal wajib diisi.');
            }
            $stmt = $pdo->prepare('INSERT INTO tabel_jadwal (id_guru, id_kelas, id_mapel, hari, jam) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$idGuru, $idKelas, $idMapel, $hari, $jam]);
            $message = 'Jadwal berhasil ditambahkan.';
        }

        if ($action === 'update_kelas') {
            $idKelas = post_int('id_kelas');
            $namaKelas = post_string('nama_kelas');
            $tingkat = post_string('tingkat');
            if ($idKelas <= 0 || $namaKelas === '' || $tingkat === '') {
                throw new RuntimeException('Nama kelas dan tingkat wajib diisi untuk update.');
            }
            $stmt = $pdo->prepare('UPDATE tabel_kelas SET nama_kelas = ?, tingkat = ? WHERE id_kelas = ?');
            $stmt->execute([$namaKelas, $tingkat, $idKelas]);

            if (is_ajax_request()) {
                $stmt = $pdo->prepare('SELECT * FROM tabel_kelas WHERE id_kelas = ?');
                $stmt->execute([$idKelas]);
                $updated = $stmt->fetch();
                ob_start();
                ?>
                <td class="text-center">-</td>
                <td><?= e((string) $updated['id_kelas']) ?></td>
                <td><?= e($updated['nama_kelas']) ?></td>
                <td><?= e($updated['tingkat']) ?></td>
                <td class="text-center">
                    <div style="display: flex; gap: 8px;">
                        <a href="?edit_kelas=<?= $updated['id_kelas'] ?>#daftar-kelas" class="btn-compact" style="background: var(--warning); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Edit</a>
                        <form method="post" onsubmit="return confirm('Hapus kelas ini? Siswa akan tetap ada tetapi kelasnya akan kosong.');" style="margin: 0;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_kelas">
                            <input type="hidden" name="id_kelas" value="<?= e((string) $updated['id_kelas']) ?>">
                            <button type="submit" class="danger" style="height: 32px; padding: 0 12px; font-size: 12px;">Hapus</button>
                        </form>
                    </div>
                </td>
                <?php
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Data kelas berhasil diperbarui.', 'html' => ob_get_clean()]);
                exit;
            }
            $message = 'Data kelas berhasil diperbarui.';
        }

        if ($action === 'update_mapel') {
            $idMapel = post_int('id_mapel');
            $namaMapel = post_string('nama_mapel');
            if ($idMapel <= 0 || $namaMapel === '') {
                throw new RuntimeException('Nama mata pelajaran wajib diisi untuk update.');
            }
            $stmt = $pdo->prepare('UPDATE tabel_mapel SET nama_mapel = ? WHERE id_mapel = ?');
            $stmt->execute([$namaMapel, $idMapel]);

            if (is_ajax_request()) {
                $stmt = $pdo->prepare('SELECT * FROM tabel_mapel WHERE id_mapel = ?');
                $stmt->execute([$idMapel]);
                $updated = $stmt->fetch();
                ob_start();
                ?>
                <td class="text-center">-</td>
                <td><?= e((string) $updated['id_mapel']) ?></td>
                <td><?= e($updated['nama_mapel']) ?></td>
                <td class="text-center">
                    <div style="display: flex; gap: 8px;">
                        <a href="?edit_mapel=<?= $updated['id_mapel'] ?>#daftar-mapel" class="btn-compact" style="background: var(--warning); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Edit</a>
                        <form method="post" onsubmit="return confirm('Hapus mata pelajaran ini? Data jadwal, kehadiran, dan nilai terkait akan ikut terhapus.');" style="margin: 0;"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_mapel"><input type="hidden" name="id_mapel" value="<?= e((string) $updated['id_mapel']) ?>"><button type="submit" class="danger" style="height: 32px; padding: 0 12px; font-size: 12px;">Hapus</button></form>
                    </div>
                </td>
                <?php
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Data mata pelajaran berhasil diperbarui.', 'html' => ob_get_clean()]);
                exit;
            }
            $message = 'Data mata pelajaran berhasil diperbarui.';
        }

        if ($action === 'update_jadwal') {
            $idJadwal = post_int('id_jadwal');
            $idGuru = post_int('id_guru');
            $idKelas = post_int('id_kelas');
            $idMapel = post_int('id_mapel');
            $hari = post_string('hari');
            $jam = post_string('jam');
            if ($idJadwal <= 0 || $idGuru <= 0 || $idKelas <= 0 || $idMapel <= 0 || $hari === '' || $jam === '') {
                throw new RuntimeException('Semua field jadwal wajib diisi untuk update.');
            }
            $stmt = $pdo->prepare('UPDATE tabel_jadwal SET id_guru = ?, id_kelas = ?, id_mapel = ?, hari = ?, jam = ? WHERE id_jadwal = ?');
            $stmt->execute([$idGuru, $idKelas, $idMapel, $hari, $jam, $idJadwal]);

            if (is_ajax_request()) {
                $stmt = $pdo->prepare('SELECT j.id_jadwal, g.nama AS guru, k.nama_kelas, m.nama_mapel, j.hari, j.jam FROM tabel_jadwal j JOIN tabel_guru g ON g.id_guru = j.id_guru JOIN tabel_kelas k ON k.id_kelas = j.id_kelas JOIN tabel_mapel m ON m.id_mapel = j.id_mapel WHERE j.id_jadwal = ?');
                $stmt->execute([$idJadwal]);
                $updated = $stmt->fetch();
                ob_start();
                ?>
                <td class="text-center">-</td>
                <td><?= e((string) $updated['id_jadwal']) ?></td>
                <td><?= e($updated['guru']) ?></td>
                <td><?= e($updated['nama_kelas']) ?></td>
                <td><?= e($updated['nama_mapel']) ?></td>
                <td><?= e($updated['hari']) ?></td>
                <td><?= e($updated['jam']) ?></td>
                <td class="text-center">
                    <div style="display: flex; gap: 8px;">
                        <a href="?edit_jadwal=<?= $updated['id_jadwal'] ?>#daftar-jadwal" class="btn-compact" style="background: var(--warning); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Edit</a>
                        <form method="post" onsubmit="return confirm('Hapus jadwal ini?');" style="margin: 0;"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_jadwal"><input type="hidden" name="id_jadwal" value="<?= e((string) $updated['id_jadwal']) ?>"><button type="submit" class="danger" style="height: 32px; padding: 0 12px; font-size: 12px;">Hapus</button></form>
                    </div>
                </td>
                <?php
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Jadwal berhasil diperbarui.', 'html' => ob_get_clean()]);
                exit;
            }
            $message = 'Jadwal berhasil diperbarui.';
        }
        } catch (Throwable $e) {
            $error = 'Operasi data akademik gagal diproses.';
        }
    }
}

$kelasList = $pdo->query('SELECT id_kelas, nama_kelas, tingkat FROM tabel_kelas ORDER BY nama_kelas ASC')->fetchAll();
$mapelList = $pdo->query('SELECT id_mapel, nama_mapel FROM tabel_mapel ORDER BY nama_mapel ASC')->fetchAll();
$guruList = $pdo->query('SELECT id_guru, nama FROM tabel_guru ORDER BY nama ASC')->fetchAll();

$jadwalList = $pdo->query(
    'SELECT j.id_jadwal, j.id_guru, j.id_kelas, j.id_mapel, g.nama AS guru, k.nama_kelas, m.nama_mapel, j.hari, j.jam
     FROM tabel_jadwal j
     JOIN tabel_guru g ON g.id_guru = j.id_guru
     JOIN tabel_kelas k ON k.id_kelas = j.id_kelas
     JOIN tabel_mapel m ON m.id_mapel = j.id_mapel
    ORDER BY k.nama_kelas ASC, FIELD(j.hari, "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"), j.jam ASC'
)->fetchAll();

$title = 'Data Akademik';
include LAYOUT_PATH . '/header.php';
?>
<section class="card page-hero">
    <h2>Manajemen Data Akademik</h2>
    <p class="page-lead">Kelola data master akademik yang meliputi daftar kelas, mata pelajaran, dan jadwal pelajaran.</p>
    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>
</section>

<?php if (APP_ENV === 'development'): ?>
<section class="card">
    <h2>Perbarui Basis Data</h2>
    <p>Fitur development untuk memformat ulang database dan mengembalikannya ke kondisi awal sesuai data seed sistem.</p>
    <form method="post" class="grid reset-inline-form" onsubmit="return confirm('Yakin memperbarui basis data? Tindakan ini tidak bisa dibatalkan.');">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset_data">
        <div>
            <label for="confirm_reset">Konfirmasi (ketik RESET)</label>
            <input id="confirm_reset" name="confirm_reset" placeholder="RESET" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="danger btn-compact">Perbarui Basis Data</button>
        </div>
    </form>
</section>
<?php endif; ?>

<div class="content-panels akademik-add-panels">
    <section class="card panel-span-12" id="tambah-kelas">
        <h2>Tambah Kelas</h2>
        <form method="post" class="reset-inline-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_kelas">
            <div>
                <label for="nama_kelas">Nama Kelas</label>
                <input id="nama_kelas" name="nama_kelas" required>
            </div>
            <div>
                <label for="tingkat">Tingkat</label>
                <select id="tingkat" name="tingkat" required>
                    <option value="7">7</option>
                    <option value="8">8</option>
                    <option value="9">9</option>
                    <option value="10">10</option>
                    <option value="11">11</option>
                    <option value="12">12</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-compact">Simpan Kelas</button>
            </div>
        </form>
    </section>

    <section class="card panel-span-12" id="tambah-mapel">
        <h2>Tambah Mata Pelajaran</h2>
        <form method="post" class="reset-inline-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_mapel">
            <div>
                <label for="nama_mapel">Nama Mata Pelajaran</label>
                <input id="nama_mapel" name="nama_mapel" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-compact">Simpan Mata Pelajaran</button>
            </div>
        </form>
    </section>
</div>

<section class="card" id="tambah-jadwal">
    <h2>Tambah Jadwal</h2>
    <form method="post" class="reset-inline-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_jadwal">
        <div>
            <label for="id_guru">Guru</label>
            <select id="id_guru" name="id_guru" required>
                <option value="">- Pilih Guru -</option>
                <?php foreach ($guruList as $guru): ?>
                    <option value="<?= e((string) $guru['id_guru']) ?>"><?= e($guru['nama']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="id_kelas">Kelas</label>
            <select id="id_kelas" name="id_kelas" required>
                <option value="">- Pilih Kelas -</option>
                <?php foreach ($kelasList as $kelas): ?>
                    <option value="<?= e((string) $kelas['id_kelas']) ?>"><?= e($kelas['nama_kelas']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="id_mapel">Mata Pelajaran</label>
            <select id="id_mapel" name="id_mapel" required>
                <option value="">- Pilih Mata Pelajaran -</option>
                <?php foreach ($mapelList as $mapel): ?>
                    <option value="<?= e((string) $mapel['id_mapel']) ?>"><?= e($mapel['nama_mapel']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="hari">Hari</label>
            <select id="hari" name="hari" required>
                <option>Senin</option>
                <option>Selasa</option>
                <option>Rabu</option>
                <option>Kamis</option>
                <option>Jumat</option>
                <option>Sabtu</option>
            </select>
        </div>
        <div>
            <label for="jam">Jam</label>
            <input id="jam" name="jam" placeholder="07:30-09:00" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn-compact">Simpan Jadwal</button>
        </div>
    </form>
</section>

<div class="content-panels akademik-list-panels">
<section class="card panel-span-12 akademik-table-card" id="daftar-kelas">
    <h2>Daftar Kelas</h2>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th class="text-center" style="width: 40px;">No</th>
            <th>ID</th>
            <th>Nama Kelas</th>
            <th>Tingkat</th>
            <th class="text-center" style="width: 140px;">Aksi</th>
        </tr>
        </thead>
        <tbody>
        <?php $noKelas = 1; ?>
        <?php foreach ($kelasList as $kelas): ?>
            <?php $isEditing = $editKelasId === (int) $kelas['id_kelas']; ?>
            <tr>
                <td class="text-center"><?= e((string) $noKelas++) ?></td>
                <td><?= e((string) $kelas['id_kelas']) ?></td>
                <?php if ($isEditing): ?>
                    <td><input type="text" name="nama_kelas" value="<?= e($kelas['nama_kelas']) ?>" form="form-edit-kelas-<?= $kelas['id_kelas'] ?>" required style="height: 32px; padding: 4px 8px; width: 100%;"></td>
                    <td>
                        <select name="tingkat" form="form-edit-kelas-<?= $kelas['id_kelas'] ?>" required style="height: 32px; padding: 4px 8px; width: 100%;">
                            <?php foreach (['7', '8', '9', '10', '11', '12'] as $t): ?>
                                <option value="<?= $t ?>" <?= $kelas['tingkat'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="text-center">
                        <form id="form-edit-kelas-<?= $kelas['id_kelas'] ?>" method="post" style="margin: 0; display: inline-flex; gap: 8px;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_kelas">
                            <input type="hidden" name="id_kelas" value="<?= e((string) $kelas['id_kelas']) ?>">
                            <button type="submit" style="background: var(--success); height: 32px; padding: 0 12px; font-size: 12px;">Simpan</button>
                            <a href="?#daftar-kelas" class="btn-compact" style="background: var(--text-muted); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Batal</a>
                        </form>
                    </td>
                <?php else: ?>
                    <td><?= e($kelas['nama_kelas']) ?></td>
                    <td><?= e($kelas['tingkat']) ?></td>
                    <td class="text-center">
                        <div style="display: flex; gap: 8px;">
                            <a href="?edit_kelas=<?= $kelas['id_kelas'] ?>#daftar-kelas" class="btn-compact" style="background: var(--warning); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Edit</a>
                            <form method="post" onsubmit="return confirm('Hapus kelas ini? Siswa akan tetap ada tetapi kelasnya akan kosong.');" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_kelas">
                                <input type="hidden" name="id_kelas" value="<?= e((string) $kelas['id_kelas']) ?>">
                                <button type="submit" class="danger" style="height: 32px; padding: 0 12px; font-size: 12px;">Hapus</button>
                            </form>
                        </div>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card panel-span-12 akademik-table-card" id="daftar-mapel">
    <h2>Daftar Mata Pelajaran</h2>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th class="text-center" style="width: 40px;">No</th>
            <th>ID</th>
            <th>Mata Pelajaran</th>
            <th class="text-center" style="width: 140px;">Aksi</th>
        </tr>
        </thead>
        <tbody>
        <?php $noMapel = 1; ?>
        <?php foreach ($mapelList as $mapel): ?>
            <?php $isEditing = $editMapelId === (int) $mapel['id_mapel']; ?>
            <tr>
                <td class="text-center"><?= e((string) $noMapel++) ?></td>
                <td><?= e((string) $mapel['id_mapel']) ?></td>
                <?php if ($isEditing): ?>
                    <td><input type="text" name="nama_mapel" value="<?= e($mapel['nama_mapel']) ?>" form="form-edit-mapel-<?= $mapel['id_mapel'] ?>" required style="height: 32px; padding: 4px 8px; width: 100%;"></td>
                    <td class="text-center">
                        <form id="form-edit-mapel-<?= $mapel['id_mapel'] ?>" method="post" style="margin: 0; display: inline-flex; gap: 8px;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_mapel">
                            <input type="hidden" name="id_mapel" value="<?= e((string) $mapel['id_mapel']) ?>">
                            <button type="submit" style="background: var(--success); height: 32px; padding: 0 12px; font-size: 12px;">Simpan</button>
                            <a href="?#daftar-mapel" class="btn-compact" style="background: var(--text-muted); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Batal</a>
                        </form>
                    </td>
                <?php else: ?>
                    <td><?= e($mapel['nama_mapel']) ?></td>
                    <td class="text-center">
                        <div style="display: flex; gap: 8px;">
                            <a href="?edit_mapel=<?= $mapel['id_mapel'] ?>#daftar-mapel" class="btn-compact" style="background: var(--warning); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Edit</a>
                            <form method="post" onsubmit="return confirm('Hapus mata pelajaran ini? Data jadwal, kehadiran, dan nilai terkait akan ikut terhapus.');" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_mapel">
                                <input type="hidden" name="id_mapel" value="<?= e((string) $mapel['id_mapel']) ?>">
                                <button type="submit" class="danger" style="height: 32px; padding: 0 12px; font-size: 12px;">Hapus</button>
                            </form>
                        </div>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
</div>

<section class="card" id="daftar-jadwal">
    <h2>Daftar Jadwal</h2>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th class="text-center" style="width: 40px;">No</th>
            <th>ID</th>
            <th>Guru</th>
            <th>Kelas</th>
            <th>Mata Pelajaran</th>
            <th>Hari</th>
            <th>Jam</th>
            <th class="text-center" style="width: 140px;">Aksi</th>
        </tr>
        </thead>
        <tbody>
        <?php $noJadwal = 1; ?>
        <?php foreach ($jadwalList as $jadwal): ?>
            <?php $isEditing = $editJadwalId === (int) $jadwal['id_jadwal']; ?>
            <tr>
                <td class="text-center"><?= e((string) $noJadwal++) ?></td>
                <td><?= e((string) $jadwal['id_jadwal']) ?></td>
                <?php if ($isEditing): ?>
                    <td>
                        <select name="id_guru" form="form-edit-jadwal-<?= $jadwal['id_jadwal'] ?>" required style="height: 32px; padding: 4px 8px; width: 100%;">
                            <?php foreach ($guruList as $g): ?>
                                <option value="<?= $g['id_guru'] ?>" <?= (int)$g['id_guru'] === (int)$jadwal['id_guru'] ? 'selected' : '' ?>><?= e($g['nama']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="id_kelas" form="form-edit-jadwal-<?= $jadwal['id_jadwal'] ?>" required style="height: 32px; padding: 4px 8px; width: 100%;">
                            <?php foreach ($kelasList as $k): ?>
                                <option value="<?= $k['id_kelas'] ?>" <?= (int)$k['id_kelas'] === (int)$jadwal['id_kelas'] ? 'selected' : '' ?>><?= e($k['nama_kelas']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="id_mapel" form="form-edit-jadwal-<?= $jadwal['id_jadwal'] ?>" required style="height: 32px; padding: 4px 8px; width: 100%;">
                            <?php foreach ($mapelList as $m): ?>
                                <option value="<?= $m['id_mapel'] ?>" <?= (int)$m['id_mapel'] === (int)$jadwal['id_mapel'] ? 'selected' : '' ?>><?= e($m['nama_mapel']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="hari" form="form-edit-jadwal-<?= $jadwal['id_jadwal'] ?>" required style="height: 32px; padding: 4px 8px; width: 100%;">
                            <?php foreach (['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'] as $h): ?>
                                <option value="<?= $h ?>" <?= $h === $jadwal['hari'] ? 'selected' : '' ?>><?= $h ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="jam" value="<?= e($jadwal['jam']) ?>" form="form-edit-jadwal-<?= $jadwal['id_jadwal'] ?>" required style="height: 32px; padding: 4px 8px; width: 100%;">
                    </td>
                    <td class="text-center">
                        <form id="form-edit-jadwal-<?= $jadwal['id_jadwal'] ?>" method="post" style="margin: 0; display: inline-flex; gap: 8px;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_jadwal">
                            <input type="hidden" name="id_jadwal" value="<?= e((string) $jadwal['id_jadwal']) ?>">
                            <button type="submit" style="background: var(--success); height: 32px; padding: 0 12px; font-size: 12px;">Simpan</button>
                            <a href="?#daftar-jadwal" class="btn-compact" style="background: var(--text-muted); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Batal</a>
                        </form>
                    </td>
                <?php else: ?>
                    <td><?= e($jadwal['guru']) ?></td>
                    <td><?= e($jadwal['nama_kelas']) ?></td>
                    <td><?= e($jadwal['nama_mapel']) ?></td>
                    <td><?= e($jadwal['hari']) ?></td>
                    <td><?= e($jadwal['jam']) ?></td>
                    <td class="text-center">
                        <div style="display: flex; gap: 8px;">
                            <a href="?edit_jadwal=<?= $jadwal['id_jadwal'] ?>#daftar-jadwal" class="btn-compact" style="background: var(--warning); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Edit</a>
                            <form method="post" onsubmit="return confirm('Hapus jadwal ini?');" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_jadwal">
                                <input type="hidden" name="id_jadwal" value="<?= e((string) $jadwal['id_jadwal']) ?>">
                                <button type="submit" class="danger" style="height: 32px; padding: 0 12px; font-size: 12px;">Hapus</button>
                            </form>
                        </div>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.body.addEventListener('submit', async (e) => {
        const form = e.target;
        
        // Hanya tangkap form untuk edit inline
        if (!form.id || !form.id.startsWith('form-edit-')) return;

        e.preventDefault(); // Mencegah halaman refresh
        const tr = form.closest('tr');
        const btn = form.querySelector('button[type="submit"]');
        const originalText = btn.textContent;
        
        btn.textContent = '...';
        btn.disabled = true;

        try {
            const formData = new FormData(form);
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const data = await response.json();
            if (data.status === 'success' && data.html) {
                // Langsung ganti baris tabel ke mode view (Diam-diam tanpa pesan alert)
                tr.innerHTML = data.html;
                
                // Bersihkan URL secara diam-diam agar mode edit tidak terbuka lagi saat di-refresh
                const cleanUrl = window.location.pathname + window.location.hash;
                window.history.replaceState(null, '', cleanUrl);
            } else {
                throw new Error(data.message || 'Gagal menyimpan data');
            }
        } catch (err) {
            // Abaikan error diam-diam, kembalikan tombol
            btn.textContent = originalText;
            btn.disabled = false;
            alert('Terjadi kesalahan: Data gagal disimpan. Pastikan form isian tidak kosong.');
        }
    });
});
</script>
<?php include LAYOUT_PATH . '/footer.php'; ?>
