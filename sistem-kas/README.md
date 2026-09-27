# Sistem Informasi Pengelolaan Uang Kas

Aplikasi web pencatatan, pelacakan, dan pelaporan pemasukan & pengeluaran kas real-time
dengan kontrol akses berbasis peran (admin, kasir, supervisor).

Stack: **PHP 8 + PDO (MySQL 8.0+ utama, SQLite fallback) + Bootstrap 5 + Chart.js**.
Tanpa dependency composer — load cepat, mobile-friendly (responsive).

## Fitur (sesuai spec)

1. **Auth & Authorization** — login username/email, 3 role, password reset (token 1 jam).
2. **Dashboard** — saldo total & hari ini, grafik 7 hari, total transaksi hari ini, notifikasi pengeluaran besar (>Rp1.000.000).
3. **Transaksi** — form pemasukan/pengeluaran per kategori, edit/delete + audit log, nomor otomatis `TRX-YYYYMMDD-XXXX`, upload bukti (JPG/PNG/PDF 2MB).
4. **Laporan** — harian (detail), bulanan (summary per kategori), tahunan (trend), export CSV (dibuka di Excel), print-friendly.
5. **Kategori** — CRUD per tipe + kategorisasi otomatis dari kata kunci deskripsi (mis. "gaji" → Gaji).
6. **Security & Audit** — activity log (user, aksi, waktu, changes, IP), backup mingguan (1 klik), validasi (tolak duplikat referensi, tolak nominal ≤ 0), prepared statement, escaping `e()`, CSRF token.

## Struktur

```
sistem-kas/
  index.php      front controller + router + views
  config.php     konfigurasi DB & aplikasi
  database.php   PDO MySQL (utama) + SQLite fallback + migrasi
  helpers.php    auth, CSRF, audit, upload, backup, dsb.
  schema.sql     skema MySQL 8.0+ + seed kategori
  install.php    migrasi + seed 3 user
  start.bat      menjalankan server dengan ekstensi PDO aktif
  uploads/       file bukti (dibuat otomatis)
  backups/       hasil backup (dibuat otomatis)
  data/          kas.db SQLite fallback (dibuat otomatis)
```

## Cara menjalankan (Windows)

**Opsi A — 1 klik (disarankan):**
```
sistem-kas\start.bat
```
lalu buka http://localhost:8000/index.php

**Opsi B — manual (MySQL):**
1. Buat database MySQL 8.0+ dan user, lalu set env `DB_HOST DB_PORT DB_NAME DB_USER DB_PASS`.
2. `php install.php` (atau buka `install.php` di browser) untuk migrasi + seed.
3. Serve: `php -S localhost:8000` dari folder `sistem-kas`.

**Opsi C — manual (tanpa MySQL, mode belajar):**
Butuh ekstensi PDO SQLite yang sudah tersedia di `C:\php-8.5.9\ext`:
```
php -d extension_dir=C:\php-8.5.9\ext -d extension=pdo_sqlite -d extension=sqlite3 -d extension=mbstring install.php
php -d extension_dir=C:\php-8.5.9\ext -d extension=pdo_sqlite -d extension=sqlite3 -d extension=mbstring -S localhost:8000
```

## Akun default (dibuat install.php)

| Username | Email | Password | Role |
|---|---|---|---|
| admin | admin@kas.local | admin123 | admin (penuh) |
| kasir | kasir@kas.local | kasir123 | kasir (input + edit milik sendiri) |
| supervisor | spv@kas.local | spv123 | supervisor (lihat + hapus + audit) |

> Ganti password setelah install via menu Users (admin).

## Matriks akses

| Fitur | admin | kasir | supervisor |
|---|---|---|---|
| Dashboard, Transaksi (lihat), Laporan + Export | ✓ | ✓ | ✓ |
| Input/Edit transaksi | ✓ | ✓ (milik sendiri) | – |
| Hapus transaksi | ✓ | – | ✓ |
| Kelola kategori | ✓ | – | ✓ (tambah/edit) |
| Hapus kategori, Users, Backup | ✓ | – | – |
| Audit log | ✓ | – | ✓ |

## Backup mingguan

Menu **Backup** (admin) → 1 klik menghasilkan `.sql` (MySQL) atau `.sql` + copy `.db`
(SQLite) di `backups/`. Jadwalkan mingguan:
- Linux cron: `0 2 * * 0 php /path/sistem-kas/cron_backup.php`
- Windows Task Scheduler: jalankan `start.bat`-style dengan `backup` via curl, atau copy folder `backups/`.

## Keamanan

- Semua query memakai **prepared statement** (anti SQL injection).
- Semua output memakai **`e()`** (anti XSS).
- Semua form POST memakai **token CSRF**.
- Password di-hash (`password_hash`), session regenerate saat login.
- Validasi: nominal > 0, tanggal valid, kategori cocok tipe, referensi unik, upload dibatasi tipe/ukuran.
- GDPR: tidak ada data pribadi selain username/email; hapus via menu Users (admin) + audit log tercatat.

## Deploy Apache/Nginx

- Apache: arahkan DocumentRoot ke `sistem-kas/`, `.htaccess` sudah disediakan (proteksi `data/`, `backups/`).
- Nginx: `root .../sistem-kas; index index.php;` + `location ~ \.php$ { fastcgi_pass ... }`, blok akses `/data/` dan `/backups/`.
- Wajib: extension `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`; `uploads/` writable.
