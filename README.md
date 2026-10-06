# MOP Board

Dashboard multi-project untuk membuat & mengelola **MOP (Method of Procedure)** —
setiap project punya kolom sendiri (mengikuti format seperti file jadwal
migrasi VM: No, VM Name, Hostname, IP, Site, Cluster, Aplikasi, PIC, Jadwal
Replikasi, Malam Cutover, Jadwal Cutover, Tim, Status Replicate, Status
CutOver, Tanggal, Keterangan), plus dashboard interaktif (status, grouping,
timeline cutover) per project.

Stack: PHP 8.1 (tanpa framework) + SQLite (via PDO) di backend, HTML/CSS/JS
vanilla + Chart.js (di-vendor lokal, bukan CDN) di frontend. Tidak butuh build
step / Node process — tinggal diarahkan ke web server PHP mana pun. Seluruh
akses (halaman & API) ada di belakang login single-admin — lihat bagian
**Login & Keamanan** di bawah.

## Struktur

```
public/          <- DocumentRoot (arahkan vhost ke sini)
  index.php      <- SPA (hanya render kalau sudah login, redirect ke login.php)
  login.php      <- form login + rate limit
  logout.php     <- hapus session
  api.php        <- REST API (routing via ?path=...), wajib login
  css/app.css    <- dipakai index.php (dashboard)
  css/login.css  <- khusus login.php, mandiri, SRI-pinned (lihat login.php)
  js/vendor/     <- chart.umd.min.js (SRI-pinned, lihat index.php)
src/             <- kelas PHP (di luar webroot, sudah ada .htaccess deny)
  Auth.php       <- session, CSRF, rate-limit login
data/            <- mopboard.sqlite + sessions/ (dibuat otomatis, mode 0700)
```

## Menjalankan (development)

```bash
cd /var/www/mopboard
php -S 127.0.0.1:8080 -t public
```

Buka `http://127.0.0.1:8080`. Database SQLite + 1 project contoh akan dibuat
otomatis di `data/mopboard.sqlite` saat request pertama.

## Deploy dengan Apache

VirtualHost harus menunjuk ke folder `public/` (bukan root project):

