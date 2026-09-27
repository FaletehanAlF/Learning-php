# Backend — Kas Management API

API JSON (gaya Laravel: `routes/api.php`, `artisan`, `.env`, `config/cors.php`)
tanpa dependency composer. Auth memakai **Bearer token** (model Sanctum:
`personal_access_tokens`, kedaluwarsa 30 hari).

## Struktur

```
backend/
  artisan              CLI: php artisan migrate | php artisan serve [--port=8000]
  .env / .env.example  konfigurasi (DB, CORS, threshold)
  config/cors.php      CORS (baca CORS_ALLOWED_ORIGINS)
  config/sanctum.php   parameter token-auth
  routes/api.php       semua endpoint /api/*
  app/Core/            Env, Database (MySQL utama + SQLite fallback), Http, Router
  app/Controllers/     Auth, Transaction, Dashboard, Category, Report, Log, Backup, User
  public/index.php     front controller (docroot)
  storage/             data/kas.db, uploads/, backups/
```

## Jalankan

```bash
cd backend
cp .env.example .env   # Windows: copy .env.example .env
php artisan migrate    # buat tabel + seed 3 user
php artisan serve      # http://127.0.0.1:8000
```

Akun: `admin/admin123`, `kasir/kasir123`, `supervisor/spv123`.

## Endpoint ringkas

| Method | URL | Auth | Keterangan |
|---|---|---|---|
| POST | /api/auth/login | – | → `{token, user}` |
| POST | /api/auth/logout | token | hapus token |
| GET | /api/auth/me | token | user saat ini (401 bila tanpa token) |
| POST | /api/auth/forgot | – | minta token reset |
| POST | /api/auth/reset | – | reset password |
| GET | /api/dashboard | token | saldo, chart 7 hari, notifikasi |
| GET/POST | /api/transactions | token | list (filter type/category_id/date/q/page) |
| GET/PUT/DELETE | /api/transactions/{id} | token | PUT JSON / POST multipart (`_method=PUT`) |
| GET/POST/PUT/DELETE | /api/categories{,/{id}} | token | hapus: admin saja |
| GET | /api/reports/daily?date= | token | `&export=csv` untuk unduh |
| GET | /api/reports/monthly?month=YYYY-MM | token | `&export=csv` |
| GET | /api/reports/yearly?year=YYYY | token | `&export=csv` |
| GET | /api/logs | admin,supervisor | 200 audit terakhir |
| POST | /api/backup | admin | backup mingguan |
| GET/POST | /api/users | admin | kelola user |

Matriks peran = monolit `sistem-kas/`: kasir input/edit milik sendiri,
supervisor hapus + audit, admin penuh.
