<?php
// VibeClient.php
//
// Единая точка обращения к V1 API Вайбкод (BFF-паттерн, раздел 4 памятки
// «Принципы безопасной VibeCode-разработки»). Ничего, кроме этого класса,
// не должно напрямую ходить в API.
//
// Подтверждено документацией (docs/entity-api, docs/infra/app-runtime):
//   - Базовый URL: https://vibecode.bitrix24.tech
//   - Авторизация к сущностям (/v1/deals, /v1/statuses, /v1/users, …):
//       X-Api-Key: <ключ ЭТОГО приложения, vibe_app_…>            (обязателен)
//       Authorization: Bearer <сессия пользователя>               (per-user поток)
//     Оба заголовка обязаны быть согласованы — X-Api-Key должен быть
//     ключом того же приложения, что выписало сессию, иначе платформа
//     отвечает 403 SESSION_APP_MISMATCH.
//   - Список:      GET  /v1/{entity}?limit=&offset=&select=&order[f]=
//   - Поиск:       POST /v1/{entity}/search  { filter, sort, limit, offset }
//   - Агрегация:   POST /v1/{entity}/aggregate  { aggregate, filter, groupBy }
//   - Формат ответа: { success, data, meta } на успехе,
//                     { success:false, error:{ code, message } } на ошибке.

require_once __DIR__ . '/config.php';

final class VibeApiException extends RuntimeException
{
    public string $apiCode;
    public int $status;

    public function __construct(string $apiCode, int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $apiCode);
        $this->apiCode = $apiCode;
        $this->status = $status;
    }
}

final class VibeClient
{
    // Экспоненциальная пауза при 429 (docs/optimization, аналог раздела 5
    // памятки): 1с → 2с → 4с.
    private const RATE_LIMIT_BACKOFF_US = [1_000_000, 2_000_000, 4_000_000];

    private static function request(string $method, string $path, array $query, ?array $jsonBody, string $sessionToken, int $attempt = 0): array
    {
        if (VIBE_APP_KEY === '') {
            // Не секрет, а отсутствие конфигурации — не должно попасть в лог как утечка ключа.
            throw new VibeApiException('MISSING_APP_KEY', 500, 'VIBE_APP_KEY не задан в окружении сервера');
        }

        $url = VIBE_API_BASE . $path;
        if ($query) {
            $url .= (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
        }

        $headers = [
            'X-Api-Key: ' . VIBE_APP_KEY,
            'Authorization: Bearer ' . $sessionToken,
        ];

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ];
        if ($jsonBody !== null) {
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new VibeApiException('NETWORK_ERROR', 502, $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string) $body, true) ?? [];

        if ($status === 429) {
            if ($attempt >= count(self::RATE_LIMIT_BACKOFF_US)) {
                throw new VibeApiException('RATE_LIMITED', 429, 'Превышен лимит запросов, повторные попытки исчерпаны');
            }
            usleep(self::RATE_LIMIT_BACKOFF_US[$attempt]);
            return self::request($method, $path, $query, $jsonBody, $sessionToken, $attempt + 1);
        }
        if ($status === 401) {
            $code = (string) ($decoded['error']['code'] ?? 'UNAUTHENTICATED');
            throw new VibeApiException($code, 401, (string) ($decoded['error']['message'] ?? 'Сессия истекла или недействительна'));
        }
        if ($status === 403) {
            $code = (string) ($decoded['error']['code'] ?? 'ACCESS_DENIED');
            throw new VibeApiException($code, 403, (string) ($decoded['error']['message'] ?? ''));
        }
        if ($status >= 500) {
            throw new VibeApiException('BITRIX_UNAVAILABLE', 502, 'Платформа или портал Битрикс24 временно недоступны');
        }
        if ($status < 200 || $status >= 300) {
            $code = (string) ($decoded['error']['code'] ?? 'UNKNOWN_ERROR');
            throw new VibeApiException($code, $status, (string) ($decoded['error']['message'] ?? ''));
        }
        if (($decoded['success'] ?? true) === false) {
            $code = (string) ($decoded['error']['code'] ?? 'UNKNOWN_ERROR');
            throw new VibeApiException($code, $status, (string) ($decoded['error']['message'] ?? ''));
        }

        return $decoded;
    }

    public static function get(string $entityPath, array $query, string $sessionToken): array
    {
        return self::request('GET', '/v1/' . ltrim($entityPath, '/'), $query, null, $sessionToken);
    }

    public static function search(string $entity, array $body, string $sessionToken): array
    {
        return self::request('POST', "/v1/{$entity}/search", [], $body, $sessionToken);
    }

    public static function aggregate(string $entity, array $body, string $sessionToken): array
    {
        return self::request('POST', "/v1/{$entity}/aggregate", [], $body, $sessionToken);
    }

    // GET /v1/me — с X-Api-Key приложения + Bearer сессии пользователя.
    public static function me(string $sessionToken): array
    {
        return self::request('GET', '/v1/me', [], null, $sessionToken);
    }
}
