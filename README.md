# CCIS Faculty Evaluation System

A campus-scoped Laravel and Filament application for faculty evaluations, student quizzes, class records, role-based administration, and evaluation reporting.

Repository: [John09278606492/ccis-faculty-evaluation](https://github.com/John09278606492/ccis-faculty-evaluation)

## Main capabilities

- Multi-campus administration with tenant-aware access
- Configurable faculty evaluation forms and scoring
- Anonymous evaluation submissions with duplicate prevention
- Student enrollment and course-offering management
- Quizzes, timed attempts, manual grading, and private file submissions
- Class record imports and evaluation reports
- Role and permission management through Filament Shield

## Requirements

- PHP 8.2 or newer with the Laravel-required extensions
- Composer 2
- Node.js 20 or newer and npm
- MySQL or MariaDB for normal use; SQLite is used by the automated tests

## Local setup with Laragon

```powershell
git clone https://github.com/John09278606492/ccis-faculty-evaluation.git
cd ccis-faculty-evaluation
Copy-Item .env.example .env
composer install
npm install
php artisan key:generate
```

Create a database named `ccis_faculty_evaluation`, update the `DB_*` values in `.env`, then run:

```powershell
php artisan migrate --seed
php artisan shield:generate --all --panel=admin
php artisan make:filament-user
php artisan shield:super-admin
npm run build
```

Point a Laragon virtual host at the project's `public` directory, or run `composer run dev` for Laravel, the queue, logs, and Vite together.

## Development checks

```powershell
php artisan test
vendor\bin\pint --test
npm run build
composer audit --locked
npm audit --audit-level=high
```

GitHub Actions runs the same tests, formatting check, production asset build, and dependency audits on pushes to `main` and on pull requests.

## Security and privacy

- Evaluation access is checked against the form's campus, allowed roles, and student enrollment.
- New evaluation records do not store evaluator user IDs, IP addresses, or browser user-agent strings. A keyed one-way fingerprint enforces one submission per evaluator and course offering.
- Set a stable `EVALUATION_FINGERPRINT_KEY` in production. If omitted, the application uses `APP_KEY`; changing either key resets duplicate-recognition for future requests.
- Quiz attachments are restricted by type and size, stored on the private disk, and downloaded only through an authorized route. Existing public-disk attachments remain downloadable through the protected route for compatibility.
- Use HTTPS in production and set `APP_ENV=production`, `APP_DEBUG=false`, and the correct `APP_URL`.
- Never commit `.env`, credentials, database exports, or files from `storage/app/private`.

## Deployment

After deploying a reviewed revision:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
php artisan filament:cache-components
php artisan icons:cache
```

Back up the database before migrations, keep `EVALUATION_FINGERPRINT_KEY` stable, and ensure the web server can write to `storage` and `bootstrap/cache`.

## License

This project is licensed under the MIT License.
