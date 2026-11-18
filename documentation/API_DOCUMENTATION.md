# API Documentation — LABOR_STATION

## 1. Overview

Dokumen ini menjelaskan endpoint API yang tersedia pada backend LABOR_STATION.

Backend menggunakan Laravel dan menyediakan REST API untuk autentikasi, pengelolaan produk, kategori, transaksi, pembayaran, pengeluaran, dashboard pemilik, serta pengaturan hak akses pengguna.

## 2. Base URL

Base URL mengikuti alamat backend yang digunakan pada lingkungan pengembangan atau deployment.

```text
http://localhost:8000/api
```

## 3. Authentication

API menggunakan Laravel Sanctum untuk autentikasi.

Endpoint login dapat diakses tanpa autentikasi. Endpoint lain yang berada di dalam middleware `auth:sanctum` membutuhkan autentikasi pengguna.

### Login

| Method | Endpoint | Keterangan |
|---|---|---|
| POST | `/login` | Melakukan login pengguna |

Login memiliki pembatasan percobaan menggunakan middleware throttle sebanyak 5 permintaan per menit.

### Authenticated User

| Method | Endpoint | Middleware | Keterangan |
|---|---|---|---|
| POST | `/logout` | `auth:sanctum` | Mengakhiri sesi autentikasi |
| GET | `/me` | `auth:sanctum` | Mendapatkan informasi pengguna yang sedang login |

## 4. Product API

### Product Catalog

| Method | Endpoint | Middleware | Keterangan |
|---|---|---|---|
| GET | `/products` | `auth:sanctum`, `product.view` | Melihat katalog produk |

### Product Management

| Method | Endpoint | Middleware | Keterangan |
|---|---|---|---|
| GET | `/owner/products` | `auth:sanctum`, `product.view` | Melihat daftar produk |
| POST | `/owner/products` | `auth:sanctum`, `product.create` | Menambahkan produk |
| GET | `/owner/products/{id}` | `auth:sanctum`, `product.view` | Melihat detail produk |
| PUT | `/owner/products/{id}` | `auth:sanctum` | Memperbarui produk |
| DELETE | `/owner/products/{id}` | `auth:sanctum`, `product.delete` | Menghapus produk |

Pembaruan produk menggunakan autentikasi. Pemeriksaan izin tambahan dilakukan pada lapisan aplikasi sesuai implementasi controller.

## 5. Category API

Seluruh endpoint kategori menggunakan middleware autentikasi dan izin `category.manage`.

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/owner/categories` | Melihat daftar kategori |
| POST | `/owner/categories` | Menambahkan kategori |
| GET | `/owner/categories/{id}` | Melihat detail kategori |
| PUT | `/owner/categories/{id}` | Memperbarui kategori |
| DELETE | `/owner/categories/{id}` | Menghapus kategori |

## 6. Transaction API

| Method | Endpoint | Middleware | Keterangan |
|---|---|---|---|
| GET | `/transactions` | `auth:sanctum`, `transaction.view` | Melihat daftar transaksi |
| POST | `/transactions` | `auth:sanctum`, `transaction.create` | Membuat transaksi |
| GET | `/transactions/{id}` | `auth:sanctum`, `transaction.view` | Melihat detail transaksi |
| POST | `/transactions/{id}/payment` | `auth:sanctum`, `transaction.payment` | Mencatat pembayaran |
| PATCH | `/transactions/{id}/status` | `auth:sanctum`, `transaction.status.update` | Memperbarui status pesanan |
| POST | `/transactions/{id}/payout` | `auth:sanctum`, `role:owner` | Mengelola pencatatan payout GrabFood |

Transaksi mendukung kanal penjualan Offline dan GrabFood.

Item transaksi menyimpan snapshot nama produk, harga, kuantitas, subtotal, serta pilihan temperatur apabila tersedia. Pilihan temperatur seperti Hot atau Iced disimpan pada item transaksi, bukan sebagai produk dan harga yang berbeda.

## 7. Expense API

| Method | Endpoint | Middleware | Keterangan |
|---|---|---|---|
| GET | `/expenses` | `auth:sanctum`, `expense.view` | Melihat daftar pengeluaran |
| POST | `/expenses` | `auth:sanctum`, `expense.create` | Menambahkan pengeluaran |
| GET | `/expenses/{id}` | `auth:sanctum`, `expense.view` | Melihat detail pengeluaran |
| PUT | `/expenses/{id}` | `auth:sanctum`, `expense.update` | Memperbarui pengeluaran |
| DELETE | `/expenses/{id}` | `auth:sanctum`, `expense.delete` | Menghapus pengeluaran |

## 8. Owner API

Endpoint berikut menggunakan autentikasi dan pembatasan role `owner`.

| Method | Endpoint | Middleware | Keterangan |
|---|---|---|---|
| GET | `/owner/dashboard` | `auth:sanctum`, `role:owner` | Mendapatkan data dashboard pemilik |
| GET | `/owner/audit-logs` | `auth:sanctum`, `role:owner` | Melihat daftar audit log |
| GET | `/owner/audit-logs/{id}` | `auth:sanctum`, `role:owner` | Melihat detail audit log |
| GET | `/owner/users` | `auth:sanctum`, `role:owner` | Melihat daftar pengguna |
| GET | `/owner/users/{userId}/permissions` | `auth:sanctum`, `role:owner` | Melihat izin pengguna |
| PUT | `/owner/users/{userId}/permissions` | `auth:sanctum`, `role:owner` | Memperbarui izin pengguna |

## 9. Authorization

Sistem menggunakan dua lapisan pengaturan akses:

1. **Role** — menentukan kelompok atau kedudukan pengguna dalam sistem.
2. **Permission** — menentukan tindakan yang boleh dilakukan oleh pengguna.

Beberapa endpoint menggunakan middleware permission secara langsung, sedangkan endpoint tertentu melakukan pemeriksaan izin di dalam controller.

Pemilik memiliki akses khusus melalui middleware `role:owner`.

## 10. API Modules

| Modul | Fungsi |
|---|---|
| Authentication | Login, logout, dan informasi pengguna |
| Product | Katalog dan pengelolaan produk |
| Category | Pengelolaan kategori |
| Transaction | Pembuatan dan pengelolaan transaksi |
| Payment | Pencatatan pembayaran |
| Order Status | Pembaruan status pesanan |
| GrabFood Payout | Pencatatan payout GrabFood |
| Expense | Pengelolaan pengeluaran |
| Dashboard | Data ringkasan pemilik |
| Audit Log | Riwayat aktivitas sistem |
| User Permission | Pengelolaan hak akses pengguna |

## 11. Notes

- Semua endpoint selain login memerlukan autentikasi sesuai middleware yang diterapkan.
- Endpoint tertentu memerlukan role atau permission khusus.
- `{id}` merupakan parameter identitas data yang dituju.
- Dokumentasi ini merangkum endpoint dan middleware berdasarkan definisi route backend.
- Format request, response, validasi, dan contoh payload perlu disesuaikan dengan implementasi masing-masing controller sebelum dijadikan kontrak API final.