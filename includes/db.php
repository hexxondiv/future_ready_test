<?php
declare(strict_types=1);

/**
 * MySQL connection via environment (or optional project-root .env).
 *
 * FUTRE_DB_HOST   default 127.0.0.1 (use this instead of "localhost" on Linux when
 *                   root uses password auth — "localhost" often goes through a socket
 *                   and may ignore the password)
 * FUTRE_DB_USER   default root
 * FUTRE_DB_PASS   default adminPassword
 * FUTRE_DB_NAME   default futre_ready_test
 *
 * If the database does not exist, it is created automatically on first connect.
 */

$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\"'");
            if ($k !== '' && getenv($k) === false) {
                putenv("{$k}={$v}");
            }
        }
    }
}

function futre_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('FUTRE_DB_HOST') ?: '127.0.0.1';
    $user = getenv('FUTRE_DB_USER') ?: 'root';
    $pass = getenv('FUTRE_DB_PASS') ?: 'adminPassword';
    $name = getenv('FUTRE_DB_NAME') ?: 'futre_ready_test';

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        throw new RuntimeException('Invalid FUTRE_DB_NAME');
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    $dsnWithDb = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsnWithDb, $user, $pass, $options);
    } catch (PDOException $e) {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($driverCode === 1049) {
            $dsnNoDb = 'mysql:host=' . $host . ';charset=utf8mb4';
            $admin = new PDO($dsnNoDb, $user, $pass, $options);
            $admin->exec(
                'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name)
                . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $pdo = new PDO($dsnWithDb, $user, $pass, $options);
        } else {
            throw $e;
        }
    }

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS registrations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(128) NOT NULL,
  last_name VARCHAR(128) NOT NULL,
  school VARCHAR(512) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(64) NOT NULL,
  designation VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cohort_membership (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(128) NOT NULL,
  last_name VARCHAR(128) NOT NULL,
  designation VARCHAR(120) NOT NULL,
  school VARCHAR(512) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(64) NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  tier_label VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    return $pdo;
}