```apache
<VirtualHost *:80>
    ServerName mopboard.example.com
    DocumentRoot /var/www/mopboard/public

    <Directory /var/www/mopboard/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Pastikan folder `data/` bisa ditulis oleh user Apache:

```bash
chown -R apache:apache /var/www/mopboard
chmod -R 775 /var/www/mopboard/data
```

## Deploy dengan Nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name mopboard.example.com;
    root /var/www/mopboard/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Header keamanan (CSP, HSTS, dst — lihat bagian berikutnya) perlu ditambahkan
manual di block `server` Nginx via `add_header`, karena README ini menyertakan
contoh konfigurasinya untuk Apache (`mod_headers`), bukan Nginx.

## Fitur

- **Login admin**: seluruh aplikasi & API di belakang login (lihat **Login &
  Keamanan**), plus rate limit brute-force.
- **Multi-project**: setiap project punya set kolom & data sendiri.
- **Kolom dinamis**: tambah/ubah/hapus/urutkan kolom per project, tiap kolom
  punya *tipe* (teks, angka, tanggal, status, dropdown, dst) dan *role*
  (`normal`, `group`, `status`, `date`) yang menentukan chart apa yang
  otomatis muncul di dashboard.
- **Template MOP siap pakai**: "Migrasi VM (Jadwal & Cutover)" — 16 kolom
  sesuai format file jadwal migrasi VM yang umum dipakai; atau mulai kosong.
- **Data MOP**: tabel spreadsheet-style, edit inline per sel, tambah/hapus
  baris, pencarian.
- **Dashboard interaktif per project**: total item, progress selesai (dari
  kolom status utama), chart donat per kolom status (pewarnaan otomatis
  berdasar kata kunci: ready/hijau, not ready/merah, done/biru, failed/merah
  tua, dst — tetap jalan walau nilainya teks bebas seperti "Failed
  connection refused by the node"), chart batang per kolom grouping (Tim,
  Site, PIC, Cluster, ...), dan timeline jumlah item per tanggal (mis. per
  malam cutover).
- **Import CSV**: tempel teks CSV atau upload file, pilih baris header &
  delimiter, preview pemetaan kolom (cocok vs kolom baru), lalu import —
  kolom yang belum ada otomatis dibuat.
- **Export CSV** per project (delimiter `;`, siap dibuka Excel).

## Login & Keamanan

**Login**: satu akun admin hardcoded (bukan tabel user — sengaja, ini gerbang
single-admin untuk tool internal, bukan sistem multi-tenant).

- Username default: `admin`. Password di-hash (bcrypt) di `src/Auth.php`
  konstanta `ADMIN_PASSWORD_HASH`.
- **Ganti password**: generate hash baru lalu timpa konstanta itu —
  ```bash
  php -r "echo password_hash('password-baru-anda', PASSWORD_DEFAULT);"
  ```
  Jangan pernah menaruh password plaintext di source.
- Semua route `index.php` (SPA) dan `api.php` (semua endpoint) menolak akses
  tanpa session valid (`index.php`/`api.php` → redirect ke `login.php` /
  `401 Unauthorized`). Hanya file statis (`css/`, `js/`, `login.php`) yang
  bisa diakses tanpa login — supaya halaman login sendiri bisa tampil.
- **Rate limit**: 5x salah dalam 5 menit per IP → terkunci sementara
  (dicatat di tabel `mop_login_attempts`, otomatis dibuat).
- **Session**: cookie `mopboard_sid`, `HttpOnly` + `Secure` + `SameSite=Strict`,
  disimpan di `data/sessions/` (mode `0700`, di luar `/tmp` bersama server ini
  supaya tidak campur dengan sesi aplikasi lain di server yang sama), auto
  idle-timeout 2 jam, `session_regenerate_id()` setiap login berhasil
  (cegah session fixation).
- Form login dilindungi CSRF token per-session.
- Frontend otomatis redirect ke `login.php` kalau API mengembalikan 401
  (mis. session kedaluwarsa di tengah pemakaian).

**CSP & SRI** (header dikirim dari vhost Apache, lihat
`/usr/local/apache2.4.68/conf/conf.d/mopboard.conf` di server produksi, atau
tambahkan manual kalau deploy di tempat lain):

CSP dikirim dalam **dua tingkat** dari vhost Apache (bukan satu kebijakan
untuk semua halaman):

- **`login.php` / `logout.php`** (bawa kredensial, tidak memuat JS/Chart.js
  sama sekali) → kebijakan paling ketat, **tanpa pengecualian**: `script-src
  'self'; style-src 'self'` — titik. Tidak ada `unsafe-inline` di mana pun,
  karena halaman ini memang tidak butuh inline apa pun. CSS-nya juga sengaja
  file terpisah (`public/css/login.css`, tidak bergantung ke `app.css`) dan
  dimuat dengan `integrity="sha384-..."` + `crossorigin="anonymous"` (Subresource
  Integrity) — browser menolak memakainya kalau isinya berubah/di-tamper.
  Dipisah dari `app.css` supaya hash SRI-nya stabil (`app.css` sering
  berubah tiap ada fitur dashboard baru; kalau login.css ikut satu file,
  hash-nya harus di-update tiap kali juga — rawan lupa dan bikin halaman
  login rusak). Setelah mengedit `login.css`, regenerate hash-nya:
  ```bash
  openssl dgst -sha384 -binary public/css/login.css | openssl base64 -A
  ```
  lalu update atribut `integrity` di `public/login.php`.
- **`index.php` / `api.php` / aset dashboard** → kebijakan yang sama
  ketatnya di `script-src` (`'self'`, tanpa CDN — Chart.js di-vendor lokal di
  `public/js/vendor/chart.umd.min.js`, juga dengan SRI) dan `style-src-elem`
  (`'self'`, blokir injeksi `<style>`), tapi `style-src-attr` dilonggarkan ke
  `'unsafe-inline'` — satu-satunya celah, dan sudah diaudit: dibutuhkan oleh
  (1) Chart.js untuk resize `<canvas>` responsif (limitasi upstream
  terdokumentasi di chartjs/Chart.js#5208 & #8108) dan (2) fitur freeze-kolom
  di tabel Data MOP, yang men-set offset `left` per kolom lewat JS karena
  lebar kolom memang dinamis. Kalau suatu saat ini justru bikin tampilan
  dashboard rusak di browser tertentu, aman untuk menghapus baris
  `Content-Security-Policy` dashboard ini dari vhost — halaman login tidak
  terpengaruh karena kebijakannya terpisah.
- Header pendukung lain (berlaku di semua halaman, tidak ada gesekan dengan
  Chart.js/dashboard): `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy` (matikan kamera/mic/geolocation dll), `Strict-Transport-Security`,
  `Cross-Origin-Opener-Policy`/`Cross-Origin-Resource-Policy: same-origin`,
  dan header `X-Powered-By` di-strip.
- **Tidak diubah** (di luar scope vhost ini karena server dipakai bareng
  situs lain): banner versi Apache (`Server:` header) diatur oleh
  `ServerTokens`/`ServerSignature` global di `httpd.conf`.

## Catatan

- Project contoh "Jadwal Migrasi VM – Nonprod (Contoh)" dibuat otomatis saat
  database pertama kali dibuat, berisi subset data nyata untuk demo
  dashboard. Hapus lewat tombol **Hapus** di project itu jika tidak
  diperlukan, lalu buat project baru / import CSV Anda sendiri.
- Untuk reset total: hentikan server lalu hapus `data/mopboard.sqlite*`.
