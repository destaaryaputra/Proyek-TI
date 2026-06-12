# EduTrack: Portal Pemantauan Kehadiran dan Prestasi Akademik

Panduan ini menjelaskan cara menjalankan, memakai, dan mengelola aplikasi portal sekolah berbasis PHP + MySQL yang saat ini sudah terhubung dengan fitur login multi-role, laporan export/print, notifikasi, dan reset password otomatis via email.

## Gambaran Sistem

Aplikasi ini membagi akses berdasarkan peran pengguna:

- Admin mengelola user, data akademik, dan monitoring sistem.
- Guru mengisi absensi dan nilai.
- Siswa melihat ringkasan akademik pribadi.
## Fitur Yang Tersedia

- Login multi-role dengan session yang aman.
- Dashboard berbeda untuk admin, guru, dan siswa.
- Manajemen pengguna oleh admin.
- Manajemen kelas, mata pelajaran, dan jadwal oleh admin.
- Input absensi oleh guru.
- Input nilai oleh guru.
- Laporan akademik berisi rekap absensi, detail ketidakhadiran, dan rekap nilai.
- Export laporan ke format Excel (`.xls`).
- Print laporan dengan mode preview di dalam halaman laporan.
- Notifikasi personal untuk pengguna.
- Notifikasi sistem untuk admin.
- Reset password otomatis dengan tautan aman via email.
- Proteksi keamanan Enterprise: CSRF, Role Access, Rate Limit (Brute-Force), dan XSS Protection.

## Struktur Folder

```
app/
   bootstrap.php # Bootstrap aplikasi
   database.php  # Konfigurasi koneksi database dan rate limit database
   helpers.php   # Helper umum, session, auth, CSRF, rate limit
   layouts/      # Header dan footer bersama

docs/
   PETUNJUK_PEMAKAIAN.md
   LAPORAN_PROYEK_SISTEM_INFORMASI_SISWA.md
   PANDUAN_DEMO_PRESENTASI.md

index.php       # Entry point utama di root project

public/
   admin/        # Halaman admin
   api/          # Endpoint JSON
   assets/       # CSS dan aset statis
   guru/         # Halaman guru
   dasbor.php    # Dashboard sesuai role
   laporan.php   # Rekap absensi, nilai, detail, export, print
   notifikasi.php # Halaman notifikasi

database/
   schema.sql    # Struktur database
   seed.sql      # Data demo / seed

storage/
   reset_mail_debug.log # File log/reset debug bila email server belum aktif
```

Catatan:

- Sistem membangun URL berdasarkan lokasi project di web root Apache.
- Jika folder project dipindahkan, link internal tetap menyesuaikan selama project masih berada di dalam web root atau virtual host yang benar.

## Cara Menjalankan Di XAMPP

1. Salin folder project ke `htdocs` XAMPP atau buat virtual host.
2. Jalankan Apache dan MySQL.
3. Buka phpMyAdmin, lalu import database secara berurutan:
   - `database/schema.sql`
   - `database/seed.sql`
4. Buka aplikasi melalui browser:
   - `http://localhost/projek%20ti/`

Catatan import:

- `schema.sql` harus diimport dulu karena berisi struktur tabel.
- `seed.sql` berisi seed demo dan harus diimport setelah struktur selesai.

## Akun Demo

Semua akun demo memakai password awal `123456`.

Contoh akun:

- Admin: `admin1`
- Guru: `guru1` sampai `guru30`
- Siswa: `siswa1` sampai `siswa200`

Data demo sudah disusun sehingga setiap siswa terhubung ke kelas tertentu.

Untuk mengisi ulang data demo presentasi tanpa import ulang database, jalankan:

```text
php scripts/sinkron_data_demo.php
```

Panduan alur demo tersedia di [docs/PANDUAN_DEMO_PRESENTASI.md](PANDUAN_DEMO_PRESENTASI.md).

## Langkah Penggunaan

### 1. Login

1. Buka halaman login.
2. Masukkan username dan password.
3. Jika kredensial benar, sistem akan mengarahkan ke dashboard sesuai role.

Catatan keamanan:

- Percobaan login dibatasi dengan rate limit.
- Jika salah berulang kali, sistem akan menolak sementara untuk mencegah brute force.

### 2. Dashboard Admin

Di dashboard admin, Anda dapat:

