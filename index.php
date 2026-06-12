<?php

declare(strict_types=1);

// Entry point utama: arahkan user ke dasbor jika sudah login, atau ke halaman login jika belum.
require_once __DIR__ . '/app/bootstrap.php';

if (is_logged_in()) {
	header('Location: ' . url('dasbor.php'));
	exit;
}

header('Location: ' . url('masuk.php'));
exit;
