<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . url('dasbor.php'));
    exit;
}

$styleVersion = (string) (@filemtime(PUBLIC_PATH . '/assets/style.css') ?: time());
$message = '';
$error = '';
$resetRateMaxAttempts = 5;
$resetRateWindowSeconds = 600;
$resetRateLockSeconds = 600;

function absolute_url(string $path): string
{
    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $host) !== 1) {
        $host = '';
    }

    if ($host === '') {
        return url($path);
    }

    return $scheme . '://' . $host . url($path);
}

function log_reset_link(string $email, string $link): void
{
    $dir = STORAGE_PATH;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = date('Y-m-d H:i:s') . ' | ' . $email . ' | ' . $link . PHP_EOL;
    @file_put_contents($dir . '/reset_mail_debug.log', $line, FILE_APPEND);
}

if (request_method_is('POST')) {
    $rateFailureRecorded = false;
    $username = post_string('username');
    $resetRateKeys = [
        'forgot-password:' . client_identity(),
        'forgot-password:' . client_identity() . ':' . strtolower($username !== '' ? $username : 'guest'),
    ];
    $rateStatus = ['blocked' => false, 'remaining_seconds' => 0, 'remaining_attempts' => $resetRateMaxAttempts];

    foreach ($resetRateKeys as $rateKey) {
        $currentStatus = rate_limit_db_status($pdo, $rateKey, $resetRateMaxAttempts, $resetRateWindowSeconds);
        if ($currentStatus['blocked']) {
            $rateStatus = $currentStatus;
            break;
        }
        if ($currentStatus['remaining_attempts'] < $rateStatus['remaining_attempts']) {
            $rateStatus = $currentStatus;
        }
    }

    if ($rateStatus['blocked']) {
        $wait = max(1, (int) $rateStatus['remaining_seconds']);
        $error = 'Terlalu banyak permintaan reset password. Coba lagi dalam ' . $wait . ' detik.';
    } elseif (!verify_csrf_token(post_string('csrf_token'))) {
        $error = 'Token keamanan tidak valid. Muat ulang halaman dan coba lagi.';
        foreach ($resetRateKeys as $rateKey) {
            rate_limit_db_register_failure($pdo, $rateKey, $resetRateMaxAttempts, $resetRateWindowSeconds, $resetRateLockSeconds);
        }
        $rateFailureRecorded = true;
    } else {
        $contact = post_string('email');
        $email = strtolower($contact);

        if ($username === '' || $contact === '') {
            $error = 'Username dan email verifikasi wajib diisi.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Format email yang dimasukkan tidak valid.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    "SELECT
                        u.id_user,
                        u.username,
                        a.email AS admin_email,
                        g.email AS guru_email,
                        s.email AS siswa_email
                    FROM tabel_users u
                    LEFT JOIN tabel_admin a ON a.id_user = u.id_user
                    LEFT JOIN tabel_guru g ON g.id_user = u.id_user
                    LEFT JOIN tabel_siswa s ON s.id_user = u.id_user
                    WHERE u.username = ?
                    LIMIT 1"
                );
                $stmt->execute([$username]);
                $account = $stmt->fetch();

                $contactMatched = false;
                if ($account) {
                    $candidates = [
                        (string) ($account['admin_email'] ?? ''),
                        (string) ($account['guru_email'] ?? ''),
                        (string) ($account['siswa_email'] ?? ''),
                    ];

                    foreach ($candidates as $candidate) {
                        if ($candidate === '') {
                            continue;
                        }

                        $candidateEmail = strtolower($candidate);
                        if (filter_var($candidateEmail, FILTER_VALIDATE_EMAIL) && hash_equals($candidateEmail, $email)) {
                            $contactMatched = true;
                            break;
                        }
                    }
                }

                if ($account && $contactMatched) {
                    $idUser = (int) $account['id_user'];
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare('UPDATE tabel_password_reset_tokens SET used_at = NOW() WHERE id_user = ? AND used_at IS NULL');
                    $stmt->execute([$idUser]);

                    $stmt = $pdo->prepare('INSERT INTO tabel_password_reset_tokens (id_user, email, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))');
                    $stmt->execute([$idUser, $email, $tokenHash]);

                    $pdo->commit();

                    $resetLink = absolute_url('reset-password.php?token=' . $token);
                    $subject = 'Permintaan Pemulihan Password - EduTrack';
                    
                    $body = '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; background-color: #f8fafc; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .header { background-color: #4f46e5; padding: 24px; text-align: center; color: #ffffff; }
        .header h1 { margin: 0; font-size: 24px; letter-spacing: 1px; }
        .content { padding: 32px 24px; color: #334155; line-height: 1.6; font-size: 15px; }
        .content p { margin-top: 0; margin-bottom: 16px; }
        .button-wrap { text-align: center; margin: 32px 0; }
        .button { display: inline-block; padding: 12px 24px; background-color: #4f46e5; color: #ffffff !important; text-decoration: none; font-weight: 600; border-radius: 6px; }
        .footer { background-color: #f1f5f9; padding: 20px; text-align: center; font-size: 13px; color: #64748b; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>EDUTRACK SEKOLAH</h1>
        </div>
        <div class="content">
            <p>Yth. <strong>' . e($username) . '</strong>,</p>
            <p>Kami menerima permintaan untuk mengatur ulang password akun EduTrack Anda. Jika Anda memang meminta pemulihan ini, silakan klik tombol di bawah ini untuk membuat password baru:</p>
            <div class="button-wrap">
                <a href="' . $resetLink . '" class="button">Atur Ulang Password</a>
            </div>
            <p>Tautan ini hanya berlaku selama <strong>24 jam</strong> ke depan dan hanya dapat digunakan satu kali.</p>
            <p>Jika Anda merasa tidak pernah meminta reset password, Anda dapat mengabaikan email ini dengan aman. Password Anda tidak akan berubah.</p>
            <p>Hormat kami,<br><strong>Tim Administrator EduTrack</strong></p>
        </div>
        <div class="footer">
            <p>Email ini dikirim secara otomatis oleh sistem. Mohon untuk tidak membalas email ini.</p>
        </div>
    </div>
</body>
</html>';
                    $headers = "MIME-Version: 1.0\r\n";
                    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                    $headers .= "From: Admin EduTrack <noreply@edutrack.local>\r\n";

                    $sent = @mail($email, $subject, $body, $headers);
                    if (!$sent) {
                        log_reset_link($email, $resetLink);
                        $serverName = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
                        $isLocalRequest = in_array($serverName, ['localhost', '127.0.0.1', '::1'], true);
                        if ((defined('APP_ENV') && APP_ENV === 'development') || $isLocalRequest) {
                            $message = 'Selesai! Karena server lokal (XAMPP), email ditahan. Cek tautan reset Anda di storage/reset_mail_debug.log';
                        } else {
                            $message = 'Permintaan diterima. Jika email server belum aktif, tautan reset akan ditangani oleh administrator.';
                        }
                    } else {
                        $message = 'Berhasil! Instruksi pemulihan password beserta tautan telah dikirim ke alamat email Anda.';
                    }

                    foreach ($resetRateKeys as $rateKey) {
                        rate_limit_db_clear($pdo, $rateKey);
                    }
                } else {
                    // Pesan dibuat netral agar tidak membocorkan akun valid.
                    $message = 'Permintaan diterima. Jika data akun cocok, tautan reset akan dibuat untuk akun Anda.';
                    foreach ($resetRateKeys as $rateKey) {
                        rate_limit_db_register_failure($pdo, $rateKey, $resetRateMaxAttempts, $resetRateWindowSeconds, $resetRateLockSeconds);
                    }
                    $rateFailureRecorded = true;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Gagal memproses permintaan reset password.';
            }
        }

        if ($error !== '' && !$rateFailureRecorded) {
            foreach ($resetRateKeys as $rateKey) {
                rate_limit_db_register_failure($pdo, $rateKey, $resetRateMaxAttempts, $resetRateWindowSeconds, $resetRateLockSeconds);
            }
        }
    }
}

?><!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password - Sistem Informasi Siswa</title>
    <link rel="stylesheet" href="<?= e(url('assets/style.css?v=' . $styleVersion)) ?>">
</head>
<body class="login-portal login-ready">

<main class="login-main">
    <div class="login-shell">
        <section class="login-card" aria-label="Form lupa password">
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
                <h2>Reset Password</h2>
                <p>Masukkan username dan email verifikasi Anda. Jika sesuai, sistem akan mengirimkan tautan reset password ke email Anda.</p>
            </div>
            <div class="login-right">
                <?php if ($message): ?>
                    <div class="alert success"><?= e($message) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="login-alert"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div class="login-field">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required autocomplete="username">
                    </div>
                    <div class="login-field">
                        <label for="email">Email Verifikasi</label>
                        <input type="email" id="email" name="email" required autocomplete="email">
                    </div>
                    <button class="login-submit" type="submit">Kirim Link Reset</button>
                </form>
                <p class="auth-help-links"><a href="<?= e(url('masuk.php')) ?>">Kembali ke login</a></p>
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
