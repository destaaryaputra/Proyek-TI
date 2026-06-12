<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$user = current_user();
$userId = (int) $user['id_user'];
$role = $user['role'];
$message = '';
$error = '';

// Ambil parameter tab aktif dari URL (default: info)
$activeTab = get_enum('tab', ['info', 'edit', 'keamanan'], 'info');

// Proses Update Data jika ada form yang disubmit
if (request_method_is('POST')) {
    if (!verify_csrf_token(post_string('csrf_token'))) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $action = post_string('action');

        // --- PROSES GANTI PASSWORD ---
        if ($action === 'update_password') {
            $passwordLama = $_POST['password_lama'] ?? '';
            $passwordBaru = $_POST['password_baru'] ?? '';
            $konfirmasi = $_POST['konfirmasi_password'] ?? '';

            if ($passwordLama === '' || $passwordBaru === '' || $konfirmasi === '') {
                $error = 'Semua kolom password wajib diisi.';
            } elseif ($passwordBaru !== $konfirmasi) {
                $error = 'Konfirmasi password baru tidak cocok.';
            } elseif (strlen($passwordBaru) < password_min_length()) {
                $error = 'Password baru minimal harus ' . password_min_length() . ' karakter.';
            } else {
                $stmt = $pdo->prepare('SELECT password FROM tabel_users WHERE id_user = ? LIMIT 1');
                $stmt->execute([$userId]);
                $storedPassword = (string) $stmt->fetchColumn();

                $isValid = false;
                if (str_starts_with($storedPassword, '$2y$') || str_starts_with($storedPassword, '$argon2')) {
                    $isValid = password_verify($passwordLama, $storedPassword);
                } else {
                    $isValid = hash_equals($storedPassword, $passwordLama);
                }

                if (!$isValid) {
                    $error = 'Password lama yang Anda masukkan salah.';
                } else {
                    $newHash = password_hash($passwordBaru, PASSWORD_BCRYPT);
                    $updateStmt = $pdo->prepare('UPDATE tabel_users SET password = ? WHERE id_user = ?');
                    $updateStmt->execute([$newHash, $userId]);

                    // Kirim Notifikasi ke Admin
                    $adminIds = $pdo->query("SELECT id_user FROM tabel_users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
                    if ($adminIds) {
                        $notifStmt = $pdo->prepare('INSERT INTO tabel_notifikasi (id_user, pesan, tanggal) VALUES (?, ?, CURDATE())');
                        $notifMsg = sprintf('Keamanan: Pengguna %s (Role: %s) baru saja mengubah password-nya.', $user['username'], strtoupper($user['role']));
                        foreach ($adminIds as $adminId) {
                            $notifStmt->execute([(int) $adminId, $notifMsg]);
                        }
                    }
                    $message = 'Password berhasil diperbarui dengan aman.';
                    $activeTab = 'keamanan';
                }
            }
        }

        // --- PROSES EDIT PROFIL ---
        if ($action === 'update_profil') {
            $emailBaru = post_string('email');
            $jkBaru = post_enum('jenis_kelamin', ['L', 'P', ''], '');
            $namaBaru = post_string('nama');

            try {
                if ($role === 'siswa') {
                    $stmt = $pdo->prepare('UPDATE tabel_siswa SET email = ?, jenis_kelamin = ? WHERE id_user = ?');
                    $stmt->execute([$emailBaru !== '' ? $emailBaru : null, $jkBaru !== '' ? $jkBaru : null, $userId]);
                } elseif ($role === 'guru') {
                    $stmt = $pdo->prepare('UPDATE tabel_guru SET email = ?, jenis_kelamin = ? WHERE id_user = ?');
                    $stmt->execute([$emailBaru !== '' ? $emailBaru : null, $jkBaru !== '' ? $jkBaru : null, $userId]);
                } elseif ($role === 'admin') {
                    if ($namaBaru === '') {
                        throw new RuntimeException('Nama lengkap wajib diisi.');
                    }
                    $stmt = $pdo->prepare('UPDATE tabel_admin SET nama = ?, email = ? WHERE id_user = ?');
                    $stmt->execute([$namaBaru, $emailBaru !== '' ? $emailBaru : null, $userId]);
                }
                $message = 'Data profil berhasil diperbarui.';
                $activeTab = 'edit';
            } catch (Throwable $e) {
                $error = $e instanceof RuntimeException ? $e->getMessage() : 'Gagal memperbarui profil.';
                $activeTab = 'edit';
            }
        }
    }
}

// Ambil data profil terbaru dari database
$profil = [];
if ($role === 'siswa') {
    $stmt = $pdo->prepare('SELECT s.*, k.nama_kelas, u.created_at FROM tabel_siswa s JOIN tabel_users u ON u.id_user = s.id_user LEFT JOIN tabel_kelas k ON k.id_kelas = s.id_kelas WHERE s.id_user = ?');
} elseif ($role === 'guru') {
    $stmt = $pdo->prepare('SELECT g.*, u.created_at FROM tabel_guru g JOIN tabel_users u ON u.id_user = g.id_user WHERE g.id_user = ?');
} else {
    $stmt = $pdo->prepare('SELECT a.*, u.created_at FROM tabel_admin a JOIN tabel_users u ON u.id_user = a.id_user WHERE a.id_user = ?');
}
$stmt->execute([$userId]);
$profil = $stmt->fetch() ?: [];

