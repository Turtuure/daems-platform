# Tests for daems-platform

This directory contains automated tests for the backend.

## Running Tests

- Make sure dependencies are installed:

  ```sh
  composer install
  ```

- Run PHPUnit:

  ```sh
  vendor/bin/phpunit
  ```

## Structure

- Place unit and integration tests in this directory, following PSR-4 autoloading.
- Test files should end with `Test.php`.
