# Kala Seduh — Sistem Informasi Coffee Shop Slow Bar

Aplikasi web **Sistem Informasi Coffee Shop Slow Bar** berbasis **PHP 8.x Native** (tanpa framework), **HTML5**, **Tailwind CSS (CDN)**, **Vanilla JavaScript**, dan **PostgreSQL (Supabase)** yang dirancang siap untuk di-deploy secara serverless ke **Vercel** menggunakan runtime `vercel-php@0.7.3`.

---

## Fitur Utama

1. **Halaman Pelanggan (`/`)**
   - **Katalog Biji Kopi**: Menampilkan detail origin, metode proses (`Natural`, `Washed`, `Honey`, `Anaerobic`), tasting notes, harga, dan sisa stok dalam gram beserta filter kategori dan pencarian.
   - **Form Pemesanan Interaktif**: Memvalidasi kecukupan `stok_gram` terhadap `rasio_kopi_gram` metode seduh (`V60`, `Kalita Wave`, `AeroPress`, `Syphon`), menyimpan pesanan, dan **otomatis mengurangi `stok_gram`** secara atomik via database transaction (`beginTransaction()`, `SELECT ... FOR UPDATE`, dan `commit()`).
   - **Antrean Seduh Live**: Memantau status pesanan secara real-time (`Menunggu` → `Sedang Diseduh` → `Selesai`) dengan auto-polling setiap 5 detik.

2. **Portal Autentikasi Stateless (`/login` & `/logout`)**
   - Menggunakan **Signed Cookie HMAC-SHA256** (`HttpOnly`, `SameSite=Lax`) agar sesi login Barista/Admin tetap persisten di lingkungan serverless Vercel tanpa bergantung pada file session lokal.
   - Verifikasi password menggunakan `password_verify()` (bcrypt `$2y$10$`).
   - Akun demo bawaan:
     - **Admin**: `admin` / `admin123`
     - **Barista**: `barista` / `barista123`

3. **Dashboard Admin & Barista (`/admin`)**
   - **Ringkasan Statistik**: Total Pendapatan, Jumlah Pesanan Hari Ini, Total Stok Gramasi, dan **Peringatan Otomatis** apabila terdapat biji kopi dengan stok `< 100 gram`.
   - **Manajemen Antrean Seduh**: Papan Kanban 3 kolom untuk memindahkan status pesanan (`Menunggu` → `Sedang Diseduh` → `Selesai`) serta pencatatan pesanan *walk-in*.
   - **CRUD Master Data**: Tambah, ubah, hapus, dan *restock* gramasi **Biji Kopi** serta pengaturan **Metode Seduh**.
   - **Laporan Transaksi & Cetak**: Filter berdasarkan rentang tanggal (`dari_tanggal` – `sampai_tanggal`) dan status, perhitungan omzet otomatis, serta cetak laporan rapi via `window.print()` dan stylesheet `@media print`.

---

## Struktur Proyek

```text
Kala Seduh/
├── api/
│   ├── config/
│   │   ├── auth.php          # Autentikasi stateless signed cookie & CSRF HMAC
│   │   ├── database.php      # Koneksi PDO PostgreSQL Supabase (sslmode=require)
│   │   └── helpers.php       # Loader .env lokal, formatter Rupiah/WIB, XSS escape
│   ├── partials/
│   │   └── head.php          # Konfigurasi Tailwind CDN, Google Fonts & palet Slow Bar
│   ├── admin.php             # Dashboard Admin & Barista (Antrean, CRUD, Laporan)
│   ├── antrean.php           # Endpoint JSON live polling antrean & stok
│   ├── index.php             # Halaman Pelanggan (Katalog, Order Drawer, Live Queue)
│   ├── login.php             # Halaman Login Admin/Barista
│   ├── logout.php            # Endpoint Logout
│   └── proses_order.php      # Endpoint transaksi order + auto-deduct stok gramasi
├── public/
│   ├── css/
│   │   └── print.css         # Stylesheet @media print untuk cetak laporan transaksi
│   └── favicon.svg           # Ikon aplikasi Kala Seduh
├── .env.example              # Contoh variabel lingkungan koneksi database
├── .gitignore
├── .vercelignore
├── database.sql              # Skema tabel PostgreSQL & data dummy awal
├── router.php                # Router pengembangan lokal (php -S)
├── vercel.json               # Konfigurasi runtime vercel-php@0.7.3 & URL rewrites
└── README.md
```

