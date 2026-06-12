<?php

declare(strict_types=1);

// Halaman inbox notifikasi pribadi untuk user yang sedang login.
require_once __DIR__ . '/../app/bootstrap.php';

require_login();

$user = current_user();

// --- AWAL LOGIKA AJAX MARK READ (Pindahan dari API) ---
if (request_method_is('POST') && post_string('action') === 'mark_read') {
    header('Content-Type: application/json');
    
    if (!verify_csrf_token(post_string('csrf_token'))) {
        http_response_code(419);
        echo json_encode(['status' => 'error', 'message' => 'Token keamanan tidak valid.']);
        exit;
    }

    $idNotifikasi = post_int('id_notifikasi', 0);
    if ($idNotifikasi <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ID tidak valid.']);
        exit;
    }

    try {
        // Update status dibaca, pastikan milik user yang login
        $stmt = $pdo->prepare('UPDATE tabel_notifikasi SET dibaca = TRUE WHERE id_notifikasi = ? AND id_user = ?');
        $stmt->execute([$idNotifikasi, (int) $user['id_user']]);

        echo json_encode(['status' => 'success', 'message' => 'Ditandai sebagai dibaca.']);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Kesalahan server.']);
        exit;
    }
}
// --- AKHIR LOGIKA AJAX ---

$pageTitle = 'Pusat Notifikasi';

// Ambil notifikasi hanya milik akun yang aktif saat ini.
$stmt = $pdo->prepare('SELECT id_notifikasi, pesan, tanggal, dibaca FROM tabel_notifikasi WHERE id_user = ? ORDER BY id_notifikasi DESC LIMIT 50');
$stmt->execute([$user['id_user']]);
$notifs = $stmt->fetchAll();

// Notifikasi khusus admin untuk mendeteksi masalah data/sistem secara cepat.
$adminSystemNotifs = [];
if (($user['role'] ?? '') === 'admin') {
    $checks = [
        [
            'sql' => 'SELECT COUNT(*) FROM tabel_siswa WHERE id_kelas IS NULL',
            'message' => 'Ada %d siswa belum memiliki kelas.',
            'type' => 'error',
        ],
        [
            'sql' => "SELECT COUNT(*) FROM tabel_users u LEFT JOIN tabel_siswa s ON s.id_user = u.id_user WHERE u.role = 'siswa' AND s.id_siswa IS NULL",
            'message' => 'Ada %d akun berperan siswa tanpa profil siswa.',
            'type' => 'error',
        ],
        [
            'sql' => "SELECT COUNT(*) FROM tabel_users u LEFT JOIN tabel_guru g ON g.id_user = u.id_user WHERE u.role = 'guru' AND g.id_guru IS NULL",
            'message' => 'Ada %d akun berperan guru tanpa profil guru.',
            'type' => 'error',
        ],
        [
            'sql' => 'SELECT CASE WHEN COUNT(*) = 0 THEN 1 ELSE 0 END FROM tabel_jadwal',
            'message' => 'Data jadwal masih kosong. Guru tidak bisa input absensi/nilai sebelum jadwal tersedia.',
            'type' => 'warning',
        ],
    ];

    foreach ($checks as $check) {
        $count = (int) $pdo->query($check['sql'])->fetchColumn();
        if ($count > 0) {
            $pesan = str_contains($check['message'], '%d')
                ? sprintf($check['message'], $count)
                : $check['message'];
            $adminSystemNotifs[] = [
                'tanggal' => date('Y-m-d'),
                'pesan' => $pesan,
                'tipe' => $check['type'],
            ];
        }
    }
}

// Tombol kembali diarahkan ke halaman internal aplikasi agar aman dari redirect eksternal.
$backCandidate = get_string('back');
if ($backCandidate === '') {
    $backCandidate = (string) ($_SERVER['HTTP_REFERER'] ?? '');
}

$fallbackBack = url('dasbor.php');
$referrer = $fallbackBack;

if ($backCandidate !== '') {
    $normalizedBack = normalize_internal_path($backCandidate);
    if ($normalizedBack !== '') {
        $basePrefix = BASE_URL . '/';
        if (str_starts_with($normalizedBack, $basePrefix)) {
            $relativePath = ltrim(substr($normalizedBack, strlen(BASE_URL)), '/');
            $referrer = url($relativePath);
        }
    }
}

