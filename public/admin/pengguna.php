<?php

declare(strict_types=1);

// Modul admin untuk menambah dan menghapus user sesuai role.
require_once __DIR__ . '/../../app/bootstrap.php';

require_role(['admin']);

$message = '';
$error = '';
$currentUserId = (int) (current_user()['id_user'] ?? 0);
$editUserId = get_int('edit_user');
$editGuruId = get_int('edit_guru');
$editSiswaId = get_int('edit_siswa');

// Hapus user berdasarkan ID sambil menjaga agar akun admin aktif tidak terhapus.
function delete_user_by_id(PDO $pdo, int $idUser, int $currentUserId): void
{
    if ($idUser <= 0) {
        throw new RuntimeException('ID user tidak valid.');
    }

    if ($idUser === $currentUserId) {
        throw new RuntimeException('Akun yang sedang dipakai tidak bisa dihapus.');
    }

    $stmt = $pdo->prepare('SELECT role FROM tabel_users WHERE id_user = ? LIMIT 1');
    $stmt->execute([$idUser]);
    $role = $stmt->fetchColumn();

    if ($role === false) {
        throw new RuntimeException('Pengguna tidak ditemukan.');
    }

    $pdo->beginTransaction();

    try {
        // ON DELETE CASCADE di database akan menghapus data turunan yang terhubung.
        $stmt = $pdo->prepare('DELETE FROM tabel_users WHERE id_user = ?');
        $stmt->execute([$idUser]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ensure_user_profile_for_role(PDO $pdo, int $idUser, string $role, string $fallbackName): void
{
    $profileName = trim($fallbackName) !== '' ? trim($fallbackName) : ('User ' . $idUser);

    if ($role === 'admin') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tabel_admin WHERE id_user = ?');
        $stmt->execute([$idUser]);
        if ((int) $stmt->fetchColumn() === 0) {
            $stmt = $pdo->prepare('INSERT INTO tabel_admin (id_user, nama, email) VALUES (?, ?, NULL)');
            $stmt->execute([$idUser, $profileName]);
        }
        return;
    }

    if ($role === 'guru') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tabel_guru WHERE id_user = ?');
        $stmt->execute([$idUser]);
        if ((int) $stmt->fetchColumn() === 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM tabel_guru WHERE nama = ? AND id_user <> ?');
            $stmt->execute([$profileName, $idUser]);
            if ((int) $stmt->fetchColumn() > 0) {
                $profileName .= ' #' . $idUser;
            }

            $stmt = $pdo->prepare('INSERT INTO tabel_guru (id_user, nama, jenis_kelamin, email) VALUES (?, ?, NULL, NULL)');
            $stmt->execute([$idUser, $profileName]);
        }
        return;
    }

    if ($role === 'siswa') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tabel_siswa WHERE id_user = ?');
        $stmt->execute([$idUser]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Profil siswa belum tersedia. Buat akun siswa baru agar NIS dan kelas lengkap.');
        }
    }
}

$kelasList = $pdo->query('SELECT id_kelas, nama_kelas FROM tabel_kelas ORDER BY nama_kelas ASC')->fetchAll();
$searchUser = get_string('q');
$roleFilter = get_string('role');
$allowedRoles = ['admin', 'guru', 'siswa'];

$roleFilter = get_enum('role', $allowedRoles, '');

