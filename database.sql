-- ============================================================================
-- Kala Seduh Slow Bar — Skema Database PostgreSQL (Supabase)
-- Jalankan seluruh skrip ini di SQL Editor Supabase.
-- ============================================================================

BEGIN;

DROP TABLE IF EXISTS pesanan CASCADE;
DROP TABLE IF EXISTS metode_seduh CASCADE;
DROP TABLE IF EXISTS biji_kopi CASCADE;
DROP TABLE IF EXISTS users CASCADE;

-- 1. Tabel Pengguna (Admin & Barista)
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nama_lengkap VARCHAR(100) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role IN ('admin', 'barista')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 2. Tabel Master Biji Kopi
CREATE TABLE biji_kopi (
    id SERIAL PRIMARY KEY,
    nama_kopi VARCHAR(120) NOT NULL,
    origin VARCHAR(120) NOT NULL,
    proses VARCHAR(40) NOT NULL CHECK (proses IN ('Natural', 'Washed', 'Honey', 'Anaerobic')),
    tasting_notes TEXT NOT NULL,
    stok_gram INTEGER NOT NULL DEFAULT 0 CHECK (stok_gram >= 0),
    harga INTEGER NOT NULL CHECK (harga >= 0),
    harga_per_kg INTEGER NOT NULL DEFAULT 250000 CHECK (harga_per_kg >= 0),
    gambar_url TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 3. Tabel Master Metode Seduh (stok_porsi NULL = tanpa batas kuota batch, INTEGER = stok terbatas seperti Cold Brew)
CREATE TABLE metode_seduh (
    id SERIAL PRIMARY KEY,
    nama_metode VARCHAR(80) NOT NULL UNIQUE,
    rasio_kopi_gram INTEGER NOT NULL DEFAULT 15 CHECK (rasio_kopi_gram > 0),
    estimasi_menit INTEGER NOT NULL DEFAULT 4 CHECK (estimasi_menit > 0),
    biaya_tambahan INTEGER NOT NULL DEFAULT 0 CHECK (biaya_tambahan >= 0),
    stok_porsi INTEGER DEFAULT NULL CHECK (stok_porsi IS NULL OR stok_porsi >= 0)
);

-- 4. Tabel Pesanan Manual Brew
CREATE TABLE pesanan (
    id SERIAL PRIMARY KEY,
    kode_pesanan VARCHAR(30) NOT NULL UNIQUE,
    nama_pelanggan VARCHAR(100) NOT NULL,
    nomor_meja VARCHAR(30) NOT NULL,
    biji_kopi_id INTEGER NOT NULL REFERENCES biji_kopi(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    metode_seduh_id INTEGER NOT NULL REFERENCES metode_seduh(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    tipe_sajian VARCHAR(10) NOT NULL CHECK (tipe_sajian IN ('Hot', 'Iced')),
    catatan TEXT DEFAULT '',
    total_harga INTEGER NOT NULL CHECK (total_harga >= 0),
    status VARCHAR(25) NOT NULL DEFAULT 'Menunggu' CHECK (status IN ('Menunggu', 'Sedang Diseduh', 'Selesai')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_pesanan_status_created ON pesanan (status, created_at DESC);
CREATE INDEX idx_pesanan_created_at ON pesanan (created_at DESC);

-- ============================================================================
-- Data Dummy Awal
-- ============================================================================

-- Akun Default:
--   admin   / admin123   (role: admin)
--   barista / barista123 (role: barista)
INSERT INTO users (username, password, nama_lengkap, role) VALUES
('admin', '$2y$10$i0Wk8C06xur2iTjXja6oxeNXV8nEfCIc9KrARDfis6hhyvz444ZAe', 'Raka Mahendra', 'admin'),
('barista', '$2y$10$RGEmsseqrQO.iCdD36GjquJrxZ33J9LnhOTM0lNu3qRigF1qGilzG', 'Arya Pratama', 'barista');

-- Katalog Biji Kopi (6 Single Origin Nusantara, harga = tarif per cup seduhan, harga_per_kg = tarif roasted beans per 1 kg)
INSERT INTO biji_kopi (nama_kopi, origin, proses, tasting_notes, stok_gram, harga, harga_per_kg, gambar_url) VALUES
(
    'Aceh Gayo Pantan Musara',
    'Aceh Tengah • 1.650 mdpl',
    'Anaerobic',
    'Blackcurrant, Peach Tea, Wild Honey',
    850,
    22000,
    320000,
    'https://images.unsplash.com/photo-1590093217544-da597729d81a?auto=format&fit=crop&w=900&q=80'
),
(
    'Flores Bajawa Ngura',
    'Bajawa, NTT • 1.400 mdpl',
    'Washed',
    'Milk Chocolate, Caramel Apple, Nutty',
    1200,
    18000,
    240000,
    'https://images.unsplash.com/photo-1561766859-f29422062842?auto=format&fit=crop&w=900&q=80'
),
(
    'Bali Kintamani Ulian',
    'Bangli, Bali • 1.450 mdpl',
    'Washed',
    'Jasmine Blossom, Mandarin Orange, Lemon Zest',
    420,
    21000,
    290000,
    'https://images.unsplash.com/photo-1703113391576-3beb676b7638?auto=format&fit=crop&w=900&q=80'
),
(
    'Java Preanger Gunung Halu',
    'Bandung Barat • 1.500 mdpl',
    'Honey',
    'Ripe Banana, Jackfruit, Brown Sugar',
    960,
    20000,
    280000,
    'https://images.unsplash.com/photo-1692299108834-038511803008?auto=format&fit=crop&w=900&q=80'
),
(
    'Toraja Sapan Minanga',
    'Toraja Utara • 1.700 mdpl',
    'Natural',
    'Dark Cocoa, Nutmeg Spice, Cedar Wood',
    1500,
    19000,
    260000,
    'https://images.unsplash.com/photo-1567613747125-f7ac12360d77?auto=format&fit=crop&w=900&q=80'
),
(
    'Papua Wamena Kurima',
    'Lembah Baliem, Papua • 1.800 mdpl',
    'Washed',
    'Blackberry, Red Plum, Cane Sugar',
    75,
    24000,
    340000,
    'https://images.unsplash.com/photo-1712251769294-16163363ebef?auto=format&fit=crop&w=900&q=80'
);

-- Master Metode Seduh
INSERT INTO metode_seduh (nama_metode, rasio_kopi_gram, estimasi_menit, biaya_tambahan, stok_porsi) VALUES
('V60', 15, 4, 0, NULL),
('Kalita Wave', 16, 5, 1000, NULL),
('AeroPress', 18, 3, 2000, NULL),
('Syphon', 20, 7, 5000, NULL),
('French Press', 18, 4, 1000, NULL),
('Cold Brew', 20, 2, 3000, 8),
('Americano', 18, 3, 0, NULL),
('Latte', 18, 4, 4000, NULL);

-- Data Awal Pesanan (Hari ini & Kemarin)
INSERT INTO pesanan (kode_pesanan, nama_pelanggan, nomor_meja, biji_kopi_id, metode_seduh_id, tipe_sajian, catatan, total_harga, status, created_at) VALUES
('KS-040', 'Maya Larasati', 'Meja 05', 5, 4, 'Hot', 'Ingin body yang tebal dan hangat.', 24000, 'Selesai', NOW() - INTERVAL '1 day'),
('KS-041', 'Kevin Pratama', 'Bar Seat 04', 6, 1, 'Iced', 'Japanese Iced, es batu secukupnya.', 24000, 'Selesai', NOW() - INTERVAL '35 minutes'),
('KS-042', 'Nabila Putri', 'Bar Seat 02', 4, 3, 'Iced', 'Ekstraksi manis buah tropis.', 22000, 'Sedang Diseduh', NOW() - INTERVAL '14 minutes'),
('KS-043', 'Rian Santoso', 'Meja 03', 2, 1, 'Hot', 'Sajikan dengan suhu agak hangat 65°C.', 18000, 'Sedang Diseduh', NOW() - INTERVAL '9 minutes'),
('KS-044', 'Sarah Kusuma', 'Meja 07', 3, 2, 'Hot', 'Minta kartu tasting notes.', 22000, 'Menunggu', NOW() - INTERVAL '5 minutes'),
('KS-045', 'Dimas Wicaksono', 'Bar Seat 01', 1, 1, 'Hot', 'Fokus ke aroma floral dan sweet finish.', 22000, 'Menunggu', NOW() - INTERVAL '2 minutes');

COMMIT;
