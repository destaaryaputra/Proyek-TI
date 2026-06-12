<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . url('dasbor.php'));
    exit;
}

$styleVersion = (string) (@filemtime(PUBLIC_PATH . '/assets/style.css') ?: time());
$error = '';
$message = '';
$token = get_string('token');
if ($token === '') {
    $token = post_string('token');
}

function get_reset_row(PDO $pdo, string $token): ?array
{
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare(
           'SELECT pr.id_reset, pr.id_user, pr.expires_at, pr.used_at, u.username
         FROM tabel_password_reset_tokens pr
         JOIN tabel_users u ON u.id_user = pr.id_user
            WHERE pr.token_hash = ?
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);

    $row = $stmt->fetch();
    return $row ?: null;
}

$resetRow = $error === '' ? get_reset_row($pdo, $token) : null;
$canReset = false;

if ($error === '' && $token !== '' && !$resetRow) {
    $error = 'Link reset tidak valid.';
} elseif ($resetRow) {
    if ($resetRow['used_at'] !== null) {
        $error = 'Link reset sudah pernah digunakan.';
    } elseif (strtotime((string) $resetRow['expires_at']) <= time()) {
        $error = 'Link reset sudah kedaluwarsa.';
    } else {
        $canReset = true;
    }
}

if (request_method_is('POST') && $error === '') {
    if (!verify_csrf_token(post_string('csrf_token'))) {
        $error = 'Token keamanan tidak valid. Muat ulang halaman dan coba lagi.';
    } elseif (!$canReset || !$resetRow) {
        $error = 'Link reset belum siap digunakan.';
    } else {
        $passwordValue = $_POST['password'] ?? '';
        $passwordConfirmValue = $_POST['password_confirm'] ?? '';
        $password = is_scalar($passwordValue) ? (string) $passwordValue : '';
        $passwordConfirm = is_scalar($passwordConfirmValue) ? (string) $passwordConfirmValue : '';

        if (strlen($password) < password_min_length()) {
            $error = 'Password minimal ' . password_min_length() . ' karakter.';
        } elseif (!hash_equals($password, $passwordConfirm)) {
            $error = 'Konfirmasi password tidak sama.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('UPDATE tabel_users SET password = ? WHERE id_user = ?');
                $stmt->execute([password_hash($password, PASSWORD_BCRYPT), (int) $resetRow['id_user']]);

                $stmt = $pdo->prepare('UPDATE tabel_password_reset_tokens SET used_at = NOW() WHERE id_reset = ?');
                $stmt->execute([(int) $resetRow['id_reset']]);

                $pdo->commit();

                header('Location: ' . url('masuk.php?reset=success'));
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Gagal menyimpan password baru.';
            }
        }
    }
}

?><!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atur Ulang Password - Sistem Informasi Siswa</title>
    <link rel="stylesheet" href="<?= e(url('assets/style.css?v=' . $styleVersion)) ?>">
</head>
<body class="login-portal login-ready">

<main class="login-main">
    <div class="login-shell">
        <section class="login-card" aria-label="Form reset password">
            <div class="login-left">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 40px;">
                    <div class="brand-icon" aria-hidden="true" style="background: rgba(255,255,255,0.2); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                            <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                        </svg>
                    </div>
                    <span style="font-size: 24px; font-weight: 800; letter-spacing: 1px; color: #fff;">EDUTRACK</span>
                </div>
                <h2>Password Baru</h2>
                <p>Link reset hanya berlaku satu kali dan akan kedaluwarsa otomatis.</p>
            </div>
            <div class="login-right">
                <?php if ($message): ?>
                    <div class="alert success"><?= e($message) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="login-alert"><?= e($error) ?></div>
                <?php endif; ?>

                <?php if ($canReset && $resetRow): ?>
                    <p class="sub">Akun: <strong><?= e((string) $resetRow['username']) ?></strong></p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <div class="login-field">
                            <label for="password">Password Baru</label>
                            <input type="password" id="password" name="password" minlength="<?= e((string) password_min_length()) ?>" required autocomplete="new-password">
                        </div>
                        <div class="login-field">
                            <label for="password_confirm">Konfirmasi Password</label>
                            <input type="password" id="password_confirm" name="password_confirm" minlength="<?= e((string) password_min_length()) ?>" required autocomplete="new-password">
                        </div>
                        <button class="login-submit" type="submit">Simpan Password</button>
                    </form>
                <?php else: ?>
            <p class="sub">Silakan minta link reset baru jika link ini tidak valid.</p>
                <?php endif; ?>

                <p class="auth-help-links"><a href="<?= e(url('lupa-password.php')) ?>">Minta link reset baru</a> · <a href="<?= e(url('masuk.php')) ?>">Kembali ke login</a></p>
            </div>
        </section>
    </div>
</main>

<footer class="site-footer">
    <div class="container">
        <p><strong>EduTrack Sekolah</strong></p>
        <p class="footer-note">Sistem pemantauan kehadiran, nilai, dan notifikasi akademik secara terintegrasi.</p>
    </div>
</footer>
</body>
</html>