---

## Langkah 1: Setup Database & Import `database.sql` ke Supabase

1. Masuk ke dashboard [Supabase](https://supabase.com) dan buat proyek baru (misalnya `kala-seduh`).
2. Di sidebar kiri Supabase, buka **SQL Editor** → klik **New query**.
3. Salin seluruh isi file [`database.sql`](database.sql) ke editor, lalu klik **Run** (atau tekan `Ctrl+Enter`).
4. Pastikan keempat tabel (`users`, `biji_kopi`, `metode_seduh`, `pesanan`) beserta data dummy berhasil dibuat.
5. Untuk mendapatkan kredensial koneksi PostgreSQL:
   - Klik tombol **Connect** di bagian atas dashboard Supabase (atau buka **Project Settings → Database**).
   - Pilih tab **Connection String / Transaction pooler** (Port **`6543`**) — mode ini sangat disarankan untuk fungsi serverless seperti Vercel.
   - Catat nilai berikut:
     - `DB_HOST`: contoh `aws-0-ap-southeast-1.pooler.supabase.com`
     - `DB_PORT`: `6543`
     - `DB_NAME`: `postgres`
     - `DB_USER`: contoh `postgres.abcdefghijklmno` *(perhatikan format user pooler Supabase yang menyertakan project ref)*
     - `DB_PASS`: password database yang Anda buat saat membuat proyek Supabase.

> **Catatan teknis:** Helper `api/config/database.php` sudah mengaktifkan `PDO::ATTR_EMULATE_PREPARES => true` dan `sslmode=require` agar koneksi PDO PostgreSQL berjalan stabil melalui **Transaction Pooler (port 6543)** Supabase.

---

## Langkah 2: Mengatur Environment Variables & Deploy ke Vercel

### Opsi A: Deploy via Dashboard Web Vercel (Direkomendasikan)

1. Push seluruh kode proyek ini ke repository GitHub/GitLab/Bitbucket Anda.
2. Buka [Vercel Dashboard](https://vercel.com/new), pilih **Import Git Repository**, lalu pilih repository **Kala Seduh**.
3. Pada bagian **Framework Preset**, biarkan **Other**.
4. Buka bagian **Environment Variables** dan tambahkan variabel berikut (sesuai `.env.example`):
   | Key | Contoh Nilai |
   | --- | --- |
   | `DB_HOST` | `aws-0-ap-southeast-1.pooler.supabase.com` |
   | `DB_PORT` | `6543` |
   | `DB_NAME` | `postgres` |
   | `DB_USER` | `postgres.abcdefghijklmno` |
   | `DB_PASS` | `password_database_supabase_anda` |
   | `APP_SECRET` | `string_acak_rahasia_minimal_32_karakter` |
5. Klik **Deploy** dan tunggu build selesai.

### Opsi B: Deploy via Vercel CLI

1. Pastikan [Node.js](https://nodejs.org) dan Vercel CLI sudah terpasang:
   ```bash
   npm install -g vercel
   vercel login
   ```
2. Jalankan deployment dari folder root proyek:
   ```bash
   vercel
   ```
3. Tambahkan Environment Variables di Vercel:
   ```bash
   vercel env add DB_HOST
   vercel env add DB_PORT
   vercel env add DB_NAME
   vercel env add DB_USER
   vercel env add DB_PASS
   vercel env add APP_SECRET
   ```
4. Deploy ke production:
   ```bash
   vercel --prod
   ```

---

## Pengujian Lokal (Opsional)

Jika Anda memiliki **PHP 8.x** dengan ekstensi `pdo_pgsql` aktif di komputer lokal:

1. Salin `.env.example` menjadi `.env` dan isi kredensial Supabase Anda:
   ```bash
   cp .env.example .env
   ```
2. Jalankan built-in web server PHP dengan `router.php`:
   ```bash
   php -S localhost:8000 router.php
   ```
3. Buka browser dan akses:
   - Halaman Pelanggan: `http://localhost:8000/`
   - Login Barista/Admin: `http://localhost:8000/login`
   - Dashboard Admin: `http://localhost:8000/admin`
