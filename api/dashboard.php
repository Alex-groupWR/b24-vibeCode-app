<?php
// api/dashboard.php
//
// Один проход: сводка по стадиям воронки (только основная воронка,
// categoryId=0 — см. известные ограничения в DESCRIPTION.md), KPI,
// последние сделки, фильтр по периоду создания.
//
// Реальные вызовы (docs/entity-api, docs/entities/deals/fields):
//   POST /v1/deals/search   — данные сделок за период, до 5000 записей
//                              за один вызов (платформа сама пагинирует)
//   GET  /v1/statuses?filter[entityId]=DEAL_STAGE — названия стадий
//   POST /v1/users/search   — имена ответственных по списку id

require_once __DIR__ . '/../VibeClient.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=utf-8');

const DEAL_SELECT = ['id', 'title', 'stageId', 'stageSemanticId', 'amount', 'currency', 'closed', 'closedAt', 'createdAt', 'assignedById'];
const MAX_RECORDS = 1000; // защита от избыточной выгрузки — раздел 6, п.3 памятки; потолок платформы — 5000
const RECENT_LIMIT = 15;   // 10–20 последних сделок по заданию

/**
 * Разбирает и валидирует период. Некорректный ввод — не 500, а понятная
 * ошибка 400 (раздел 5, п.6 памятки — «не доверяйте вводу»).
 */
function parsePeriod(array $query): ?array
{
    $to = (isset($query['dateTo']) && $query['dateTo'] !== '') ? strtotime($query['dateTo']) : time();
    $from = (isset($query['dateFrom']) && $query['dateFrom'] !== '')
        ? strtotime($query['dateFrom'])
        : strtotime('-90 days', $to !== false ? $to : time());

    if ($from === false || $to === false || $from > $to) {
        return null;
    }

    return ['from' => date('Y-m-d', $from) . 'T00:00:00', 'to' => date('Y-m-d', $to) . 'T23:59:59'];
}

function fetchDealsForPeriod(string $token, array $period): array
{
    $body = [
        'filter' => [
            'createdAt' => ['$gte' => $period['from'], '$lte' => $period['to']],
        ],
        'select' => DEAL_SELECT,
        'sort' => ['createdAt' => 'desc'],
        'limit' => MAX_RECORDS, // платформа авто-пагинирует внутри одного вызова при limit > 50
        'withTotal' => false,
    ];

    $res = VibeClient::search('deals', $body, $token);
    return $res['data'] ?? [];
}

/**
 * Справочник названий стадий основной воронки. Сбой здесь не критичен и
 * не должен ронять весь дашборд (раздел 5, п.1) — в худшем случае
 * покажем ID стадии как есть.
 */
function fetchStageLabels(string $token): array
{
    try {
        $res = VibeClient::get('statuses', ['filter' => ['entityId' => 'DEAL_STAGE']], $token);
        $map = [];
        foreach ($res['data'] ?? [] as $item) {
            $id = $item['id'] ?? $item['statusId'] ?? null;
            $name = $item['name'] ?? $item['NAME'] ?? null;
            if ($id !== null && $name !== null) {
                $map[$id] = $name;
            }
        }
        return $map;
    } catch (Throwable $e) {
        error_log('Не удалось получить справочник стадий: ' . $e->getMessage());
        return [];
    }
}

function fetchUserNames(string $token, array $userIds): array
{
    $ids = array_values(array_unique(array_filter($userIds)));
    if (!$ids) {
        return [];
    }
    try {
        $res = VibeClient::search('users', [
            'filter' => ['id' => ['$in' => $ids]],
            'select' => ['id', 'name', 'lastName'],
            'limit' => count($ids),
            'withTotal' => false,
        ], $token);
        $map = [];
        foreach ($res['data'] ?? [] as $u) {
            $name = trim(($u['name'] ?? '') . ' ' . ($u['lastName'] ?? ''));
            $map[$u['id']] = $name !== '' ? $name : ('#' . $u['id']);
        }
        return $map;
    } catch (Throwable $e) {
        error_log('Не удалось получить имена ответственных: ' . $e->getMessage());
        return [];
    }
}

