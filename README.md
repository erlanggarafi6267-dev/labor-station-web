# LABOR_STATION

**Integrated Point of Sale (POS) Management System**

LABOR_STATION adalah aplikasi Point of Sale (POS) yang dirancang untuk membantu pengelolaan operasional penjualan, produk, transaksi, dan aktivitas bisnis dalam satu sistem terintegrasi.

Project ini menggunakan arsitektur monorepo yang memisahkan frontend dan backend agar pengembangan, pemeliharaan, dan pengelolaan sistem lebih terstruktur.

## Project Structure

```text
labor-station/
├── frontend/          # Next.js application
├── backend/           # Laravel REST API
├── documentation/     # Project documentation
├── .gitignore
└── README.md
```

## Technology Stack

### Frontend
- Next.js 16.4.6
- React 19.3.0
- TypeScript
- Tailwind CSS 4.3.3
- ESLint 9

### Backend
- PHP 8.3+
- Laravel 13
- Laravel Sanctum 4
- Composer

### Database
- PostgreSQL
- Default local port: 5433

## Main Components

| Component | Description |
|---|---|
| Frontend | User interface and application dashboard |
| Backend | Business logic and REST API |
| Database | Persistent application data |
| Documentation | Technical and project documentation |

## Application Modules

The system is developed to support:

- Product management
- Sales transactions
- Offline and GrabFood sales channels
- Channel-specific product pricing and availability
- Hot and Iced preparation options for eligible drinks
- User roles and permission management
- Operational management

*Module availability and implementation details may evolve during development.*

## Requirements

Install the following tools before running the project:

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

### 2. Setup Backend

```bash
cd backend
composer install
```

Create the local environment file:

```bash
Copy-Item .env.example .env
```

Generate the application key:

```bash
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

Create the database and user in PostgreSQL before running migrations.

Run database migrations:

```bash
php artisan migrate
```

Start the backend:

```bash
php artisan serve
```

Backend development server:

`http://127.0.0.1:8000`

### 3. Setup Frontend

Open another terminal from the repository root:

```bash
cd frontend
npm install
```

Start the frontend:

```bash
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

- `backend/.env` contains backend and database configuration.
- Frontend environment variables must be configured according to the frontend application's API integration.

Use `.env.example` as the backend configuration template.

## Git Workflow

The repository uses a monorepo structure.

- `main` — primary development branch.
- `frontend/` — frontend application.
- `backend/` — backend API.
- `documentation/` — project documentation.

Commit messages should describe the changes made.

## Security

- Never commit `.env` files or database credentials.
- Do not expose application keys, API tokens, or passwords.
- Use appropriate role and permission checks for protected operations.
- Validate incoming API requests.

## Project Status

LABOR_STATION is under active development. Features, documentation, and system architecture may be updated as development progresses.

---

**Repository:** [LABOR_STATION](https://github.com/erlanggarafi6267-dev/labor-station-web)
