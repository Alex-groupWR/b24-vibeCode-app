<?php
// config.php
//
// Значения читаются из переменных окружения (или .env — см. .env.example).
// Секретов здесь нет.

function vibecode_env(string $key, ?string $default = null): ?string
{
    static $loaded = false;
    if (!$loaded) {
        $envFile = __DIR__ . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                [$k, $v] = array_pad(explode('=', $line, 2), 2, null);
                $k = trim((string) $k);
                if ($k !== '' && getenv($k) === false) {
                    putenv($k . '=' . trim((string) $v));
                }
            }
        }
        $loaded = true;
    }
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

// Подтверждённый документацией базовый URL платформы (docs/entity-api,
// docs/infra) — единый для инфра-эндпоинтов и сущностей.
define('VIBE_API_BASE', 'https://vibecode.bitrix24.tech');

// Собственный ключ ЭТОГО приложения (vibe_app_…), выпущенный в разделе
// «Ключи авторизации» на портале Вайбкод. Это НЕ ключ сервера и НЕ
// сессионный токен пользователя — отдельная сущность, которую платформа
// не проставляет в окружение сама. Обязателен для каждого вызова
// GET/POST /v1/{entity}: X-Api-Key = этот ключ, Authorization: Bearer =
// сессия пользователя из заголовка X-Vibe-Authorization.
// См. https://vibecode.bitrix24.tech/docs/infra/app-runtime
//   → «Приложение как сервис: собственный ключ (X-Api-Key)»
define('VIBE_APP_KEY', (string) vibecode_env('VIBE_APP_KEY', ''));
