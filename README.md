# EduTrack: Portal Pemantauan Kehadiran & Akademik

EduTrack adalah sistem informasi manajemen sekolah berbasis web yang mengintegrasikan pencatatan kehadiran, pengelolaan nilai, dan sistem notifikasi akademik. Proyek ini dikembangkan untuk meningkatkan efisiensi administrasi sekolah dan menyediakan transparansi data bagi siswa, guru, dan administrator.

![GitHub repo size](https://img.shields.io/github/repo-size/destaaryaputra/Proyek-TI)
![GitHub language count](https://img.shields.io/github/languages/count/destaaryaputra/Proyek-TI)
![PHP Version](https://img.shields.io/badge/PHP-8.x-777bb4?logo=php)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?logo=mysql)

---

## 🚀 Fitur Utama

### 🔐 Multi-Role Access Control
*   **Admin:** Manajemen pengguna, kontrol data akademik master (kelas, mapel, jadwal), monitoring notifikasi sistem, dan pemeliharaan database.
*   **Guru:** Input kehadiran siswa, input nilai akademik, dan melihat rekapitulasi kelas yang diampu.
*   **Siswa:** Melihat dashboard prestasi pribadi, riwayat kehadiran, dan menerima notifikasi akademik.

### 📊 Manajemen Akademik
*   **Absensi Digital:** Pencatatan kehadiran real-time per mata pelajaran.
*   **Pengelolaan Nilai:** Input nilai tugas, kuis, UTS, dan UAS dengan perhitungan otomatis.
*   **Laporan & Ekspor:** Fitur cetak laporan langsung dari browser dan ekspor data ke format Excel (`.xls`).

### 🔔 Sistem Notifikasi Otomatis
*   Notifikasi **Alpa** otomatis jika siswa tidak hadir lebih dari 3 kali.
*   Peringatan **Nilai di bawah KKM** (Kriteria Ketuntasan Minimal).
*   **System Health Check** untuk admin (deteksi integritas data).

### 🛡️ Keamanan & Pemulihan
*   **Rate Limiting:** Proteksi terhadap serangan brute-force pada halaman login dan reset password.
*   **CSRF Protection:** Keamanan transaksi data di setiap formulir.
*   **Password Recovery:** Fitur lupa password dengan tautan reset otomatis yang aman.

---

## 🛠️ Tech Stack

*   **Backend:** PHP Native (v8.x recommended)
*   **Frontend:** HTML5, Modern CSS (Vanilla), Vanilla JavaScript
*   **Database:** MySQL / MariaDB
*   **Server:** Apache (XAMPP Environment)

---

## 📂 Struktur Folder

```text
├── app/            # Logika inti, helper, dan koneksi database
├── database/       # Skema SQL, data seed, dan backup database
├── docs/           # Dokumentasi proyek (Laporan & Panduan)
├── public/         # Entry point aplikasi (Aset CSS, JS, dan Halaman)
├── scripts/        # Script utilitas (Sinkronisasi data demo)
└── storage/        # Penyimpanan log dan file sementara
```

---

## ⚙️ Cara Instalasi (Lokal)

1.  **Clone Repositori:**
    ```bash
    git clone https://github.com/destaaryaputra/Proyek-TI.git
    ```
2.  **Konfigurasi Database:**
    *   Buka XAMPP Control Panel dan aktifkan **Apache** serta **MySQL**.
    *   Buka [phpMyAdmin](http://localhost/phpmyadmin).
    *   Buat database baru bernama `edutrack_db`.
    *   Import file `database/schema.sql` kemudian `database/seed.sql`.
3.  **Akses Aplikasi:**
    *   Pindahkan folder proyek ke `C:/xampp/htdocs/`.
    *   Akses via browser: `http://localhost/proyek-ti/public/index.php`.

---

## 👨‍💻 Pengembang

**Desta Arya Putra**
*   NPM: 2413025058
*   Prodi: Pendidikan Teknologi Informasi, Universitas Lampung

---

## 📝 Lisensi
Proyek ini dikembangkan untuk tujuan pendidikan. Silakan digunakan dan dikembangkan lebih lanjut.