try {
    $token = requireSessionToken();

    $period = parsePeriod($_GET);
    if ($period === null) {
        http_response_code(400);
        echo json_encode(['error' => 'BAD_PERIOD', 'message' => 'Некорректный период: проверьте даты dateFrom/dateTo'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $deals = fetchDealsForPeriod($token, $period);
    $stageLabels = fetchStageLabels($token);

    // Пустые данные — нормальный сценарий, не ошибка (раздел 5, п.1)
    if (!$deals) {
        echo json_encode([
            'period' => $period,
            'stageSummary' => [],
            'kpis' => ['openAmount' => 0, 'wonCount' => 0, 'avgCheck' => 0, 'currency' => null],
            'recentDeals' => [],
            'empty' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $currency = $deals[0]['currency'] ?? null;

    // --- Сводка по стадиям воронки: количество и сумма по каждому stageId ---
    $stageMap = [];
    foreach ($deals as $d) {
        $stageId = $d['stageId'] ?? 'UNKNOWN';
        if (!isset($stageMap[$stageId])) {
            $stageMap[$stageId] = [
                'stageId' => $stageId,
                'label' => $stageLabels[$stageId] ?? $stageId,
                'count' => 0,
                'amount' => 0.0,
            ];
        }
        $stageMap[$stageId]['count'] += 1;
        $stageMap[$stageId]['amount'] += (float) ($d['amount'] ?? 0);
    }
    $stageSummary = array_values($stageMap);
    usort($stageSummary, static fn($a, $b) => $b['amount'] <=> $a['amount']);

    // --- KPI: сумма открытых, число выигранных за период, средний чек ---
    // stageSemanticId: 'P' — в работе, 'S' — успех, 'F' — провал (docs/entities/deals/fields)
    $openDeals = array_filter($deals, static fn($d) => ($d['closed'] ?? false) !== true);
    $wonDeals = array_filter($deals, static fn($d) => ($d['stageSemanticId'] ?? '') === 'S');

    $openAmount = array_sum(array_map(static fn($d) => (float) ($d['amount'] ?? 0), $openDeals));
    $wonAmount = array_sum(array_map(static fn($d) => (float) ($d['amount'] ?? 0), $wonDeals));
    $wonCount = count($wonDeals);
    $avgCheck = $wonCount > 0 ? round($wonAmount / $wonCount) : 0;

    // --- Последние 15 сделок: название, сумма, стадия, ответственный ---
    $recentRaw = array_slice($deals, 0, RECENT_LIMIT);
    $userNames = fetchUserNames($token, array_map(static fn($d) => $d['assignedById'] ?? null, $recentRaw));

    $recentDeals = array_map(static function ($d) use ($stageLabels, $userNames) {
        return [
            'id' => $d['id'],
            'title' => ($d['title'] ?? '') !== '' ? $d['title'] : ('Сделка #' . $d['id']),
            'amount' => (float) ($d['amount'] ?? 0),
            'stage' => $stageLabels[$d['stageId']] ?? $d['stageId'],
            'assignedTo' => $userNames[$d['assignedById']] ?? '—',
            'dateCreate' => $d['createdAt'] ?? null,
        ];
    }, $recentRaw);

    echo json_encode([
        'period' => $period,
        'stageSummary' => $stageSummary,
        'kpis' => ['openAmount' => $openAmount, 'wonCount' => $wonCount, 'avgCheck' => $avgCheck, 'currency' => $currency],
        'recentDeals' => array_values($recentDeals),
        'empty' => false,
    ], JSON_UNESCAPED_UNICODE);
} catch (VibeApiException $e) {
    // Недостаток прав / нет данных — предсказуемый ответ, а не техтрасса (раздел 5, п.5)
    http_response_code($e->status);
    echo json_encode(['error' => $e->apiCode, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'INTERNAL_ERROR', 'message' => 'Внутренняя ошибка приложения'], JSON_UNESCAPED_UNICODE);
}
