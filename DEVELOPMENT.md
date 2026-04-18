# Development Guide

## Prerequisites

- PHP >= 8.1
- Composer
- MySQL or MariaDB
- Apache/Nginx or PHP built-in server
- Laragon (recommended for local development)

## Getting Started

1. Clone the repository:

   ```sh
   git clone <repo-url>
   ```

2. Install dependencies:

   ```sh
   composer install
   ```

3. Copy and configure your environment variables:

   ```sh
   cp .env.example .env
   # Edit .env as needed
   ```

4. Set up the database and run migrations (if available).

5. Start the development server:

   ```sh
   php -S localhost:8000 -t public
   # Or use Apache/Nginx
   ```

## Code Style

- Follow PSR-12 for PHP.
- See `.editorconfig` for formatting rules.

## Testing

- Run tests with PHPUnit:

  ```sh
  vendor/bin/phpunit
  ```
