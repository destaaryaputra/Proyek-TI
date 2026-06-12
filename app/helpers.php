<?php

declare(strict_types=1);

// Pastikan session siap dipakai sebelum helper autentikasi dijalankan.
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    // Kunci cookie session agar lebih aman terhadap XSS/CSRF.
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$projectRoot = realpath(defined('PROJECT_ROOT') ? PROJECT_ROOT : (__DIR__ . '/../..'));
$publicRoot = realpath(defined('PUBLIC_PATH') ? PUBLIC_PATH : (dirname(__DIR__) . '/public'));
$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
$baseUrl = '';

// Encode path URL per segmen agar folder dengan spasi tetap valid di header/link.
function encode_url_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $leadingSlash = str_starts_with($path, '/');
    $trailingSlash = $path !== '/' && str_ends_with($path, '/');
    $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($segment) => $segment !== ''));
    $encoded = array_map(static fn ($segment) => rawurlencode(rawurldecode($segment)), $segments);
    $result = implode('/', $encoded);

    if ($leadingSlash) {
        $result = '/' . $result;
    }

    if ($trailingSlash) {
        $result .= '/';
    }

    return $result === '' && $leadingSlash ? '/' : $result;
}

// Coba bangun BASE_URL dari lokasi folder public di web root agar link tetap konsisten.
if ($publicRoot !== false && $documentRoot !== false) {
    $normalizedPublicRoot = rtrim(str_replace('\\', '/', $publicRoot), '/');
    $normalizedDocumentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');

    if ($normalizedDocumentRoot !== '' && strcasecmp($normalizedPublicRoot, $normalizedDocumentRoot) === 0) {
        $baseUrl = '/';
    } elseif ($normalizedDocumentRoot !== '' && stripos($normalizedPublicRoot, $normalizedDocumentRoot) === 0) {
        $relativePath = trim(substr($normalizedPublicRoot, strlen($normalizedDocumentRoot)), '/');
        $baseUrl = encode_url_path('/' . $relativePath);
    }
}

if ($baseUrl === '') {
    $publicPos = strpos($scriptName, '/public/');
    $appPos = strpos($scriptName, '/app/');

    if ($publicPos !== false) {
        $baseUrl = rtrim(substr($scriptName, 0, $publicPos), '/') . '/public';
    } elseif ($appPos !== false) {
        $baseUrl = rtrim(substr($scriptName, 0, $appPos), '/') . '/public';
    } else {
        $baseUrl = rtrim(str_replace('\\', '/', dirname($scriptName)), '/') . '/public';
    }

    $baseUrl = encode_url_path($baseUrl);
}

if ($baseUrl === '') {
    $baseUrl = '/projek%20ti/public';
}

if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim($baseUrl, '/'));
}

// Bangun URL absolut ke halaman aplikasi di folder public.
function url(string $path = ''): string
{
    $base = rtrim(BASE_URL, '/');
    $cleanPath = ltrim($path, '/');

    if ($base === '') {
        return $cleanPath === '' ? '/' : '/' . $cleanPath;
    }

    return $cleanPath === '' ? $base . '/' : $base . '/' . $cleanPath;
}

// Helper autentikasi yang dipakai lintas halaman.
function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

// Ambil data user yang sedang login dari session.
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

// Paksa user login sebelum membuka halaman tertentu.
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . url('masuk.php'));
        exit;
    }
}

// Batasi akses berdasarkan role.
function require_role(array $roles): void
{
    require_login();
    $role = $_SESSION['user']['role'] ?? '';
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        die('Akses ditolak.');
    }
}

// Ambil atau buat CSRF token session.
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// Verifikasi CSRF token dari request.
function verify_csrf_token(?string $token): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '' || !is_string($token) || $token === '') {
        return false;
    }

    return hash_equals($sessionToken, $token);
}

// Ambil nilai POST sebagai string ter-trim agar validasi lebih konsisten.
function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    if (!is_scalar($value)) {
        return $default;
    }

    return trim((string) $value);
}

// Ambil nilai GET sebagai string ter-trim.
function get_string(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    if (!is_scalar($value)) {
        return $default;
    }

    return trim((string) $value);
}

// Ambil nilai POST sebagai integer.
function post_int(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? $default;
    if (is_int($value)) {
        return $value;
    }

    if (!is_scalar($value) || !is_numeric((string) $value)) {
        return $default;
    }

    return (int) $value;
}

// Ambil nilai POST integer opsional (null jika kosong atau tidak valid).
function post_nullable_int(string $key): ?int
{
    $value = $_POST[$key] ?? null;
    if ($value === null || $value === '') {
        return null;
    }

    if (is_int($value)) {
        return $value;
    }

    if (!is_scalar($value) || !is_numeric((string) $value)) {
        return null;
    }

    return (int) $value;
}