1. Mengelola user.
2. Menambah atau menghapus pengguna sesuai role.
3. Mengelola kelas, mata pelajaran, dan jadwal.
4. Melihat notifikasi sistem.

### 3. Dashboard Guru

Di dashboard guru, Anda dapat:

1. Mengisi absensi siswa.
2. Menginput nilai siswa.
3. Melihat data jadwal mengajar.
4. Membuka laporan untuk rekap data yang relevan.

### 4. Dashboard Siswa

Di dashboard siswa, Anda dapat:

1. Melihat ringkasan kehadiran.
2. Melihat ringkasan nilai.
3. Membaca notifikasi akademik.

### 6. Laporan Akademik

Halaman laporan menyediakan:

1. Filter berdasarkan kelas, mata pelajaran, dan periode.
2. Rekap absensi.
3. Detail ketidakhadiran.
4. Rekap nilai.
5. Export ke Excel.
6. Preview print di dalam halaman laporan.

Cara memakai export/print:

1. Buka [laporan.php](../public/laporan.php).
2. Pilih filter yang dibutuhkan.
3. Klik export Excel bila ingin file `.xls`.
4. Klik print bila ingin mode cetak.
5. Saat mode cetak, tampil preview di dalam halaman dan background laporan tetap terlihat.

### 7. Notifikasi

Halaman notifikasi dapat dibuka dari ikon/header di hampir semua halaman.

Fungsinya:

1. Menampilkan notifikasi personal.
2. Menampilkan notifikasi sistem khusus admin.
3. Menandai notifikasi sebagai sudah dibaca.

### 8. Reset Password

Alur reset password saat ini:

1. Pengguna membuka halaman lupa password.
2. Pengguna mengisi username dan email verifikasi.
3. Sistem memvalidasi kecocokan akun lalu mengirimkan tautan reset password ke email pengguna.
4. Pengguna membuka tautan tersebut dan memasukkan password baru tanpa butuh intervensi admin.

Catatan:

- Password baru minimal 8 karakter.

Catatan:

- Jika server email belum aktif, link reset disimpan di `storage/reset_mail_debug.log`.

## Lokasi File Penting

- Bootstrap aplikasi: [app/bootstrap.php](../app/bootstrap.php)
- Konfigurasi helper: [app/helpers.php](../app/helpers.php)
- Konfigurasi database: [app/database.php](../app/database.php)
- Header/footer bersama: [app/layouts/header.php](../app/layouts/header.php) dan [app/layouts/footer.php](../app/layouts/footer.php)
- Login: [public/masuk.php](../public/masuk.php)
- Dashboard: [public/dasbor.php](../public/dasbor.php)
- Laporan: [public/laporan.php](../public/laporan.php)
- Notifikasi: [public/notifikasi.php](../public/notifikasi.php)
- Lupa password: [public/lupa-password.php](../public/lupa-password.php)
- Reset password: [public/reset-password.php](../public/reset-password.php)
- Skema database: [database/schema.sql](../database/schema.sql)
- Seed data: [database/seed.sql](../database/seed.sql)

## Catatan Teknis

- Password seed demo akan otomatis di-upgrade menjadi hash bcrypt saat login berhasil.
- Sistem memakai CSRF token untuk form sensitif.
- Rate limit dipakai pada login dan permintaan reset password.
- Redirect internal sudah dibatasi agar tidak mengarah ke URL luar.
- Notifikasi dan laporan memanfaatkan data relasional dari MySQL.

## Troubleshooting Singkat

- Jika login gagal terus, cek apakah username/password benar dan apakah rate limit sedang aktif.
- Jika database error, pastikan `database/schema.sql` dan `database/seed.sql` sudah diimport.
- Jika link reset tidak aktif, pastikan admin sudah menyetujui permintaan reset.
- Jika email tidak terkirim di XAMPP lokal, cek file log reset di `storage/reset_mail_debug.log`.

## Rangkuman Penggunaan Cepat

1. Login sesuai role.
2. Buka dashboard.
3. Admin mengelola user dan data akademik.
4. Guru mengisi absensi dan nilai.
5. Siswa memantau laporan dan notifikasi.
6. Gunakan export atau print dari halaman laporan bila dibutuhkan.

Jika dibutuhkan, panduan ini bisa diperluas lagi menjadi panduan per role dalam bentuk bab terpisah untuk tugas laporan atau dokumentasi pengguna akhir.
