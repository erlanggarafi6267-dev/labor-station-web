# Technical Documentation

Dokumentasi teknis LABOR_STATION.

## Documentation Index

| No. | Document | Description |
|---|---|---|
| 1 | [System Architecture](SYSTEM_ARCHITECTURE.md) | Arsitektur dan komponen sistem |
| 2 | [Database Design](DATABASE_DESIGN.md) | Struktur tabel dan relasi database |
| 3 | [API Documentation](API_DOCUMENTATION.md) | Endpoint dan komunikasi API |
| 4 | [User Roles](USER_ROLES.md) | Role dan hak akses pengguna |
| 5 | [Transaction Flow](TRANSACTION_FLOW.md) | Alur transaksi POS |

## Project Components

- `../frontend/` — Next.js frontend.
- `../backend/` — Laravel REST API.
- `../` — Root monorepo.

## Documentation Guidelines

- Dokumentasi mengikuti implementasi kode terbaru.
- Perubahan database harus diikuti pembaruan dokumentasi database.
- Perubahan API harus diikuti pembaruan dokumentasi endpoint.
- Perubahan hak akses harus diikuti pembaruan dokumentasi pengguna.
- Jangan menyimpan credential atau informasi rahasia di dokumentasi.