// Cek apakah request datang dari AJAX (misal: fetch JavaScript).
function is_ajax_request(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// Cek method request secara konsisten.
function request_method_is(string $method): bool
{
    $currentMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return $currentMethod === strtoupper($method);
}

// Ambil nilai GET sebagai integer.
function get_int(string $key, int $default = 0): int
{
    $value = $_GET[$key] ?? $default;
    if (is_int($value)) {
        return $value;
    }

    if (!is_scalar($value) || !is_numeric((string) $value)) {
        return $default;
    }

    return (int) $value;
}

// Ambil nilai request dan validasi agar masuk dalam daftar yang diizinkan.
function post_enum(string $key, array $allowed, string $default = ''): string
{
    $value = post_string($key, $default);
    return in_array($value, $allowed, true) ? $value : $default;
}

// Ambil nilai request GET dan validasi agar masuk dalam daftar yang diizinkan.
function get_enum(string $key, array $allowed, string $default = ''): string
{
    $value = get_string($key, $default);
    return in_array($value, $allowed, true) ? $value : $default;
}

// Normalisasi nomor telepon agar perbandingan kontak lebih stabil.
function normalize_phone_number(string $value): string
{
    return preg_replace('/\D+/', '', trim($value)) ?? '';
}

// Normalisasi path internal supaya aman dipakai untuk redirect lokal.
function normalize_internal_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    $parsed = parse_url($path);
    if ($parsed === false) {
        return '';
    }

    $candidatePath = isset($parsed['path']) ? (string) $parsed['path'] : '';
    if ($candidatePath === '' || str_contains($candidatePath, '..')) {
        return '';
    }

    $query = isset($parsed['query']) ? ('?' . $parsed['query']) : '';
    return $candidatePath . $query;
}

// Ambil identitas client sederhana untuk pembatasan request berbasis session.
function client_identity(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown-agent'));
    return hash('sha256', $ip . '|' . $ua);
}

// Pastikan container rate limit tersedia di session.
function ensure_rate_limit_store(): void
{
    if (!isset($_SESSION['rate_limit']) || !is_array($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [];
    }
}

// Hitung status rate limit untuk key tertentu.
function rate_limit_status(string $key, int $maxAttempts, int $windowSeconds): array
{
    ensure_rate_limit_store();
    $now = time();
    $bucket = $_SESSION['rate_limit'][$key] ?? null;

    if (!is_array($bucket)) {
        $bucket = [
            'attempts' => [],
            'blocked_until' => 0,
        ];
    }

    $attempts = is_array($bucket['attempts'] ?? null) ? $bucket['attempts'] : [];
    $attempts = array_values(array_filter($attempts, static fn ($ts) => is_int($ts) && ($now - $ts) < $windowSeconds));
    $blockedUntil = (int) ($bucket['blocked_until'] ?? 0);

    $isBlocked = $blockedUntil > $now;
    $remainingSeconds = $isBlocked ? ($blockedUntil - $now) : 0;
    $remainingAttempts = max(0, $maxAttempts - count($attempts));

    $_SESSION['rate_limit'][$key] = [
        'attempts' => $attempts,
        'blocked_until' => $blockedUntil,
    ];

    return [
        'blocked' => $isBlocked,
        'remaining_seconds' => $remainingSeconds,
        'remaining_attempts' => $remainingAttempts,
    ];
}

// Catat percobaan gagal dan kunci sementara jika melewati batas.
function rate_limit_register_failure(string $key, int $maxAttempts, int $windowSeconds, int $lockSeconds): array
{
    ensure_rate_limit_store();
    $now = time();
    $bucket = $_SESSION['rate_limit'][$key] ?? [
        'attempts' => [],
        'blocked_until' => 0,
    ];

    $attempts = is_array($bucket['attempts'] ?? null) ? $bucket['attempts'] : [];
    $attempts = array_values(array_filter($attempts, static fn ($ts) => is_int($ts) && ($now - $ts) < $windowSeconds));
    $attempts[] = $now;

    $blockedUntil = (int) ($bucket['blocked_until'] ?? 0);
    if (count($attempts) >= $maxAttempts) {
        $blockedUntil = $now + $lockSeconds;
        $attempts = [];
    }

    $_SESSION['rate_limit'][$key] = [
        'attempts' => $attempts,
        'blocked_until' => $blockedUntil,
    ];

    return rate_limit_status($key, $maxAttempts, $windowSeconds);
}

// Bersihkan state rate limit jika proses berhasil.
function rate_limit_clear(string $key): void
{
    ensure_rate_limit_store();
    unset($_SESSION['rate_limit'][$key]);
}

// Escape output HTML untuk mencegah XSS.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Beri kelas badge yang konsisten untuk role user.
function role_badge_class(string $role): string
{
    return match ($role) {
        'admin' => 'danger',
        'guru' => 'success',
        default => '',
    };
}

// Ubah kode jenis kelamin menjadi label yang mudah dibaca.
function gender_label(?string $value): string
{
    return match ($value) {
        'L' => 'Laki-laki',
        'P' => 'Perempuan',
        default => '-',
    };
}

// Render pagination yang dipakai pada beberapa halaman admin.
function pagination_links(string $baseUrl, string $pageKey, int $currentPage, int $totalPages, array $extraParams = []): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $buildUrl = static function (int $page) use ($baseUrl, $pageKey, $extraParams): string {
        $params = array_merge($extraParams, [$pageKey => $page]);
        return $baseUrl . '?' . http_build_query($params);
    };

    $links = '<div class="pagination">';
    $links .= '<div class="page-prev">';
    if ($currentPage > 1) {
        $links .= '<a class="badge filter-print js-pagination-link" href="' . e($buildUrl($currentPage - 1)) . '">Sebelumnya</a>';
    }
    $links .= '</div>';

    $links .= '<div class="page-info"><span class="footer-note">Halaman ' . e((string) $currentPage) . ' dari ' . e((string) $totalPages) . '</span></div>';

    $links .= '<div class="page-next">';
    if ($currentPage < $totalPages) {
        $links .= '<a class="badge filter-print js-pagination-link" href="' . e($buildUrl($currentPage + 1)) . '">Berikutnya</a>';
    }
    $links .= '</div>';

    $links .= '</div>';
    return $links;
}

// Standar minimal password untuk seluruh flow autentikasi.
function password_min_length(): int
{
    return 8;
}
