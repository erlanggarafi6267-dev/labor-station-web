# ☕ LABOR_STATION

<p align="center">
  <strong>Integrated Point of Sale & Coffee Shop Management System</strong>
  <br />
  Sistem informasi penjualan dan manajemen operasional kedai kopi.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Next.js-16-black?logo=next.js" alt="Next.js" />
  <img src="https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white" alt="Laravel" />
  <img src="https://img.shields.io/badge/PostgreSQL-Database-4169E1?logo=postgresql&logoColor=white" alt="PostgreSQL" />
  <img src="https://img.shields.io/badge/TypeScript-Ready-3178C6?logo=typescript&logoColor=white" alt="TypeScript" />
</p>

---

## 📌 About the Project

**LABOR_STATION** merupakan sistem Point of Sale (POS) berbasis web yang dirancang untuk membantu pengelolaan aktivitas operasional kedai kopi secara terintegrasi.

Sistem ini menggabungkan pengelolaan produk, transaksi penjualan, pembayaran, pengeluaran, serta pengaturan hak akses pengguna dalam satu aplikasi.

LABOR_STATION mendukung dua kanal penjualan, yaitu **Offline** dan **GrabFood**.

## 🖥️ Technology Stack

### Frontend

<p>
  <img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/nextjs/nextjs-original.svg" width="55" alt="Next.js" />
  <img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/react/react-original.svg" width="55" alt="React" />
  <img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/typescript/typescript-original.svg" width="55" alt="TypeScript" />
  <img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/tailwindcss/tailwindcss-original.svg" width="55" alt="Tailwind CSS" />
</p>

- Next.js 16
- React 19
- TypeScript
- Tailwind CSS 4

### Backend

<p>
  <img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/laravel/laravel-original.svg" width="65" alt="Laravel" />
  <img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/php/php-original.svg" width="65" alt="PHP" />
</p>

- Laravel 13
- PHP 8.3+
- Laravel Sanctum
- REST API

### Database

<p>
  <img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/postgresql/postgresql-original.svg" width="65" alt="PostgreSQL" />
</p>

- PostgreSQL

## ✨ Main Features

| Module | Description |
|---|---|
| Dashboard | Ringkasan informasi operasional |
| Product Management | Pengelolaan produk dan kategori |
| Product Pricing | Pengaturan harga berdasarkan kanal penjualan |
| Transactions | Pembuatan dan pengelolaan transaksi |
| Payment | Pencatatan pembayaran |
| Order Status | Pengelolaan status pesanan |
| GrabFood Payout | Pencatatan potongan dan pencairan GrabFood |
| Expense Management | Pengelolaan pengeluaran usaha |
| User Management | Pengelolaan pengguna |
| Role & Permission | Pengaturan hak akses |
| Audit Log | Pencatatan aktivitas sistem |

## 🧾 Sales Channels

LABOR_STATION mendukung dua kanal penjualan:

- **Offline** — transaksi penjualan langsung.
- **GrabFood** — transaksi melalui platform GrabFood.

Harga produk dikelola berdasarkan kanal penjualan.

Produk minuman yang mendukung pilihan temperatur dapat menyediakan opsi **Hot** atau **Iced**. Pilihan tersebut disimpan pada item transaksi tanpa membuat produk atau harga baru.

## 🏗️ System Architecture

LABOR_STATION menggunakan arsitektur frontend dan backend yang terpisah.

```text
LABOR_STATION
│
├── Frontend
│   ├── Next.js
│   ├── React
│   ├── TypeScript
│   └── Tailwind CSS
│
├── Backend
│   ├── Laravel
│   ├── REST API
│   └── Laravel Sanctum
│
└── Database
    └── PostgreSQL
```

Frontend berkomunikasi dengan backend melalui REST API. Backend menangani autentikasi, otorisasi, logika bisnis, dan pengelolaan data melalui PostgreSQL.

## 👥 User Roles

Sistem menyediakan tiga role pengguna:

| Role | Description |
|---|---|
| Owner | Pemilik usaha dengan akses khusus pemilik |
| Admin | Pengguna dengan peran administrasi |
| Staff | Pengguna operasional |

Hak akses fitur dapat diatur menggunakan sistem permission.

## 📁 Project Structure

```text
labor-station/
│
├── frontend/
│   ├── src/
│   ├── public/
│   ├── package.json
│   └── README.md
│
├── backend/
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── public/
│   ├── resources/
│   ├── routes/
│   └── README.md
│
├── documentation/
│   ├── README.md
│   ├── SYSTEM_ARCHITECTURE.md
│   ├── DATABASE_DESIGN.md
│   ├── API_DOCUMENTATION.md
│   ├── USER_ROLES.md
│   └── TRANSACTION_FLOW.md
│
├── .gitignore
└── README.md
```

## ⚙️ Installation & Setup

### Requirements

Pastikan perangkat sudah memiliki:

- Node.js dan npm
- PHP 8.3 atau lebih baru
- Composer
- PostgreSQL
- Git

### 1. Clone Repository

```bash
git clone https://github.com/erlanggarafi6267-dev/labor-station-web.git

cd labor-station-web
```

### 2. Setup Backend

```bash
cd backend

composer install

cp .env.example .env

php artisan key:generate
```

Atur konfigurasi database PostgreSQL pada file `.env`, kemudian jalankan:

```bash
php artisan migrate --seed

php artisan serve
```

### 3. Setup Frontend

Buka terminal baru:

```bash
cd frontend

npm install

npm run dev
```

Frontend secara default dapat diakses melalui:

```text
http://localhost:3000
```

Backend Laravel secara default dapat diakses melalui:

```text
http://localhost:8000
```

Pastikan konfigurasi URL API frontend sesuai dengan alamat backend yang digunakan.

## 📚 Documentation

Dokumentasi teknis proyek tersedia pada folder `documentation/`.

| Document | Description |
|---|---|
| [System Architecture](documentation/SYSTEM_ARCHITECTURE.md) | Arsitektur sistem |
| [Database Design](documentation/DATABASE_DESIGN.md) | Struktur dan relasi database |
| [API Documentation](documentation/API_DOCUMENTATION.md) | Dokumentasi endpoint API |
| [User Roles](documentation/USER_ROLES.md) | Role dan permission |
| [Transaction Flow](documentation/TRANSACTION_FLOW.md) | Alur transaksi |
| [Documentation Index](documentation/README.md) | Indeks dokumentasi |

## 🔐 Security

- Autentikasi menggunakan Laravel Sanctum.
- Password disimpan menggunakan hashing.
- Otorisasi menggunakan role dan permission.
- Konfigurasi rahasia disimpan melalui environment variables.
- File `.env` tidak boleh diunggah ke repository publik.

## 🚀 Project Status

LABOR_STATION dikembangkan sebagai proyek sistem informasi Point of Sale untuk mendukung pengelolaan operasional kedai kopi.

---

<p align="center">
  <strong>LABOR_STATION</strong>
  <br />
  <sub>Integrated Point of Sale & Coffee Shop Management System</sub>
</p>
