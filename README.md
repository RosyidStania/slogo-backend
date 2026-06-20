<p align="center">
  <img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo">
</p>

# Slogo Backend API

Selamat datang di repositori Slogo Backend API. Proyek ini dibangun menggunakan **Laravel 11** dan berfungsi sebagai mesin utama (API Server) untuk manajemen data kegiatan, absensi, dan keanggotaan (Generus). 

Sistem ini didesain khusus untuk melayani permintaan (request) dari Slogo Frontend (React/Vite).

## Fitur Utama
- **Manajemen Autentikasi**: Sistem Login & JWT Token menggunakan Laravel Sanctum.
- **Manajemen Pengguna (User & Admin)**: Pengaturan hak akses untuk admin dan anggota.
- **Manajemen Generus**: Sistem pendataan anggota (CRUD), fitur kenaikan/penurunan kelas massal (Promote/Demote), dan fitur **Import massal dari Excel**.
- **Manajemen Acara & Kehadiran**: Penjadwalan kegiatan (Event) dan absensi peserta dengan pelaporan statistik kehadiran.
- **Dasbor Statistik Lengkap**: Menghasilkan data untuk grafik pie, bar, dan daftar peringkat peserta tereaktif.

---

## 🛠️ Persyaratan Sistem

Pastikan server atau komputer lokal Anda telah memasang:
- **PHP** ^8.2 (Disarankan versi terbaru 8.x)
- **Composer** (untuk manajemen paket PHP)
- **MySQL / MariaDB**
- **Docker & Docker Compose** (Opsional jika ingin menjalankan via Container)

---

## 🚀 Cara Menjalankan di Komputer Lokal (Local Development)

Ikuti langkah-langkah di bawah ini untuk menjalankan server backend di komputer lokal Anda:

### 1. Kloning Repositori
```bash
git clone https://github.com/RosyidStania/slogo-backend.git
cd slogo-backend
```

### 2. Install Dependensi (Vendor)
```bash
composer install
```

### 3. Konfigurasi Environment (`.env`)
Salin file konfigurasi bawaan Laravel:
```bash
cp .env.example .env
```
Lalu, buka file `.env` yang baru dibuat dan sesuaikan kredensial koneksi ke *database* MySQL Anda:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1      # Ganti sesuai host database lokal Anda
DB_PORT=3306
DB_DATABASE=slogo_db   # Ganti dengan nama database Anda
DB_USERNAME=root       # Ganti dengan username database Anda
DB_PASSWORD=rahasia    # Ganti dengan password database Anda
```

*(Opsional)* Anda juga bisa mengatur URL Frontend untuk perizinan CORS:
```env
FRONTEND_URL=http://localhost:5173
```

### 4. Buat Application Key
```bash
php artisan key:generate
```

### 5. Jalankan Migrasi Database
Siapkan struktur tabel di database Anda (Sistem otomatis akan membuatkan skema tabel, termasuk perbaikan struktur password dari audit keamanan terakhir).
```bash
php artisan migrate
```
*(Opsional)* Jika Anda memiliki file *seeder* bawaan, Anda dapat menjalankan `php artisan db:seed`.

### 6. Jalankan Server Lokal
```bash
php artisan serve
```
Backend API Anda kini telah berjalan di `http://127.0.0.1:8000`.

---

## 🐳 Menjalankan dengan Docker (Opsional)

Jika Anda tidak ingin repot mengonfigurasi PHP dan MySQL secara manual, repositori ini telah dilengkapi dengan `docker-compose.yml`.

```bash
docker compose up -d --build
```
*Perintah ini akan secara otomatis:*
- Membuat *container* MySQL database bernama `slogo_db`.
- Membangun *container* PHP Laravel bernama `slogo_backend`.
- Menjalankan server di port `8000`.

*Catatan: Pastikan Anda menjalankan perintah `docker compose exec backend php artisan migrate` setelah container menyala.*

---

## 🌐 API Endpoints
Seluruh jalur komunikasi dengan aplikasi (API) dilindungi oleh aturan standar REST API. Untuk melihat daftar lengkap *endpoint* yang didukung beserta parameter dan detail keamanannya, silakan hubungi tim *developer* atau periksa langsung dari file *router* `routes/api.php`.

---

## 🛡️ Catatan Keamanan
- File `.env` dilarang keras disertakan dalam *commit* Git.
- Sistem ini telah menghapus celah *plain text password*. Seluruh password pengguna dienkripsi dengan metode algoritma _Bcrypt Hash_.
- Proses ekspor/impor massal telah dioptimalkan untuk menangkis masalah kinerja (*N+1 Query Issue*).

## Dukungan & Kontribusi
Proyek ini dibuat dan didedikasikan secara privat. Untuk pertanyaan terkait lisensi atau izin penggunaan (*deployment* server), harap menghubungi Administrator.