$title = $pageTitle;
include LAYOUT_PATH . '/header.php';
?>

    <div class="notification-page">
        <section class="card notification-panel" id="notifikasi-saya">
            <div class="section-heading notification-panel-head">
                <div>
                    <p class="brand-kicker">Pusat Notifikasi</p>
                    <h2>Notifikasi Saya (<?= e((string) count($notifs)) ?>)</h2>
                </div>
                <a href="<?= e($referrer) ?>" class="action-link notification-back-link">Kembali</a>
            </div>

            <?php if (!$notifs): ?>
                <div class="empty-state" style="margin-bottom: 24px;">
                    <strong>Belum ada notifikasi.</strong>
                    <p>Semua notifikasi personal Anda akan muncul di halaman ini.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap" style="margin-bottom: 24px;">
                    <table class="notif-table">
                        <thead>
                        <tr>
                            <th>Status</th>
                            <th>Tanggal</th>
                            <th>Pesan</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($notifs as $notif): ?>
                            <tr class="notif-row <?= (bool) $notif['dibaca'] ? 'notif-read' : 'notif-unread' ?>" data-notif-id="<?= e((string) $notif['id_notifikasi']) ?>">
                                <td class="notif-status">
                                    <?php if (!(bool) $notif['dibaca']): ?>
                                        <span class="badge unread-badge">Baru</span>
                                    <?php else: ?>
                                        <span class="status-text read">✓</span>
                                    <?php endif; ?>
                                </td>
                                <td class="notif-date"><?= e($notif['tanggal']) ?></td>
                                <td class="notif-message"><?= e($notif['pesan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (($user['role'] ?? '') === 'admin'): ?>
                <div class="section-heading notification-panel-head" style="margin-top: 16px;">
                    <div>
                        <h2>Notifikasi Sistem (<?= e((string) count($adminSystemNotifs)) ?>)</h2>
                        <p class="brand-kicker">Peringatan Integritas Data</p>
                    </div>
                </div>
                <?php if (!$adminSystemNotifs): ?>
                    <div class="empty-state">
                        <strong>Sistem Sehat 100%</strong>
                        <p>Tidak ada kesalahan sistem atau kekurangan data master yang terdeteksi saat ini.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Tipe</th>
                                <th>Pesan</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($adminSystemNotifs as $notif): ?>
                                <tr>
                                    <td><?= e($notif['tanggal']) ?></td>
                                    <td><span class="badge <?= e($notif['tipe']) ?>"><?= e(strtoupper($notif['tipe'])) ?></span></td>
                                    <td><?= e($notif['pesan']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

<script>
(() => {
    const csrfToken = '<?= e(csrf_token()) ?>';

    const notifRows = document.querySelectorAll('.notif-row');
    
    notifRows.forEach(row => {
        row.style.cursor = 'pointer';
        row.addEventListener('click', async function() {
            const notifId = this.dataset.notifId;
            
            if (!notifId || this.classList.contains('notif-read')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('id_notifikasi', notifId);
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'mark_read');
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData,
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    // Ubah styling notifikasi menjadi read
                    this.classList.remove('notif-unread');
                    this.classList.add('notif-read');
                    
                    // Update badge di halaman notifikasi
                    const badge = this.querySelector('.unread-badge');
                    if (badge) {
                        badge.remove();
                    }
                    
                    const statusCell = this.querySelector('.notif-status');
                    if (statusCell && !statusCell.querySelector('.status-text')) {
                        statusCell.innerHTML = '<span class="status-text read">✓</span>';
                    }
                    
                    // Update badge di header
                    const headerBadge = document.querySelector('.notif-badge');
                    if (headerBadge) {
                        let currentCount = parseInt(headerBadge.textContent, 10) || 0;
                        currentCount = Math.max(0, currentCount - 1);
                        
                        if (currentCount === 0) {
                            headerBadge.remove();
                        } else {
                            headerBadge.textContent = currentCount;
                        }
                    }
                }
            } catch (error) {
                console.error('Error marking notification as read:', error);
            }
        });
    });
})();
</script>

<?php include LAYOUT_PATH . '/footer.php'; ?>
