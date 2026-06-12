<?php

declare(strict_types=1);

// Halaman login: validasi akun lalu simpan data user ke session.
require_once __DIR__ . '/../app/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . url('dasbor.php'));
    exit;
}

$error = '';
$message = '';
$styleVersion = (string) (@filemtime(PUBLIC_PATH . '/assets/style.css') ?: time());

if (get_string('reset') === 'success') {
    $message = 'Password berhasil direset. Silakan login dengan password baru.';
}

$loginRateKey = 'login:' . client_identity();
$loginRateMaxAttempts = 6;
$loginRateWindowSeconds = 300;
$loginRateLockSeconds = 300;

if (request_method_is('POST')) {
    $rateFailureRecorded = false;
    $username = post_string('username');
    $loginRateKeys = [
        'login:' . client_identity(),
        'login:' . client_identity() . ':' . strtolower($username !== '' ? $username : 'guest'),
    ];
    $rateStatus = ['blocked' => false, 'remaining_seconds' => 0, 'remaining_attempts' => $loginRateMaxAttempts];

    foreach ($loginRateKeys as $rateKey) {
        $currentStatus = rate_limit_db_status($pdo, $rateKey, $loginRateMaxAttempts, $loginRateWindowSeconds);
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
        $error = 'Terlalu banyak percobaan login. Coba lagi dalam ' . $wait . ' detik.';
    } elseif (!verify_csrf_token(post_string('csrf_token'))) {
        $error = 'Sesi tidak valid. Silakan refresh halaman dan coba lagi.';
        $rateFailureRecorded = true;
    }

    $passwordValue = $_POST['password'] ?? '';
    $password = is_scalar($passwordValue) ? (string) $passwordValue : '';

    if ($error !== '') {
        $username = '';
        $password = '';
    }

    $user = null;
    $isValid = false;

    if ($error === '') {
        // Cari user berdasarkan username untuk proses autentikasi.
        $stmt = $pdo->prepare('SELECT id_user, username, password, role FROM tabel_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user) {
            $storedPassword = (string) $user['password'];
            if (str_starts_with($storedPassword, '$2y$') || str_starts_with($storedPassword, '$argon2')) {
                // Password modern dicek langsung dengan password_verify.
                $isValid = password_verify($password, $storedPassword);
            } else {
                // Seed lama masih plaintext, jadi diverifikasi lalu di-upgrade ke hash.
                $isValid = hash_equals($storedPassword, $password);
                if ($isValid) {
                    $rehash = password_hash($password, PASSWORD_BCRYPT);
                    $update = $pdo->prepare('UPDATE tabel_users SET password = ? WHERE id_user = ?');
                    $update->execute([$rehash, (int) $user['id_user']]);
                }
            }
        }
    }

    if ($error === '' && $user && $isValid) {
        foreach ($loginRateKeys as $rateKey) {
            rate_limit_db_clear($pdo, $rateKey);
        }
        session_regenerate_id(true);
        // Simpan identitas minimal yang dibutuhkan sepanjang session.
        $_SESSION['user'] = [
            'id_user' => (int) $user['id_user'],
            'username' => $user['username'],
            'role' => $user['role'],
        ];
        header('Location: ' . url('dasbor.php'));
        exit;
    }

    if ($error === '') {
        foreach ($loginRateKeys as $rateKey) {
            $rateStatus = rate_limit_db_register_failure($pdo, $rateKey, $loginRateMaxAttempts, $loginRateWindowSeconds, $loginRateLockSeconds);
        }
        $rateFailureRecorded = true;
        if ($rateStatus['blocked']) {
            $wait = max(1, (int) $rateStatus['remaining_seconds']);
            $error = 'Terlalu banyak percobaan login. Coba lagi dalam ' . $wait . ' detik.';
        } else {
            $remaining = max(0, (int) $rateStatus['remaining_attempts']);
            $error = 'Username atau password salah. Sisa percobaan: ' . $remaining . '.';
        }
    }

    if ($error !== '' && !$rateFailureRecorded) {
        foreach ($loginRateKeys as $rateKey) {
            rate_limit_db_register_failure($pdo, $rateKey, $loginRateMaxAttempts, $loginRateWindowSeconds, $loginRateLockSeconds);
        }
    }

}

?><!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - EduTrack</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎓</text></svg>">
    <link rel="stylesheet" href="<?= e(url('assets/style.css?v=' . $styleVersion)) ?>">
    <noscript>
        <style>
            body.login-portal.login-preload .login-header,
            body.login-portal.login-preload .login-main {
                opacity: 1 !important;
                transform: none !important;
                filter: none !important;
            }
        </style>
    </noscript>
</head>
<body class="login-portal login-preload">

