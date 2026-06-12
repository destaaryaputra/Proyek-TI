-- Skema database utama portal siswa. Jalankan file ini terlebih dahulu saat reset/import.
CREATE DATABASE IF NOT EXISTS edutrack_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE edutrack_db;

DROP TABLE IF EXISTS tabel_notifikasi;
DROP TABLE IF EXISTS tabel_password_reset_tokens;
DROP TABLE IF EXISTS tabel_rate_limits;
DROP TABLE IF EXISTS tabel_nilai;
DROP TABLE IF EXISTS tabel_kehadiran;
DROP TABLE IF EXISTS tabel_jadwal;
DROP TABLE IF EXISTS tabel_siswa;
DROP TABLE IF EXISTS tabel_guru;
DROP TABLE IF EXISTS tabel_admin;
DROP TABLE IF EXISTS tabel_mapel;
DROP TABLE IF EXISTS tabel_kelas;
DROP TABLE IF EXISTS tabel_users;

CREATE TABLE tabel_users (
    id_user INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'guru', 'siswa') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tabel_admin (
    id_admin INT AUTO_INCREMENT PRIMARY KEY,
    id_user INT NOT NULL UNIQUE,
    nama VARCHAR(100) NOT NULL,
    email VARCHAR(100) NULL,
    FOREIGN KEY (id_user) REFERENCES tabel_users(id_user) ON DELETE CASCADE
);

CREATE TABLE tabel_guru (
    id_guru INT AUTO_INCREMENT PRIMARY KEY,
    id_user INT NOT NULL UNIQUE,
    nama VARCHAR(100) NOT NULL UNIQUE,
    jenis_kelamin ENUM('L', 'P') NULL,
    email VARCHAR(100) NULL,
    FOREIGN KEY (id_user) REFERENCES tabel_users(id_user) ON DELETE CASCADE
);

CREATE TABLE tabel_kelas (
    id_kelas INT AUTO_INCREMENT PRIMARY KEY,
    nama_kelas VARCHAR(30) NOT NULL,
    tingkat ENUM('7', '8', '9', '10', '11', '12') NOT NULL
);

CREATE TABLE tabel_mapel (
    id_mapel INT AUTO_INCREMENT PRIMARY KEY,
    nama_mapel VARCHAR(100) NOT NULL
);

CREATE TABLE tabel_siswa (
    id_siswa INT AUTO_INCREMENT PRIMARY KEY,
    id_user INT NOT NULL UNIQUE,
    id_kelas INT NULL,
    nama VARCHAR(100) NOT NULL UNIQUE,
    jenis_kelamin ENUM('L', 'P') NULL,
    nis VARCHAR(30) NOT NULL UNIQUE,
    email VARCHAR(100) NULL,
    FOREIGN KEY (id_user) REFERENCES tabel_users(id_user) ON DELETE CASCADE,
    FOREIGN KEY (id_kelas) REFERENCES tabel_kelas(id_kelas) ON DELETE SET NULL
);

CREATE TABLE tabel_jadwal (
    id_jadwal INT AUTO_INCREMENT PRIMARY KEY,
    id_guru INT NOT NULL,
    id_kelas INT NOT NULL,
    id_mapel INT NOT NULL,
    hari ENUM('Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu') NOT NULL,
    jam VARCHAR(20) NOT NULL,
    FOREIGN KEY (id_guru) REFERENCES tabel_guru(id_guru) ON DELETE CASCADE,
    FOREIGN KEY (id_kelas) REFERENCES tabel_kelas(id_kelas) ON DELETE CASCADE,
    FOREIGN KEY (id_mapel) REFERENCES tabel_mapel(id_mapel) ON DELETE CASCADE
);

CREATE TABLE tabel_kehadiran (
    id_kehadiran INT AUTO_INCREMENT PRIMARY KEY,
    id_siswa INT NOT NULL,
    id_guru INT NOT NULL,
    id_mapel INT NOT NULL,
    tanggal DATE NOT NULL,
    status ENUM('hadir', 'izin', 'sakit', 'alpa') NOT NULL,
    UNIQUE KEY uk_kehadiran_siswa_tanggal_mapel (id_siswa, tanggal, id_mapel),
    FOREIGN KEY (id_siswa) REFERENCES tabel_siswa(id_siswa) ON DELETE CASCADE,
    FOREIGN KEY (id_guru) REFERENCES tabel_guru(id_guru) ON DELETE CASCADE,
    FOREIGN KEY (id_mapel) REFERENCES tabel_mapel(id_mapel) ON DELETE CASCADE
);

CREATE TABLE tabel_nilai (
    id_nilai INT AUTO_INCREMENT PRIMARY KEY,
    id_siswa INT NOT NULL,
    id_mapel INT NOT NULL,
    id_guru INT NOT NULL,
    jenis_penilaian ENUM('tugas', 'kuis', 'uts', 'uas') NOT NULL,
    skor DECIMAL(5,2) NOT NULL,
    periode VARCHAR(30) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_nilai_unique (id_siswa, id_mapel, jenis_penilaian, periode),
    FOREIGN KEY (id_siswa) REFERENCES tabel_siswa(id_siswa) ON DELETE CASCADE,
    FOREIGN KEY (id_mapel) REFERENCES tabel_mapel(id_mapel) ON DELETE CASCADE,
    FOREIGN KEY (id_guru) REFERENCES tabel_guru(id_guru) ON DELETE CASCADE
);

CREATE TABLE tabel_notifikasi (
    id_notifikasi INT AUTO_INCREMENT PRIMARY KEY,
    id_user INT NOT NULL,
    pesan VARCHAR(255) NOT NULL,
    tanggal DATE NOT NULL,
    dibaca BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES tabel_users(id_user) ON DELETE CASCADE
);

CREATE TABLE tabel_password_reset_tokens (
    id_reset BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_user INT NOT NULL,
    email VARCHAR(100) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_user (id_user),
    INDEX idx_reset_expires (expires_at),
    FOREIGN KEY (id_user) REFERENCES tabel_users(id_user) ON DELETE CASCADE
);

CREATE TABLE tabel_rate_limits (
    key_hash CHAR(64) PRIMARY KEY,
    bucket_label VARCHAR(64) NOT NULL,
    attempts_json LONGTEXT NOT NULL,
    blocked_until DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rate_limit_blocked_until (blocked_until)
);