$namaLengkap = $profil['nama'] ?? $user['username'];
$inisial = strtoupper(substr($namaLengkap, 0, 1));

$title = 'Profil Saya';
include LAYOUT_PATH . '/header.php';
?>

<div class="card page-hero">
    <h2>Pengaturan Profil</h2>
    <p class="page-lead">Kelola informasi pribadi dan pengaturan keamanan akun Anda di sini.</p>
</div>

<?php if ($message): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<div class="profile-layout">
    <!-- Sidebar Kiri -->
    <section class="card profile-sidebar">
        <div class="profile-avatar"><?= e($inisial) ?></div>
        <h3 style="margin-bottom: 4px; font-size: 18px;"><?= e($namaLengkap) ?></h3>
        <span class="badge" style="background: var(--primary); color: white;"><?= e(strtoupper($role)) ?></span>
        
        <div class="profile-nav">
            <a href="?tab=info" class="<?= $activeTab === 'info' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                Informasi Akun
            </a>
            <a href="?tab=edit" class="<?= $activeTab === 'edit' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                Edit Profil
            </a>
            <a href="?tab=keamanan" class="<?= $activeTab === 'keamanan' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                Keamanan
            </a>
        </div>
    </section>

    <!-- Konten Kanan -->
    <section class="card" style="margin-bottom: 0;">
        <?php if ($activeTab === 'info'): ?>
            <h2 style="border-bottom: 1px solid var(--border); padding-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; color: var(--text-muted);"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                Detail Informasi Akun
            </h2>
            <table class="info-table">
                <tr><td>Username Login</td><td><?= e($user['username']) ?></td></tr>
                <tr><td>Nama Lengkap</td><td><?= e($namaLengkap) ?></td></tr>
                <?php if ($role === 'siswa'): ?>
                    <tr><td>Nomor Induk Siswa (NIS)</td><td><?= e($profil['nis'] ?? '-') ?></td></tr>
                    <tr><td>Kelas Saat Ini</td><td><span class="badge"><?= e($profil['nama_kelas'] ?? 'Belum ada kelas') ?></span></td></tr>
                <?php endif; ?>
                <?php if (in_array($role, ['siswa', 'guru'])): ?>
                    <tr><td>Jenis Kelamin</td><td><?= e(($profil['jenis_kelamin'] ?? '') === 'L' ? 'Laki-laki' : (($profil['jenis_kelamin'] ?? '') === 'P' ? 'Perempuan' : 'Belum diatur')) ?></td></tr>
                <?php endif; ?>
                <tr><td>Alamat Email</td><td><?= e($profil['email'] ?? 'Belum diatur') ?></td></tr>
                <tr><td>Tanggal Bergabung</td><td><?= e(date('d M Y', strtotime($profil['created_at'] ?? 'now'))) ?></td></tr>
            </table>
            <?php if ($role !== 'admin'): ?>
                <p class="footer-note" style="margin-top: 16px;">*Hubungi Administrator sekolah jika terdapat kesalahan pada Nama atau NIS/NIP Anda.</p>
            <?php endif; ?>

        <?php elseif ($activeTab === 'edit'): ?>
            <h2 style="border-bottom: 1px solid var(--border); padding-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; color: var(--text-muted);"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                Edit Profil <?= $role === 'admin' ? '' : 'Terbatas' ?>
            </h2>
            <form method="post" style="margin-top: 16px;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_profil">
                <?php if ($role === 'admin'): ?>
                <div>
                    <label for="nama">Nama Lengkap</label>
                    <input type="text" id="nama" name="nama" value="<?= e($profil['nama'] ?? '') ?>" required>
                </div>
                <?php endif; ?>
                <div>
                    <label for="email">Alamat Email (Opsional)</label>
                    <input type="email" id="email" name="email" value="<?= e($profil['email'] ?? '') ?>" placeholder="contoh@email.com">
                </div>
                <?php if (in_array($role, ['siswa', 'guru'])): ?>
                <div>
                    <label for="jenis_kelamin">Jenis Kelamin</label>
                    <select id="jenis_kelamin" name="jenis_kelamin">
                        <option value="">- Belum Diatur -</option>
                        <option value="L" <?= ($profil['jenis_kelamin'] ?? '') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                        <option value="P" <?= ($profil['jenis_kelamin'] ?? '') === 'P' ? 'selected' : '' ?>>Perempuan</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-actions"><button type="submit">Simpan Perubahan</button></div>
            </form>

        <?php elseif ($activeTab === 'keamanan'): ?>
            <h2 style="border-bottom: 1px solid var(--border); padding-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; color: var(--text-muted);"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                Ganti Password
            </h2>
            <form method="post" style="margin-top: 16px;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_password">
                <div>
                    <label for="password_lama">Password Lama</label>
                    <input type="password" id="password_lama" name="password_lama" required>
                </div>
                <div>
                    <label for="password_baru">Password Baru</label>
                    <input type="password" id="password_baru" name="password_baru" required minlength="<?= e((string) password_min_length()) ?>" placeholder="Minimal <?= e((string) password_min_length()) ?> karakter">
                </div>
                <div>
                    <label for="konfirmasi_password">Konfirmasi Password Baru</label>
                    <input type="password" id="konfirmasi_password" name="konfirmasi_password" required minlength="<?= e((string) password_min_length()) ?>">
                </div>
                <div class="form-actions"><button type="submit" class="danger">Update Password</button></div>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php include LAYOUT_PATH . '/footer.php'; ?>
