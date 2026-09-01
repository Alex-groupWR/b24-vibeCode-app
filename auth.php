<?php
// auth.php
//
// Gateway аутентифицирует пользователя САМ и на каждый проксируемый
// запрос кладёт заголовок X-Vibe-Authorization: Bearer vibe_session_<…>
// (см. docs/infra/app-runtime, «Что приходит в каждый запрос»).
// Любой входящий X-Vibe-Authorization от клиента Gateway срезает до
// проброса — подделать этот заголовок снаружи нельзя. Поэтому серверу
// достаточно довериться значению, которое он видит здесь.

function requireSessionToken(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $header = $headers['X-Vibe-Authorization']
        ?? $headers['x-vibe-authorization']
        ?? ($_SERVER['HTTP_X_VIBE_AUTHORIZATION'] ?? null);

    if (!$header || stripos($header, 'Bearer ') !== 0) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'NO_SESSION',
            'message' => 'Запрос пришёл не через Gateway или сессия отсутствует',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return substr($header, 7);
}
