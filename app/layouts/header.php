<?php

declare(strict_types=1);

// Header bersama untuk seluruh halaman setelah user login.
require_once __DIR__ . '/../bootstrap.php';

$user = current_user();
$title = $title ?? 'EduTrack Sekolah';
$notifikasiCount = 0;
$adminSystemAlertCount = 0;
$showNotifMenu = $user !== null;

// Ambil notifikasi milik user aktif untuk badge dan tampilan ringkas.
if ($showNotifMenu && isset($pdo)) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tabel_notifikasi WHERE id_user = ? AND dibaca = FALSE');
    $stmt->execute([(int) $user['id_user']]);
    $notifikasiCount = (int) $stmt->fetchColumn();

    // Untuk admin, tambahkan notifikasi kesehatan data sistem ke badge.
    if (($user['role'] ?? '') === 'admin') {
        $adminChecks = [
            'SELECT COUNT(*) FROM tabel_siswa WHERE id_kelas IS NULL',
            "SELECT COUNT(*) FROM tabel_users u LEFT JOIN tabel_siswa s ON s.id_user = u.id_user WHERE u.role = 'siswa' AND s.id_siswa IS NULL",
            "SELECT COUNT(*) FROM tabel_users u LEFT JOIN tabel_guru g ON g.id_user = u.id_user WHERE u.role = 'guru' AND g.id_guru IS NULL",
            'SELECT CASE WHEN COUNT(*) = 0 THEN 1 ELSE 0 END FROM tabel_jadwal',
        ];

        foreach ($adminChecks as $checkSql) {
            $count = (int) $pdo->query($checkSql)->fetchColumn();
            if ($count > 0) {
                $adminSystemAlertCount++;
            }
        }
    }
}

// Jumlahkan semua notifikasi agar Admin juga bisa melihat notifikasi pribadinya (seperti request reset password).
$totalNotifBadge = $notifikasiCount + $adminSystemAlertCount;

$currentPath = str_replace('\\', '/', (string) ($_SERVER['PHP_SELF'] ?? ''));
$isNotifikasi = str_ends_with($currentPath, '/notifikasi.php');
$isDasbor = str_ends_with($currentPath, '/dasbor.php');
$isLaporan = str_ends_with($currentPath, '/laporan.php');
$isAdminPengguna = str_ends_with($currentPath, '/admin/pengguna.php');
$isAdminAkademik = str_ends_with($currentPath, '/admin/akademik.php');
$isGuruAbsensi = str_ends_with($currentPath, '/guru/absensi.php');
$isGuruNilai = str_ends_with($currentPath, '/guru/nilai.php');
$bodyClasses = [];
$isProfil = str_ends_with($currentPath, '/profil.php');
if ($isLaporan) {
    $bodyClasses[] = 'report-page';
}
if (isset($bodyClass) && is_string($bodyClass) && trim($bodyClass) !== '') {
    $bodyClasses[] = trim($bodyClass);
}
$bodyClass = implode(' ', array_values(array_unique($bodyClasses)));
$styleVersion = (string) (@filemtime(PUBLIC_PATH . '/assets/style.css') ?: time());
$notifLabel = 'Notifikasi';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎓</text></svg>">
    <link rel="stylesheet" href="<?= e(url('assets/style.css?v=' . $styleVersion)) ?>">
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
<header class="topbar">
    <div class="container topbar-inner">
        <div class="brand-lockup">
            <button type="button" id="mobile-menu-toggle" class="mobile-menu-btn" aria-label="Buka Menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>
            <a href="<?= e(url('dasbor.php')) ?>" class="topbar-brand-link">
                <div class="brand-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                        <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                    </svg>
                </div>
                <span class="brand-name">EduTrack</span>
            </a>
        </div>
        <?php if ($user): ?>
            <div class="topbar-meta">
                <div class="topbar-user">
                    <a href="<?= e(url('profil.php')) ?>" class="topbar-profile-link">
                        <svg class="topbar-profile-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                        <span>Halo, <strong><?= e(ucfirst($user['username'])) ?></strong></span>
                    </a>
                </div>
                <?php if ($showNotifMenu): ?>
                    <a class="notif-link topbar-notif <?= $isNotifikasi ? 'active' : '' ?>" href="<?= e(url('notifikasi.php')) ?>" aria-label="Buka notifikasi saya">
                        <svg class="notif-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                        </svg>
                        <span><?= e($notifLabel) ?></span>
                        <?php if ($totalNotifBadge > 0): ?>
                            <span class="notif-badge"><?= e((string) $totalNotifBadge) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
                <a href="<?= e(url('keluar.php')) ?>" class="topbar-logout">
                    <svg class="topbar-logout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    <span>Logout</span>
                </a>
            </div>
        <?php endif; ?>
    </div>
</header>
<?php if ($user): ?>
<div id="mobile-nav-backdrop" class="mobile-nav-backdrop"></div>
<div class="topnav-shell" id="mobile-nav-shell">
    <div class="container">
        <nav class="topnav">
            <a class="<?= $isDasbor ? 'active' : '' ?>" href="<?= e(url('dasbor.php')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
                Dashboard
            </a>
            <?php if ($user['role'] === 'admin'): ?>
                <a class="<?= $isAdminPengguna ? 'active' : '' ?>" href="<?= e(url('admin/pengguna.php')) ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    Manajemen Pengguna
                </a>
                <a class="<?= $isAdminAkademik ? 'active' : '' ?>" href="<?= e(url('admin/akademik.php')) ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
                    </svg>
                    Data Akademik
                </a>
            <?php endif; ?>
            <?php if ($user['role'] === 'guru'): ?>
                <a class="<?= $isGuruAbsensi ? 'active' : '' ?>" href="<?= e(url('guru/absensi.php')) ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                    Input Absensi
                </a>
                <a class="<?= $isGuruNilai ? 'active' : '' ?>" href="<?= e(url('guru/nilai.php')) ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                    </svg>
                    Input Nilai
                </a>
            <?php endif; ?>
            <a class="<?= $isLaporan ? 'active' : '' ?>" href="<?= e(url('laporan.php')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path>
                </svg>
                Laporan
            </a>
            <a class="<?= $isProfil ? 'active' : '' ?>" href="<?= e(url('profil.php')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>
                </svg>
                Profil Saya
            </a>
        </nav>
    </div>
</div>
<script>
(() => {
    const toggleBtn = document.getElementById('mobile-menu-toggle');
    const navShell = document.getElementById('mobile-nav-shell');
    const backdrop = document.getElementById('mobile-nav-backdrop');

    if (!toggleBtn || !navShell || !backdrop) return;

    const toggleMenu = () => {
        navShell.classList.toggle('show');
        backdrop.classList.toggle('show');
        // Mencegah halaman utama bisa di-scroll saat menu samping terbuka
        const isShowing = navShell.classList.contains('show');
        document.body.style.overflow = isShowing ? 'hidden' : '';
        document.documentElement.style.overflow = isShowing ? 'hidden' : '';
    };

    toggleBtn.addEventListener('click', toggleMenu);
    backdrop.addEventListener('click', toggleMenu);
})();
</script>
<?php endif; ?>
<main class="container app-content">