if (request_method_is('POST')) {
    if (!verify_csrf_token(post_string('csrf_token'))) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } elseif (post_int('delete_user_id') > 0) {
        try {
            $idUser = post_int('delete_user_id');
            delete_user_by_id($pdo, $idUser, $currentUserId);
            $message = 'Pengguna berhasil dihapus.';
        } catch (Throwable $e) {
            $error = 'Gagal menghapus pengguna.';
        }
    } elseif (post_string('action') === 'update_user') {
        try {
            $idUser = post_int('id_user');
            $username = post_string('username');
            $role = post_enum('role', $allowedRoles, '');
            if ($idUser <= 0 || $username === '' || $role === '') throw new RuntimeException('Data tidak lengkap.');

            $stmt = $pdo->prepare('SELECT username, role FROM tabel_users WHERE id_user = ? LIMIT 1');
            $stmt->execute([$idUser]);
            $targetUser = $stmt->fetch();
            if (!$targetUser) {
                throw new RuntimeException('Pengguna tidak ditemukan.');
            }

            if ($idUser === $currentUserId && $role !== 'admin') {
                throw new RuntimeException('Akun admin yang sedang aktif tidak boleh diubah ke role lain.');
            }

            $pdo->beginTransaction();
            if ($role !== (string) $targetUser['role']) {
                ensure_user_profile_for_role($pdo, $idUser, $role, $username);
            }

            $stmt = $pdo->prepare('UPDATE tabel_users SET username = ?, role = ? WHERE id_user = ?');
            $stmt->execute([$username, $role, $idUser]);
            $pdo->commit();

            if (is_ajax_request()) {
                $stmt = $pdo->prepare('SELECT id_user, username, role, created_at FROM tabel_users WHERE id_user = ?');
                $stmt->execute([$idUser]);
                $u = $stmt->fetch();
                ob_start();
                ?>
                <td class="text-center">-</td>
                <td><?= e((string) $u['id_user']) ?></td>
                <td><?= e($u['username']) ?></td>
                <td><span class="badge <?= e(role_badge_class($u['role'])) ?>"><?= e(strtoupper($u['role'])) ?></span></td>
                <td><?= e($u['created_at']) ?></td>
                <td class="text-center">
                    <div style="display: flex; gap: 8px;">
                        <a href="?edit_user=<?= $u['id_user'] ?>#daftar-user" class="btn-compact" style="background: var(--warning); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Edit</a>
                        <?php if ((int) $u['id_user'] !== $currentUserId): ?>
                            <form method="post" onsubmit="return confirm('Hapus pengguna ini? Data terkait akan ikut terhapus sesuai relasi.');" style="margin: 0;"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="delete_user_id" value="<?= e((string) $u['id_user']) ?>"><button type="submit" class="danger" style="height: 32px; padding: 0 12px; font-size: 12px;">Hapus</button></form>
                        <?php else: ?>
                            <span class="badge" style="height: 32px; display: inline-flex; align-items: center;">Aktif</span>
                        <?php endif; ?>
                    </div>
                </td>
                <?php
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'html' => ob_get_clean()]);
                exit;
            }

            $message = 'Data pengguna berhasil diperbarui.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Gagal memperbarui pengguna. Username mungkin sudah dipakai.';
        }
    } elseif (post_string('action') === 'update_guru') {
        try {
            $idGuru = post_int('id_guru');
            $nama = post_string('nama');
            $jk = post_enum('jenis_kelamin', ['L', 'P', ''], '');
            $email = post_string('email');
            if ($idGuru <= 0 || $nama === '') throw new RuntimeException('Nama wajib diisi.');
            $stmt = $pdo->prepare('UPDATE tabel_guru SET nama = ?, jenis_kelamin = ?, email = ? WHERE id_guru = ?');
            $stmt->execute([$nama, $jk !== '' ? $jk : null, $email !== '' ? $email : null, $idGuru]);

            if (is_ajax_request()) {
                $stmt = $pdo->prepare("SELECT g.id_guru, u.username, g.nama, g.jenis_kelamin, g.email, u.id_user FROM tabel_guru g JOIN tabel_users u ON u.id_user = g.id_user WHERE g.id_guru = ?");
                $stmt->execute([$idGuru]);
                $guru = $stmt->fetch();
                ob_start();
                ?>
                <td class="text-center">-</td>
                <td><?= e((string) $guru['id_guru']) ?></td>
                <td><?= e($guru['username']) ?></td>
                <td><?= e($guru['nama']) ?></td>
                <td><?= e(gender_label($guru['jenis_kelamin'] ?? null)) ?></td>
                <td><?= e((string) ($guru['email'] ?? '-')) ?></td>
                <td class="text-center">
                    <div style="display: flex; gap: 8px;">
                        <a href="?edit_guru=<?= $guru['id_guru'] ?>#data-guru" class="btn-compact" style="background: var(--warning); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Edit</a>
                        <?php if ((int) $guru['id_user'] !== $currentUserId): ?>
                            <form method="post" onsubmit="return confirm('Hapus data guru ini? Seluruh jadwal, data kehadiran, dan nilai yang terhubung akan ikut terhapus.');" style="margin: 0;"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="delete_user_id" value="<?= e((string) $guru['id_user']) ?>"><button type="submit" class="danger" style="height: 32px; padding: 0 12px; font-size: 12px;">Hapus</button></form>
                        <?php else: ?>
                            <span class="badge" style="height: 32px; display: inline-flex; align-items: center;">Aktif</span>
                        <?php endif; ?>
                    </div>
                </td>
                <?php
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'html' => ob_get_clean()]);
                exit;
            }

            $message = 'Data guru berhasil diperbarui.';
        } catch (Throwable $e) {
            $error = 'Gagal memperbarui data guru. Nama mungkin sudah dipakai.';
        }
    } elseif (post_string('action') === 'update_siswa') {
        try {
            $idSiswa = post_int('id_siswa');
            $nama = post_string('nama');
            $jk = post_enum('jenis_kelamin', ['L', 'P', ''], '');
            $nis = post_string('nis');
            $idKelas = post_nullable_int('id_kelas');
            if ($idSiswa <= 0 || $nama === '' || $nis === '') throw new RuntimeException('Nama dan NIS wajib diisi.');
            $stmt = $pdo->prepare('UPDATE tabel_siswa SET nama = ?, jenis_kelamin = ?, nis = ?, id_kelas = ? WHERE id_siswa = ?');
            $stmt->execute([$nama, $jk !== '' ? $jk : null, $nis, $idKelas, $idSiswa]);

            if (is_ajax_request()) {
                $stmt = $pdo->prepare("SELECT s.id_siswa, u.username, s.nama, s.jenis_kelamin, s.nis, s.id_kelas, k.nama_kelas, u.id_user FROM tabel_siswa s JOIN tabel_users u ON u.id_user = s.id_user LEFT JOIN tabel_kelas k ON k.id_kelas = s.id_kelas WHERE s.id_siswa = ?");
                $stmt->execute([$idSiswa]);
                $siswa = $stmt->fetch();
                ob_start();
                ?>
                <td class="text-center">-</td>
                <td><?= e((string) $siswa['id_siswa']) ?></td>
                <td><?= e($siswa['username']) ?></td>
                <td><?= e($siswa['nama']) ?></td>
                <td><?= e(gender_label($siswa['jenis_kelamin'] ?? null)) ?></td>
                <td><?= e($siswa['nis']) ?></td>
                <td><?= e((string) ($siswa['nama_kelas'] ?? '-')) ?></td>
                <td class="text-center">
                    <div style="display: flex; gap: 8px;">
                        <a href="?edit_siswa=<?= $siswa['id_siswa'] ?>#data-siswa" class="btn-compact" style="background: var(--warning); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Edit</a>
                        <?php if ((int) $siswa['id_user'] !== $currentUserId): ?>
                            <form method="post" onsubmit="return confirm('Hapus data siswa ini? Seluruh data kehadiran dan nilainya akan ikut terhapus.');" style="margin: 0;"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="delete_user_id" value="<?= e((string) $siswa['id_user']) ?>"><button type="submit" class="danger" style="height: 32px; padding: 0 12px; font-size: 12px;">Hapus</button></form>
                        <?php else: ?>
                            <span class="badge" style="height: 32px; display: inline-flex; align-items: center;">Aktif</span>
                        <?php endif; ?>
                    </div>
                </td>
                <?php
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'html' => ob_get_clean()]);
                exit;
            }

            $message = 'Data siswa berhasil diperbarui.';
        } catch (Throwable $e) {
            $error = 'Gagal memperbarui data siswa. Nama atau NIS mungkin sudah dipakai.';
        }
    } else {
        $username = post_string('username');
        $passwordValue = $_POST['password'] ?? '';
        $password = is_scalar($passwordValue) ? (string) $passwordValue : '';
        $role = post_enum('role', $allowedRoles, '');
        $nama = post_string('nama');
        $jenisKelamin = post_enum('jenis_kelamin', ['', 'L', 'P'], '');
        $kontak = post_string('kontak');
        $nis = post_string('nis');
        $idKelas = post_nullable_int('id_kelas');

        if ($username === '' || $password === '' || $nama === '' || $role === '') {
            $error = 'Data wajib belum lengkap.';
        } elseif (strlen($password) < password_min_length()) {
            $error = 'Password minimal ' . password_min_length() . ' karakter.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('INSERT INTO tabel_users (username, password, role) VALUES (?, ?, ?)');
                $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $role]);
                $idUser = (int) $pdo->lastInsertId();

                if ($role === 'admin') {
                    $stmt = $pdo->prepare('INSERT INTO tabel_admin (id_user, nama, email) VALUES (?, ?, ?)');
                    $stmt->execute([$idUser, $nama, $kontak !== '' ? $kontak : null]);
                }

                if ($role === 'guru') {
                    $stmt = $pdo->prepare('INSERT INTO tabel_guru (id_user, nama, jenis_kelamin, email) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$idUser, $nama, $jenisKelamin !== '' ? $jenisKelamin : null, $kontak !== '' ? $kontak : null]);
                }

                if ($role === 'siswa') {
                    if ($nis === '' || $idKelas === null) {
                        throw new RuntimeException('NIS dan kelas wajib diisi untuk peran siswa.');
                    }
                    $stmt = $pdo->prepare('INSERT INTO tabel_siswa (id_user, id_kelas, nama, jenis_kelamin, nis, email) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$idUser, $idKelas, $nama, $jenisKelamin !== '' ? $jenisKelamin : null, $nis, $kontak !== '' ? $kontak : null]);
                }

                $pdo->commit();
                $message = 'Pengguna berhasil ditambahkan.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Gagal menambahkan pengguna. Username atau nama kemungkinan sudah dipakai.';
            }
        }
    }
}

