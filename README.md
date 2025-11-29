# AEATech Transaction Manager — Doctrine Adapter

An adapter that implements `AEATech\TransactionManager\ConnectionInterface` on top of Doctrine DBAL. It allows the AEATech Transaction Manager core to work with any database supported by Doctrine DBAL while providing a consistent API for beginning/committing/rolling back transactions and setting transaction isolation levels.

## Overview

This package exposes a single class:

- `AEATech\TransactionManager\DoctrineAdapter\DbalConnectionAdapter`

It wraps a `Doctrine\DBAL\Connection` and forwards transaction calls. Isolation levels from the core (`AEATech\TransactionManager\IsolationLevel`) are mapped to Doctrine’s `TransactionIsolationLevel`.

Main responsibilities:
- Start, commit, and roll back transactions
- Set transaction isolation level
- Execute DML statements via `executeStatement()` passthrough

## Installation

Install via Composer in your project:

```bash
composer require aeatech/transaction-manager-doctrine-adapter
```

## Usage

Create a Doctrine DBAL connection (`Doctrine\DBAL\Connection`) and wrap it with the adapter:

```php
use AEATech\TransactionManager\DoctrineAdapter\DbalConnectionAdapter;
use AEATech\TransactionManager\IsolationLevel;
use Doctrine\DBAL\DriverManager;

$connection = DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => '127.0.0.1',
    'dbname' => 'app',
    'user' => 'app',
    'password' => 'secret',
]);

$conn = new DbalConnectionAdapter($connection);

$conn->beginTransaction();
try {
    $conn->setTransactionIsolationLevel(IsolationLevel::Serializable);
    $conn->executeStatement('UPDATE accounts SET balance = balance - ? WHERE id = ?', [100, 1]);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollBack();
    throw $e;
}
```

## Tests

Make sure the Docker containers are up and running. From the project root:

```bash
docker-compose -p aeatech-transaction-manager-doctrine-adapter -f docker/docker-compose.yml up -d --build
```

### 2. Install Dependencies
Install composer dependencies inside the container (using PHP 8.2 as a base):
```bash
docker-compose -p aeatech-transaction-manager-doctrine-adapter -f docker/docker-compose.yml exec php-cli-8.2 composer install
```

# 3. Run Tests
PHP 8.2
```bash
docker-compose -p aeatech-transaction-manager-doctrine-adapter -f docker/docker-compose.yml exec php-cli-8.2 vendor/bin/phpunit
```

PHP 8.3
```bash
docker-compose -p aeatech-transaction-manager-doctrine-adapter -f docker/docker-compose.yml exec php-cli-8.3 vendor/bin/phpunit
```

PHP 8.4
```bash
docker-compose -p aeatech-transaction-manager-doctrine-adapter -f docker/docker-compose.yml exec php-cli-8.4 vendor/bin/phpunit
```

### 4. Run All Tests (Bash Script)
```bash
for v in 8.2 8.3 8.4; do \
    echo "Testing PHP $v..."; \
    docker-compose -p aeatech-transaction-manager-doctrine-adapter -f docker/docker-compose.yml exec -T php-cli-$v vendor/bin/phpunit || break; \
done
```

## License

This project is licensed under the MIT License. See the [LICENSE](./LICENSE) file for details.
