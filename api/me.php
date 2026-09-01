<?php
// api/me.php — проксирует GET /v1/me для самопроверки (раздел 7, п.5
// памятки): фактические скоупы, тип ключа, портал, capabilities.

require_once __DIR__ . '/../VibeClient.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=utf-8');

$token = requireSessionToken();

try {
    echo json_encode(VibeClient::me($token), JSON_UNESCAPED_UNICODE);
} catch (VibeApiException $e) {
    http_response_code($e->status);
    echo json_encode(['error' => $e->apiCode, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'INTERNAL_ERROR', 'message' => 'Внутренняя ошибка приложения'], JSON_UNESCAPED_UNICODE);
}