$baseWhere = ' WHERE 1=1';
$filterParams = [];

if ($searchUser !== '') {
    $baseWhere .= ' AND username LIKE ?';
    $filterParams[] = '%' . $searchUser . '%';
}

if ($roleFilter !== '') {
    $baseWhere .= ' AND role = ?';
    $filterParams[] = $roleFilter;
}

$usersSql = 'SELECT id_user, username, role, created_at FROM tabel_users' . $baseWhere . ' ORDER BY username ASC';
$usersPerPage = 15;
$usersPage = max(1, get_int('page_users', 1));
$usersCountSql = 'SELECT COUNT(*) FROM tabel_users' . $baseWhere;

$stmt = $pdo->prepare($usersCountSql);
$stmt->execute($filterParams);
$usersTotal = (int) $stmt->fetchColumn();
$usersTotalPages = max(1, (int) ceil($usersTotal / $usersPerPage));
$usersPage = min($usersPage, $usersTotalPages);
$usersOffset = ($usersPage - 1) * $usersPerPage;

$stmt = $pdo->prepare($usersSql . ' LIMIT ' . $usersPerPage . ' OFFSET ' . $usersOffset);
$stmt->execute($filterParams);
$users = $stmt->fetchAll();

$guruPerPage = 10;
$guruPage = max(1, get_int('page_guru', 1));
$stmt = $pdo->query('SELECT COUNT(*) FROM tabel_guru');
$guruTotal = (int) $stmt->fetchColumn();
$guruTotalPages = max(1, (int) ceil($guruTotal / $guruPerPage));
$guruPage = min($guruPage, $guruTotalPages);
$guruOffset = ($guruPage - 1) * $guruPerPage;

