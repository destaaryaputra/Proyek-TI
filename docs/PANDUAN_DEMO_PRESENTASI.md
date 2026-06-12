# Panduan Demo Presentasi EduTrack

Dokumen ini dipakai untuk menjalankan demo aplikasi secara rapi saat presentasi.

## Persiapan Cepat

1. Jalankan Apache dan MySQL dari XAMPP.
2. Buka aplikasi:
   - `http://localhost/projek%20ti/`
3. Jika data demo terlihat kosong, jalankan:
   - `php scripts/sinkron_data_demo.php`

## Akun Demo

Semua akun demo memakai password:

```text
123456
```

Gunakan akun berikut saat presentasi:

| Role | Username | Fokus Demo |
| --- | --- | --- |
| Admin | `admin1` | Dashboard analitik, data pengguna, data akademik, laporan |
| Guru | `guru1` | Jadwal mengajar, input absensi, input nilai, laporan guru |
| Siswa | `siswa1` | Ringkasan akademik, jadwal kelas, notifikasi, laporan pribadi |

## Alur Demo Rekomendasi

### 1. Admin

1. Login sebagai `admin1`.
2. Buka Dashboard.
3. Tunjukkan:
   - KPI pengguna, guru, siswa, kelas, mapel, jadwal.
   - Grafik distribusi role.
   - Tren absensi 7 hari.
   - Status absensi.
   - Siswa per kelas.
   - Rata-rata nilai.
4. Buka **Manajemen Pengguna**.
5. Tunjukkan daftar user, guru, dan siswa.
6. Buka **Data Akademik**.
7. Tunjukkan kelas, mapel, dan jadwal.
8. Buka **Laporan**.
9. Tunjukkan filter, export Excel, dan preview cetak.

### 2. Guru

1. Logout, lalu login sebagai `guru1`.
2. Buka Dashboard.
3. Tunjukkan:
   - Statistik mengajar.
   - Jadwal mengajar.
   - Absensi hari ini.
   - Nilai terbaru yang diinput.
4. Buka **Input Absensi**.
5. Pilih kelas, mapel, tanggal, lalu tunjukkan tabel status siswa.
6. Buka **Input Nilai**.
7. Pilih kelas, mapel, jenis penilaian, periode, lalu tunjukkan input skor.
8. Buka **Laporan** untuk menunjukkan data yang dibatasi sesuai guru.

### 3. Siswa

1. Logout, lalu login sebagai `siswa1`.
2. Buka Dashboard.
3. Tunjukkan:
   - Ringkasan kehadiran.
   - Rata-rata nilai.
   - Jadwal kelas.
   - Notifikasi terbaru.
   - Nilai terbaru.
4. Buka **Laporan**.
5. Tunjukkan rekap kehadiran, detail ketidakhadiran, rekap nilai, dan notifikasi pribadi.
6. Buka **Profil Saya** untuk menunjukkan data akun siswa.

## Poin Penjelasan Saat Presentasi

- Sistem memakai multi-role: admin, guru, siswa.
- Admin mengelola struktur data sekolah.
- Guru hanya mengelola data sesuai kelas dan mata pelajaran yang diampu.
- Siswa hanya melihat data miliknya sendiri.
- Laporan mendukung filter, export Excel, dan cetak PDF melalui browser.
- Dashboard menggunakan data MySQL nyata, bukan angka statis.

## Checklist Sebelum Presentasi

- Login admin, guru, siswa berhasil.
- Dashboard admin memiliki grafik yang terisi.
- Dashboard guru menampilkan jadwal dan data input.
- Dashboard siswa menampilkan nilai, jadwal, dan notifikasi.
- Halaman laporan bisa dibuka untuk semua role.
- Tidak ada error PHP di halaman.
- Browser sudah di-hard refresh (`Ctrl + F5`) agar CSS terbaru tampil.
