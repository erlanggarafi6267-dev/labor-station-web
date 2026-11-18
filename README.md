# LABOR_STATION

### Integrated Point of Sale (POS) Management System

<p align="center">
  <img src="https://img.shields.io/badge/Next.js-16.4.6-black?logo=next.js" alt="Next.js" />
  <img src="https://img.shields.io/badge/React-19.3.0-61DAFB?logo=react" alt="React" />
  <img src="https://img.shields.io/badge/TypeScript-3178C6?logo=typescript&logoColor=white" alt="TypeScript" />
  <img src="https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white" alt="Laravel" />
  <img src="https://img.shields.io/badge/PostgreSQL-4169E1?logo=postgresql&logoColor=white" alt="PostgreSQL" />
</p>

LABOR_STATION is an integrated Point of Sale (POS) management system designed to support business operations, including product management, sales transactions, and operational activities through a centralized application.

The project uses a **monorepo architecture**, separating the frontend and backend to improve maintainability, scalability, and development organization.

---

## Project Structure

```text
labor-station/
├── frontend/           # Next.js application
├── backend/            # Laravel REST API
├── documentation/      # Technical documentation
├── .gitignore
└── README.md
```

## Technology Stack

### Frontend

<p>
  <img src="https://skillicons.dev/icons?i=nextjs,react,ts,tailwind" alt="Frontend technologies" />
</p>

| Technology | Version |
|---|---|
| Next.js | 16.4.6 |
| React | 19.3.0 |
| TypeScript | - |
| Tailwind CSS | 4.3.3 |
| ESLint | 9 |

### Backend

<p>
  <img src="https://skillicons.dev/icons?i=laravel,php" alt="Backend technologies" />
</p>

| Technology | Version |
|---|---|
| PHP | 8.3+ |
| Laravel | 13 |
| Laravel Sanctum | 4 |
| Composer | Dependency Manager |

### Database

<p>
  <img src="https://skillicons.dev/icons?i=postgres" alt="PostgreSQL" />
</p>

- PostgreSQL
- Default local port: `5433`

---

## Main Features

| Module | Description |
|---|---|
| Product Management | Manage product information and categories |
| Sales Transactions | Process and manage sales transactions |
| Sales Channels | Support Offline and GrabFood transactions |
| Product Pricing | Manage channel-specific product prices |
| Product Availability | Configure product availability by sales channel |
| Preparation Options | Support Hot and Iced options for eligible drinks |
| User Management | Manage user roles and permissions |
| Operational Management | Support business operational activities |

*Features and implementation details may evolve during development.*

## System Architecture

```text
┌──────────────────────────────┐
│          FRONTEND            │
│       Next.js + React        │
└──────────────┬───────────────┘
               │ REST API
               ▼
┌──────────────────────────────┐
│           BACKEND            │
│      Laravel 13 + Sanctum    │
└──────────────┬───────────────┘
               │
               ▼
┌──────────────────────────────┐
│          DATABASE            │
│          PostgreSQL          │
└──────────────────────────────┘
```

## Requirements

Make sure the following tools are installed:

- Node.js and npm
- PHP 8.3 or newer
- Composer
- PostgreSQL
- Git

## Installation

### 1. Clone Repository

```bash
git clone https://github.com/erlanggarafi6267-dev/labor-station-web.git

cd labor-station-web
```

### 2. Backend Setup

```bash
cd backend

composer install

Copy-Item .env.example .env

php artisan key:generate
```

Configure the database connection in `backend/.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5433
DB_DATABASE=labor_station
DB_USERNAME=labor_station_app
DB_PASSWORD=your_local_database_password
```

Create the PostgreSQL database and user before running migrations.

```bash
php artisan migrate

php artisan serve
```

Backend development server:

`http://127.0.0.1:8000`

### 3. Frontend Setup

Open another terminal from the repository root:

```bash
cd frontend

npm install

npm run dev
```

Frontend development server:

`http://localhost:3000`

## Development Commands

### Frontend

```bash
npm run dev
npm run build
npm run start
npm run lint
```

### Backend

```bash
php artisan serve
php artisan migrate
php artisan route:list
php artisan test
php artisan optimize:clear
```

## Environment Configuration

Environment files are maintained locally and must not be committed to Git.

- `backend/.env` — Backend and database configuration.
- Frontend environment variables — Configure according to the frontend API integration.
- `.env.example` — Backend environment configuration template.

## Documentation

Technical documentation is available in the `documentation/` directory.

- System Architecture
- Database Design
- API Documentation
- User Roles and Permissions
- Transaction Flow

## Security

- Never commit `.env` files or database credentials.
- Do not expose application keys, API tokens, or passwords.
- Apply appropriate role and permission checks.
- Validate incoming API requests.

## Project Status

**LABOR_STATION is currently under active development.**

Features, documentation, and system architecture may be updated as development progresses.

---

<p align="center">
  <strong>LABOR_STATION</strong><br/>
  Integrated Point of Sale Management System
</p>

<p align="center">
  <a href="https://github.com/erlanggarafi6267-dev/labor-station-web">GitHub Repository</a>
</p>