$stmt = $pdo->prepare("SELECT g.id_guru, u.username, g.nama, g.jenis_kelamin, g.email, u.id_user FROM tabel_guru g JOIN tabel_users u ON u.id_user = g.id_user ORDER BY g.id_guru ASC LIMIT $guruPerPage OFFSET $guruOffset");
$stmt->execute();
$guruData = $stmt->fetchAll();

$siswaPerPage = 10;
$siswaPage = max(1, get_int('page_siswa', 1));
$stmt = $pdo->query('SELECT COUNT(*) FROM tabel_siswa');
$siswaTotal = (int) $stmt->fetchColumn();
$siswaTotalPages = max(1, (int) ceil($siswaTotal / $siswaPerPage));
$siswaPage = min($siswaPage, $siswaTotalPages);
$siswaOffset = ($siswaPage - 1) * $siswaPerPage;

$stmt = $pdo->prepare("SELECT s.id_siswa, u.username, s.nama, s.jenis_kelamin, s.nis, s.id_kelas, k.nama_kelas, u.id_user FROM tabel_siswa s JOIN tabel_users u ON u.id_user = s.id_user LEFT JOIN tabel_kelas k ON k.id_kelas = s.id_kelas ORDER BY s.id_siswa ASC LIMIT $siswaPerPage OFFSET $siswaOffset");
$stmt->execute();
$siswaData = $stmt->fetchAll();