<main class="login-main">
    <div class="login-shell">
        <section class="login-card" aria-label="Panel login portal siswa">
            <div class="login-left">
                <div class="login-brand-panel">
                    <div class="brand-icon login-brand-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                            <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                        </svg>
                    </div>
                    <span>EDUTRACK</span>
                </div>
                
                <div class="login-copy">
                    <span class="login-eyebrow">Portal Akademik Sekolah</span>
                    <h2>Efisiensi dalam setiap langkah akademik.</h2>
                    <p>Satu platform terpadu untuk memudahkan kolaborasi antara guru, siswa, dan orang tua dalam memantau perkembangan belajar.</p>
                </div>

                <?php
                $quotes = [
                    ['text' => 'Pendidikan adalah senjata paling mematikan di dunia, karena dengan pendidikan, Anda dapat mengubah dunia.', 'cite' => 'Nelson Mandela'],
                    ['text' => 'Hiduplah seolah-olah kamu mati besok. Belajarlah seolah-olah kamu hidup selamanya.', 'cite' => 'Mahatma Gandhi'],
                    ['text' => 'Satu anak, satu guru, satu buku, dan satu pena dapat mengubah dunia.', 'cite' => 'Malala Yousafzai'],
                    ['text' => 'Pendidikan bukan persiapan untuk hidup; pendidikan adalah hidup itu sendiri.', 'cite' => 'John Dewey'],
                    ['text' => 'Investasi dalam pengetahuan memberikan bunga terbaik.', 'cite' => 'Benjamin Franklin'],
                ];
                $randomQuote = $quotes[array_rand($quotes)];
                ?>
                
                <div class="login-quote" aria-hidden="true">
                    <blockquote>"<?= e($randomQuote['text']) ?>"</blockquote>
                    <cite>— <?= e($randomQuote['cite']) ?></cite>
                </div>
            </div>

            <div class="login-right">
                <h3>Login ke Portal</h3>
                <p class="sub">Silakan gunakan akun sekolah Anda untuk melanjutkan.</p>
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
                        <div class="login-input-wrap">
                            <div class="icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.8"/>
                                    <path d="M5 18.5C5.7 15.9 8 14 10.8 14H13.2C16 14 18.3 15.9 19 18.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                            </div>
                            <input type="text" id="username" name="username" required autocomplete="username" placeholder="username@sekolah.edu">
                        </div>
                    </div>
                    <div class="login-field">
                        <label for="password">Password</label>
                        <div class="login-input-wrap">
                            <div class="icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <rect x="5" y="11" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.8"/>
                                    <path d="M8 11V8.5C8 6.6 9.6 5 11.5 5H12.5C14.4 5 16 6.6 16 8.5V11" stroke="currentColor" stroke-width="1.8"/>
                                </svg>
                            </div>
                            <input type="password" id="password" name="password" class="has-toggle" required autocomplete="current-password" placeholder="••••••••">
                            <button type="button" class="toggle-password" aria-label="Tampilkan password" title="Tampilkan/Sembunyikan password">
                                <svg class="eye-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                <svg class="eye-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px; display: none;">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <button class="login-submit" type="submit">Login</button>
                </form>
                <p class="auth-help-links"><a href="<?= e(url('lupa-password.php')) ?>">Lupa password?</a></p>
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
<script>
(() => {
    const body = document.body;
    let revealed = false;

    const initScrollReveal = () => {
        if (!('IntersectionObserver' in window)) {
            return;
        }

        const targets = Array.from(document.querySelectorAll([
            '.login-header-row',
            '.login-left',
            '.login-right',
            '.login-field',
            '.login-submit'
        ].join(',')));

        if (!targets.length) {
            return;
        }

        const assignDirection = (el) => {
            const rect = el.getBoundingClientRect();
            const vh = window.innerHeight || document.documentElement.clientHeight;
            const center = rect.top + (rect.height / 2);

            if (center < vh * 0.33) {
                el.dataset.reveal = 'top';
            } else if (center > vh * 0.67) {
                el.dataset.reveal = 'bottom';
            } else {
                el.dataset.reveal = 'middle';
            }
        };

        targets.forEach((el, i) => {
            el.classList.add('scroll-reveal');
            el.style.setProperty('--reveal-delay', (i % 4) * 55 + 'ms');
            assignDirection(el);
        });

        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    assignDirection(entry.target);
                    entry.target.classList.add('is-visible');
                } else {
                    entry.target.classList.remove('is-visible');
                }
            });
        }, {
            threshold: 0.08,
            rootMargin: '0px 0px -2% 0px'
        });

        targets.forEach((el) => revealObserver.observe(el));
    };

    const reveal = () => {
        if (revealed) {
            return;
        }
        revealed = true;
        body.classList.add('login-ready');
        body.classList.remove('login-preload');
        initScrollReveal();
    };

    if (document.readyState === 'complete') {
        requestAnimationFrame(reveal);
        return;
    }

    window.addEventListener('load', () => {
        requestAnimationFrame(reveal);
    }, { once: true });

    // Fallback jika event load terlambat
    window.setTimeout(reveal, 600);
})();

    // Logika Toggle Show/Hide Password
    const toggleBtn = document.querySelector('.toggle-password');
    const passInput = document.getElementById('password');
    if (toggleBtn && passInput) {
        toggleBtn.addEventListener('click', () => {
            const isPassword = passInput.getAttribute('type') === 'password';
            passInput.setAttribute('type', isPassword ? 'text' : 'password');
            toggleBtn.querySelector('.eye-show').style.display = isPassword ? 'none' : 'block';
            toggleBtn.querySelector('.eye-hide').style.display = isPassword ? 'block' : 'none';
        });
    }
</script>
</body>
</html>
