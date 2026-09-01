<?php
// router.php — точка входа для встроенного сервера PHP:
//   php -S 0.0.0.0:3000 -t public router.php
// Реальные файлы под public/ сервер отдаёт сам; сюда попадают только /api/*.

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($uri === '/api/me') {
    require __DIR__ . '/api/me.php';
    return true;
}

if ($uri === '/api/dashboard') {
    require __DIR__ . '/api/dashboard.php';
    return true;
}

return false;