$title = 'Manajemen Pengguna';
include LAYOUT_PATH . '/header.php';
?>
<div class="card page-hero">
    <h2>Manajemen Pengguna</h2>
    <p class="page-lead">Kelola data akun admin, guru, dan siswa.</p>
</div>
<section class="card">
    <h2>Tambah Pengguna</h2>
    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div>
            <label for="username">Username</label>
            <input id="username" name="username" required>
        </div>
        <div>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required>
        </div>
        <div>
            <label for="role">Peran</label>
            <select id="role" name="role" required>
                <option value="">- Pilih Peran -</option>
                <option value="admin">Admin</option>
                <option value="guru">Guru</option>
                <option value="siswa">Siswa</option>
            </select>
        </div>
        <div>
            <label for="nama">Nama</label>
            <input id="nama" name="nama" required>
        </div>
        <div>
            <label for="jenis_kelamin">Jenis Kelamin</label>
            <select id="jenis_kelamin" name="jenis_kelamin">
                <option value="">- Pilih Jenis Kelamin -</option>
                <option value="L">Laki-laki</option>
                <option value="P">Perempuan</option>
            </select>
        </div>
        <div>
            <label for="kontak">Email</label>
            <input id="kontak" name="kontak" placeholder="opsional">
        </div>
        <div>
            <label for="nis">NIS (khusus siswa)</label>
            <input id="nis" name="nis">
        </div>
        <div>
            <label for="id_kelas">Kelas (khusus siswa)</label>
            <select id="id_kelas" name="id_kelas">
                <option value="">- Pilih Kelas -</option>
                <?php foreach ($kelasList as $kelas): ?>
                    <option value="<?= e((string) $kelas['id_kelas']) ?>"><?= e($kelas['nama_kelas']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn-compact">Simpan Pengguna</button>
        </div>
    </form>
</section>

<section class="card" id="daftar-user">
    <h2>Daftar Pengguna</h2>
    <form method="get" class="filter-toolbar" style="margin-bottom: 10px;">
        <div class="filter-field">
            <label for="q">Cari Username</label>
            <input id="q" name="q" value="<?= e($searchUser) ?>" placeholder="Cari username...">
        </div>
        <div class="filter-field">
            <label for="role_filter">Filter Peran</label>
            <select id="role_filter" name="role">
                <option value="">Semua Peran</option>
                <?php foreach ($allowedRoles as $filterRole): ?>
                    <option value="<?= e($filterRole) ?>" <?= $roleFilter === $filterRole ? 'selected' : '' ?>><?= e(strtoupper($filterRole)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions" style="margin-left: auto;">
            <button type="submit">Terapkan</button>
            <a class="action-link" href="<?= e(url('admin/pengguna.php#daftar-user')) ?>">Reset</a>
        </div>
    </form>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
                <th class="text-center" style="width: 40px;">No.</th>
            <th>ID</th>
            <th>Username</th>
            <th>Peran</th>
            <th>Tanggal Dibuat</th>
                <th class="text-center" style="width: 140px;">Aksi</th>
        </tr>
        </thead>
        <tbody>
            <?php $noUsers = $usersOffset + 1; ?>
        <?php foreach ($users as $u): ?>
            <?php $isEditing = $editUserId === (int) $u['id_user']; ?>
            <tr>
                    <td class="text-center"><?= $noUsers++ ?></td>
                <td><?= e((string) $u['id_user']) ?></td>
                <?php if ($isEditing): ?>
                    <td><input type="text" name="username" value="<?= e($u['username']) ?>" form="form-edit-user-<?= $u['id_user'] ?>" required style="height: 32px; padding: 4px 8px; width: 100%;"></td>
                    <td>
                        <select name="role" form="form-edit-user-<?= $u['id_user'] ?>" required style="height: 32px; padding: 4px 8px; width: 100%;">
                            <?php foreach ($allowedRoles as $r): ?>
                                <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(strtoupper($r)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><?= e($u['created_at']) ?></td>
                    <td class="text-center">
                        <form id="form-edit-user-<?= $u['id_user'] ?>" method="post" style="margin: 0; display: inline-flex; gap: 8px;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_user">
                            <input type="hidden" name="id_user" value="<?= e((string) $u['id_user']) ?>">
                            <button type="submit" style="background: var(--success); height: 32px; padding: 0 12px; font-size: 12px;">Simpan</button>
                            <a href="?#daftar-user" class="btn-compact" style="background: var(--text-muted); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Batal</a>
                        </form>
                    </td>
                <?php else: ?>
                    <td><?= e($u['username']) ?></td>
                    <td><span class="badge <?= $u['role'] === 'admin' ? 'danger' : ($u['role'] === 'guru' ? 'success' : '') ?>"><?= e(strtoupper($u['role'])) ?></span></td>
                    <td><?= e($u['created_at']) ?></td>
                    <td class="text-center">
                        <div style="display: flex; gap: 8px;">
                            <a href="?edit_user=<?= $u['id_user'] ?>#daftar-user" class="btn-compact" style="background: var(--warning); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Edit</a>
                            <?php if ((int) $u['id_user'] !== $currentUserId): ?>
                                <form method="post" onsubmit="return confirm('Hapus pengguna ini? Data terkait akan ikut terhapus sesuai relasi.');" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="delete_user_id" value="<?= e((string) $u['id_user']) ?>">
                                    <button type="submit" class="danger" style="height: 32px; padding: 0 12px; font-size: 12px;">Hapus</button>
                                </form>
                            <?php else: ?>
                                <span class="badge" style="height: 32px; display: inline-flex; align-items: center;">Aktif</span>
                            <?php endif; ?>
                        </div>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?= pagination_links(url('admin/pengguna.php'), 'page_users', $usersPage, $usersTotalPages, ['q' => $searchUser, 'role' => $roleFilter, 'page_guru' => $guruPage, 'page_siswa' => $siswaPage]) ?>
</section>

<section class="card" id="data-guru">
    <h2>Data Guru</h2>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
                <th class="text-center" style="width: 40px;">No.</th>
            <th>ID</th>
            <th>Username</th>
            <th>Nama</th>
            <th>Jenis Kelamin</th>
            <th>Email</th>
                <th class="text-center" style="width: 140px;">Aksi</th>
        </tr>
        </thead>
        <tbody>
            <?php $noGuru = $guruOffset + 1; ?>
        <?php foreach ($guruData as $guru): ?>
            <?php $isEditing = $editGuruId === (int) $guru['id_guru']; ?>
            <tr>
                    <td class="text-center"><?= $noGuru++ ?></td>
                <td><?= e((string) $guru['id_guru']) ?></td>
                <td><?= e($guru['username']) ?></td>
                <?php if ($isEditing): ?>
                    <td><input type="text" name="nama" value="<?= e($guru['nama']) ?>" form="form-edit-guru-<?= $guru['id_guru'] ?>" required style="height: 32px; padding: 4px 8px; width: 100%;"></td>
                    <td>
                        <select name="jenis_kelamin" form="form-edit-guru-<?= $guru['id_guru'] ?>" style="height: 32px; padding: 4px 8px; width: 100%;">
                            <option value="">-</option>
                            <option value="L" <?= ($guru['jenis_kelamin'] ?? '') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                            <option value="P" <?= ($guru['jenis_kelamin'] ?? '') === 'P' ? 'selected' : '' ?>>Perempuan</option>
                        </select>
                    </td>
                    <td><input type="email" name="email" value="<?= e((string) ($guru['email'] ?? '')) ?>" form="form-edit-guru-<?= $guru['id_guru'] ?>" style="height: 32px; padding: 4px 8px; width: 100%;"></td>
                    <td class="text-center">
                        <form id="form-edit-guru-<?= $guru['id_guru'] ?>" method="post" style="margin: 0; display: inline-flex; gap: 8px;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_guru">
                            <input type="hidden" name="id_guru" value="<?= e((string) $guru['id_guru']) ?>">
                            <button type="submit" style="background: var(--success); height: 32px; padding: 0 12px; font-size: 12px;">Simpan</button>
                            <a href="?#data-guru" class="btn-compact" style="background: var(--text-muted); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Batal</a>
                        </form>
                    </td>
                <?php else: ?>
                    <td><?= e($guru['nama']) ?></td>
                <td><?= e(gender_label($guru['jenis_kelamin'] ?? null)) ?></td>
                    <td><?= e((string) ($guru['email'] ?? '-')) ?></td>
                    <td class="text-center">
                        <div style="display: flex; gap: 8px;">
                            <a href="?edit_guru=<?= $guru['id_guru'] ?>#data-guru" class="btn-compact" style="background: var(--warning); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Edit</a>
                            <?php if ((int) $guru['id_user'] !== $currentUserId): ?>
                                <form method="post" onsubmit="return confirm('Hapus data guru ini? Seluruh jadwal, data kehadiran, dan nilai yang terhubung akan ikut terhapus.');" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="delete_user_id" value="<?= e((string) $guru['id_user']) ?>">
                                    <button type="submit" class="danger" style="height: 32px; padding: 0 12px; font-size: 12px;">Hapus</button>
                                </form>
                            <?php else: ?>
                                <span class="badge" style="height: 32px; display: inline-flex; align-items: center;">Aktif</span>
                            <?php endif; ?>
                        </div>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?= pagination_links(url('admin/pengguna.php'), 'page_guru', $guruPage, $guruTotalPages, ['q' => $searchUser, 'role' => $roleFilter, 'page_users' => $usersPage, 'page_siswa' => $siswaPage]) ?>
</section>

<section class="card" id="data-siswa">
    <h2>Data Siswa</h2>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
                <th class="text-center" style="width: 40px;">No.</th>
            <th>ID</th>
            <th>Username</th>
            <th>Nama</th>
            <th>Jenis Kelamin</th>
            <th>NIS</th>
            <th>Kelas</th>
                <th class="text-center" style="width: 140px;">Aksi</th>
        </tr>
        </thead>
        <tbody>
            <?php $noSiswa = $siswaOffset + 1; ?>
        <?php foreach ($siswaData as $siswa): ?>
            <?php $isEditing = $editSiswaId === (int) $siswa['id_siswa']; ?>
            <tr>
                    <td class="text-center"><?= $noSiswa++ ?></td>
                <td><?= e((string) $siswa['id_siswa']) ?></td>
                <td><?= e($siswa['username']) ?></td>
                <?php if ($isEditing): ?>
                    <td><input type="text" name="nama" value="<?= e($siswa['nama']) ?>" form="form-edit-siswa-<?= $siswa['id_siswa'] ?>" required style="height: 32px; padding: 4px 8px; width: 100%;"></td>
                    <td>
                        <select name="jenis_kelamin" form="form-edit-siswa-<?= $siswa['id_siswa'] ?>" style="height: 32px; padding: 4px 8px; width: 100%;">
                            <option value="">-</option>
                            <option value="L" <?= ($siswa['jenis_kelamin'] ?? '') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                            <option value="P" <?= ($siswa['jenis_kelamin'] ?? '') === 'P' ? 'selected' : '' ?>>Perempuan</option>
                        </select>
                    </td>
                    <td><input type="text" name="nis" value="<?= e($siswa['nis']) ?>" form="form-edit-siswa-<?= $siswa['id_siswa'] ?>" required style="height: 32px; padding: 4px 8px; width: 100%;"></td>
                    <td>
                        <select name="id_kelas" form="form-edit-siswa-<?= $siswa['id_siswa'] ?>" style="height: 32px; padding: 4px 8px; width: 100%;">
                            <option value="">- Kelas -</option>
                            <?php foreach ($kelasList as $k): ?>
                                <option value="<?= $k['id_kelas'] ?>" <?= (int)($siswa['id_kelas'] ?? 0) === (int)$k['id_kelas'] ? 'selected' : '' ?>><?= e($k['nama_kelas']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="text-center">
                        <form id="form-edit-siswa-<?= $siswa['id_siswa'] ?>" method="post" style="margin: 0; display: inline-flex; gap: 8px;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_siswa">
                            <input type="hidden" name="id_siswa" value="<?= e((string) $siswa['id_siswa']) ?>">
                            <button type="submit" style="background: var(--success); height: 32px; padding: 0 12px; font-size: 12px;">Simpan</button>
                            <a href="?#data-siswa" class="btn-compact" style="background: var(--text-muted); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Batal</a>
                        </form>
                    </td>
                <?php else: ?>
                    <td><?= e($siswa['nama']) ?></td>
                <td><?= e(gender_label($siswa['jenis_kelamin'] ?? null)) ?></td>
                    <td><?= e($siswa['nis']) ?></td>
                    <td><?= e((string) ($siswa['nama_kelas'] ?? '-')) ?></td>
                    <td class="text-center">
                        <div style="display: flex; gap: 8px;">
                            <a href="?edit_siswa=<?= $siswa['id_siswa'] ?>#data-siswa" class="btn-compact" style="background: var(--warning); text-decoration: none; height: 32px; padding: 0 12px; font-size: 12px; line-height: 30px;">Edit</a>
                            <?php if ((int) $siswa['id_user'] !== $currentUserId): ?>
                                <form method="post" onsubmit="return confirm('Hapus data siswa ini? Seluruh data kehadiran dan nilainya akan ikut terhapus.');" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="delete_user_id" value="<?= e((string) $siswa['id_user']) ?>">
                                    <button type="submit" class="danger" style="height: 32px; padding: 0 12px; font-size: 12px;">Hapus</button>
                                </form>
                            <?php else: ?>
                                <span class="badge" style="height: 32px; display: inline-flex; align-items: center;">Aktif</span>
                            <?php endif; ?>
                        </div>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?= pagination_links(url('admin/pengguna.php'), 'page_siswa', $siswaPage, $siswaTotalPages, ['q' => $searchUser, 'role' => $roleFilter, 'page_users' => $usersPage, 'page_guru' => $guruPage]) ?>
</section>
<script>
(() => {
    const parser = new DOMParser();
    let isSwapping = false;

    const swapSectionFromUrl = async (link) => {
        if (isSwapping) {
            return;
        }

        const currentSection = link.closest('section.card[id]');
        if (!currentSection) {
            window.location.href = link.href;
            return;
        }

        isSwapping = true;
        currentSection.classList.add('is-loading');
        const previousTop = currentSection.getBoundingClientRect().top;

        try {
            const response = await fetch(link.href, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('Request failed');
            }

            const html = await response.text();
            const doc = parser.parseFromString(html, 'text/html');
            const replacement = doc.getElementById(currentSection.id);

            if (!replacement) {
                throw new Error('Section not found');
            }

            currentSection.replaceWith(replacement);

            const newSection = document.getElementById(replacement.id);
            if (newSection) {
                const newTop = newSection.getBoundingClientRect().top;
                window.scrollBy({ top: newTop - previousTop, left: 0, behavior: 'auto' });
            }

            history.replaceState(null, '', link.href);
        } catch (err) {
            window.location.href = link.href;
        } finally {
            isSwapping = false;
        }
    };

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a.js-pagination-link');
        if (!link) {
            return;
        }

        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        swapSectionFromUrl(link);
    });

    // JS untuk Menangani Inline Editing secara Instan tanpa Refresh (AJAX)
    document.body.addEventListener('submit', async (e) => {
        const form = e.target;
        if (!form.id || !form.id.startsWith('form-edit-')) return;

        e.preventDefault(); 
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
                tr.innerHTML = data.html;
                window.history.replaceState(null, '', window.location.pathname + window.location.hash);
            } else {
                throw new Error(data.message || 'Gagal menyimpan data');
            }
        } catch (err) {
            btn.textContent = originalText;
            btn.disabled = false;
            alert('Terjadi kesalahan: Data gagal disimpan. Pastikan username/NIS tidak duplikat.');
        }
    });
})();
</script>
<?php include LAYOUT_PATH . '/footer.php'; ?>
