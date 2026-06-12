<?php

declare(strict_types=1);

// Konfigurasi koneksi database utama. Nilai lokal tetap menjadi fallback untuk XAMPP.
$host = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: 'edutrack_db';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS');
if ($dbPass === false) {
    $dbPass = '';
}

// Buat koneksi PDO agar query bisa memakai prepared statement.
try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    // Jika koneksi gagal, hentikan aplikasi dengan pesan yang jelas.
    http_response_code(500);
    error_log('Koneksi database gagal: ' . $e->getMessage());
    $serverName = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
    $isLocalRequest = in_array($serverName, ['localhost', '127.0.0.1', '::1'], true);

    if ((defined('APP_ENV') && APP_ENV === 'development') || $isLocalRequest) {
        $errorMsg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        die("
            <div style='font-family: system-ui, sans-serif; max-width: 600px; margin: 40px auto; padding: 24px; border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
                <h2 style='margin-top: 0;'>Koneksi Database Gagal</h2>
                <p>Aplikasi tidak dapat terhubung ke database. Ini <b>bukan error pada kode program</b>, melainkan database belum diaktifkan.</p>
                <ol style='line-height: 1.6;'>
                    <li>Buka <b>XAMPP Control Panel</b>.</li>
                    <li>Klik tombol <b>Start</b> pada modul <b>MySQL</b> sampai berwarna hijau.</li>
                    <li>Buka phpMyAdmin, pastikan Anda sudah membuat database bernama <b>edutrack_db</b>.</li>
                    <li>Import file <b>database/schema.sql</b> lalu <b>database/seed.sql</b>.</li>
                </ol>
                <p style='font-size: 13px; margin-bottom: 0; padding-top: 12px; border-top: 1px solid #fca5a5;'>Detail teknis: {$errorMsg}</p>
            </div>
        ");
    }

    die("
        <div style='font-family: system-ui, sans-serif; max-width: 600px; margin: 40px auto; padding: 24px; border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
            <h2 style='margin-top: 0;'>Layanan Sementara Tidak Tersedia</h2>
            <p>Aplikasi belum dapat terhubung ke database. Silakan coba lagi nanti atau hubungi administrator.</p>
        </div>
    ");
}

// Ubah key rate limit menjadi hash agar aman disimpan.
function rate_limit_storage_key(string $key): string
{
    return hash('sha256', $key);
}

// Normalisasi daftar timestamp dari JSON yang disimpan di database.
function rate_limit_decode_attempts(string $attemptsJson): array
{
    $decoded = json_decode($attemptsJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    $normalized = [];
    foreach ($decoded as $value) {
        if (is_int($value)) {
            $normalized[] = $value;
            continue;
        }

        if (is_string($value) && ctype_digit($value)) {
            $normalized[] = (int) $value;
        }
    }

    return $normalized;
}

// Ambil status rate limit dari database.
function rate_limit_db_status(PDO $pdo, string $key, int $maxAttempts, int $windowSeconds): array
{
    $now = time();
    $storageKey = rate_limit_storage_key($key);
    $stmt = $pdo->prepare('SELECT attempts_json, blocked_until FROM tabel_rate_limits WHERE key_hash = ? LIMIT 1');
    $stmt->execute([$storageKey]);
    $row = $stmt->fetch();

    $attempts = [];
    $blockedUntil = 0;

    if ($row) {
        $attempts = rate_limit_decode_attempts((string) ($row['attempts_json'] ?? '[]'));
        $blockedUntil = (int) strtotime((string) ($row['blocked_until'] ?? ''));
        if ($blockedUntil <= 0) {
            $blockedUntil = 0;
        }
    }

    $attempts = array_values(array_filter($attempts, static fn ($value) => is_int($value) && ($now - $value) < $windowSeconds));
    $blocked = $blockedUntil > $now;

    return [
        'blocked' => $blocked,
        'remaining_seconds' => $blocked ? ($blockedUntil - $now) : 0,
        'remaining_attempts' => max(0, $maxAttempts - count($attempts)),
    ];
}

// Catat kegagalan dan aktifkan blok sementara bila batas tercapai.
function rate_limit_db_register_failure(PDO $pdo, string $key, int $maxAttempts, int $windowSeconds, int $lockSeconds): array
{
    $now = time();
    $storageKey = rate_limit_storage_key($key);
    $stmt = $pdo->prepare('SELECT attempts_json, blocked_until FROM tabel_rate_limits WHERE key_hash = ? LIMIT 1');
    $stmt->execute([$storageKey]);
    $row = $stmt->fetch();

    $attempts = [];
    $blockedUntil = 0;

    if ($row) {
        $attempts = rate_limit_decode_attempts((string) ($row['attempts_json'] ?? '[]'));
        $blockedUntil = (int) strtotime((string) ($row['blocked_until'] ?? ''));
        if ($blockedUntil <= 0) {
            $blockedUntil = 0;
        }
    }

    $attempts = array_values(array_filter($attempts, static fn ($value) => is_int($value) && ($now - $value) < $windowSeconds));
    $attempts[] = $now;

    if (count($attempts) >= $maxAttempts) {
        $blockedUntil = $now + $lockSeconds;
        $attempts = [];
    }

    $upsert = $pdo->prepare(
        'INSERT INTO tabel_rate_limits (key_hash, bucket_label, attempts_json, blocked_until)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             bucket_label = VALUES(bucket_label),
             attempts_json = VALUES(attempts_json),
             blocked_until = VALUES(blocked_until),
             updated_at = CURRENT_TIMESTAMP'
    );
    $upsert->execute([
        $storageKey,
        substr($key, 0, 64),
        json_encode($attempts, JSON_UNESCAPED_SLASHES),
        $blockedUntil > 0 ? date('Y-m-d H:i:s', $blockedUntil) : null,
    ]);

    return rate_limit_db_status($pdo, $key, $maxAttempts, $windowSeconds);
}

// Hapus data rate limit saat proses berhasil.
function rate_limit_db_clear(PDO $pdo, string $key): void
{
    $stmt = $pdo->prepare('DELETE FROM tabel_rate_limits WHERE key_hash = ?');
    $stmt->execute([rate_limit_storage_key($key)]);
}
