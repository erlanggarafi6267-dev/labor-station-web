# User Roles & Permissions — LABOR_STATION

## 1. Overview

LABOR_STATION menerapkan sistem Role-Based Access Control (RBAC) untuk mengatur akses pengguna terhadap fitur aplikasi.

Sistem memiliki tiga role utama, yaitu Owner, Admin, dan Staff. Selain role, sistem menggunakan permission untuk mengatur tindakan yang dapat dilakukan oleh setiap pengguna.

Pengelolaan role dan permission dilakukan melalui backend Laravel.

## 2. User Roles

Berdasarkan implementasi `RoleSeeder.php`, terdapat tiga role yang terdaftar.

| Role | Slug | Keterangan |
|---|---|---|
| Owner | `owner` | Pemilik usaha dengan akses khusus pemilik |
| Admin | `admin` | Pengguna dengan role administrator |
| Staff | `staff` | Pengguna operasional |

### 2.1 Owner

Owner merupakan pemilik usaha yang memiliki akses khusus melalui middleware `role:owner`.

Fitur yang menggunakan pembatasan role Owner meliputi:

- Melihat dashboard pemilik.
- Melihat audit log.
- Melihat detail audit log.
- Melihat daftar pengguna.
- Melihat permission pengguna.
- Mengatur permission pengguna.
- Mengelola pencatatan payout GrabFood.

Owner juga memiliki akses khusus terhadap fitur yang menerapkan pemeriksaan role Owner di dalam aplikasi.

### 2.2 Admin

Admin merupakan salah satu role yang terdaftar dalam sistem.

Role Admin disediakan untuk kebutuhan pengelolaan operasional dan administrasi aplikasi.

Daftar permission yang diberikan kepada Admin harus mengikuti konfigurasi hak akses pengguna yang diterapkan pada sistem.

### 2.3 Staff

Staff merupakan role yang disediakan untuk pengguna operasional.

Staff dapat memperoleh akses terhadap fitur tertentu berdasarkan permission yang diberikan kepadanya.

Akses Staff tidak otomatis mencakup seluruh fitur aplikasi.

## 3. Permission List

Berdasarkan `PermissionSeeder.php`, sistem menyediakan permission berikut.

| No. | Permission | Slug | Fungsi |
|---|---|---|---|
| 1 | Melihat Produk | `product.view` | Melihat katalog dan daftar produk |
| 2 | Menambah Produk | `product.create` | Menambahkan produk |
| 3 | Mengubah Produk | `product.update` | Memperbarui informasi produk |
| 4 | Mengubah Harga Produk | `product.price.update` | Mengubah harga produk |
| 5 | Menghapus Produk | `product.delete` | Menghapus produk |
| 6 | Mengubah Ketersediaan Produk | `product.status.update` | Mengubah status ketersediaan produk |
| 7 | Mengelola Kategori | `category.manage` | Mengelola kategori produk |
| 8 | Melihat Transaksi | `transaction.view` | Melihat data transaksi |
| 9 | Membuat Transaksi | `transaction.create` | Membuat transaksi baru |
| 10 | Mengelola Pembayaran | `transaction.payment` | Mengelola pembayaran transaksi |
| 11 | Mengubah Status Pesanan | `transaction.status.update` | Memperbarui status pesanan |
| 12 | Melihat Pengeluaran | `expense.view` | Melihat data pengeluaran |
| 13 | Menambah Pengeluaran | `expense.create` | Menambahkan pengeluaran |
| 14 | Mengubah Pengeluaran | `expense.update` | Memperbarui pengeluaran |
| 15 | Menghapus Pengeluaran | `expense.delete` | Menghapus pengeluaran |
| 16 | Melihat Audit Log | `audit.view` | Permission untuk akses audit log |
| 17 | Mengatur Permission Pengguna | `permission.manage` | Permission untuk pengaturan hak akses |

## 4. Permission Management

Sistem menyediakan pengelolaan permission pengguna melalui endpoint khusus Owner.

| Method | Endpoint | Fungsi |
|---|---|---|
| GET | `/api/owner/users` | Melihat daftar pengguna |
| GET | `/api/owner/users/{userId}/permissions` | Melihat permission pengguna |
| PUT | `/api/owner/users/{userId}/permissions` | Memperbarui permission pengguna |

Relasi antara pengguna dan permission disimpan melalui tabel `user_permissions`.

Informasi permission yang diberikan dapat mencatat pengguna yang memberikan izin melalui kolom `granted_by`.

## 5. Authorization Mechanism

Pemeriksaan akses dilakukan menggunakan middleware dan pemeriksaan permission pada aplikasi.

Mekanisme otorisasi meliputi:

1. Pengguna melakukan autentikasi.
2. Sistem mengidentifikasi role pengguna.
3. Sistem memeriksa permission yang diperlukan oleh endpoint atau fitur.
4. Jika akses diizinkan, permintaan diproses.
5. Jika akses tidak diizinkan, sistem menolak permintaan.

Owner memiliki jalur akses khusus melalui middleware `role:owner`.

## 6. Role and Permission Relationship

Tabel `roles` menyimpan daftar role pengguna.

Tabel `permissions` menyimpan daftar permission yang tersedia.

Tabel `user_permissions` menghubungkan pengguna dengan permission yang diberikan.

Struktur tersebut memungkinkan pengaturan hak akses secara lebih fleksibel tanpa harus membuat role baru untuk setiap variasi kebutuhan akses.

## 7. Security Notes

- Endpoint yang membutuhkan autentikasi menggunakan Laravel Sanctum.
- Hak akses dibatasi berdasarkan role atau permission.
- Pengelolaan permission pengguna dibatasi untuk Owner.
- Pengguna tidak boleh memperoleh akses terhadap fitur yang tidak diizinkan.
- Password pengguna disimpan menggunakan mekanisme hashing.
- Konfigurasi akun awal Owner harus dijaga dan password awal sebaiknya diganti setelah instalasi.

## 8. Implementation Notes

Dokumentasi ini disusun berdasarkan `RoleSeeder.php`, `PermissionSeeder.php`, `OwnerSeeder.php`, struktur tabel database, dan definisi route backend.

Seeder yang diperiksa mendaftarkan tiga role dan daftar permission, tetapi belum menunjukkan pembagian permission bawaan untuk masing-masing role Admin dan Staff.

Pembagian hak akses aktual mengikuti data permission pengguna dan implementasi pemeriksaan otorisasi pada backend.