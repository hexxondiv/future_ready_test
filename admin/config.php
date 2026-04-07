<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}

/**
 * Admin credentials. Change the password hash before production:
 *   php -r "echo password_hash('your_secure_password', PASSWORD_DEFAULT);"
 *
 * Default login: admin / changeme
 */
return [
    'username' => 'admin',
    'password_hash' => '$2y$12$84T9Ps.EunuIEtn5r6yQuO4DNRv3mI4nxixpJ3a947K7zUyDNuzmy',
];
