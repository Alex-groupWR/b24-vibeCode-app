#!/usr/bin/env bash

# Пример запуска:
#   export VIBE_KEY="vibe_api_..."
#   export SERVER_ID="22ee2bfa-fad4-4491-9dc7-97c5ea37a8aa"
#   export VIBE_APP_KEY="vibe_api_..."
#   ./deploy.sh

set -euo pipefail

PROJECT_DIR="${1:-.}"
ARCHIVE="/tmp/deal-funnel-dashboard-deploy.tar.gz"
DEPLOY_URL="https://vibecode.bitrix24.tech/v1/infra/servers/${SERVER_ID:-}/deploy"

# --- проверки перед стартом -------------------------------------------------

for cmd in curl tar jq base64; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "Не найдена команда '$cmd' — установите её (Git Bash: обычно есть все, кроме jq — поставьте через 'choco install jq' или скачайте с https://jqlang.org)." >&2
    exit 1
  fi
done

: "${VIBE_KEY:?Переменная VIBE_KEY не задана. export VIBE_KEY=\"vibe_api_...\" (ключ в режиме READ+WRITE)}"
: "${SERVER_ID:?Переменная SERVER_ID не задана. export SERVER_ID=\"...\"}"
: "${VIBE_APP_KEY:?Переменная VIBE_APP_KEY не задана. export VIBE_APP_KEY=\"vibe_app_...\" (ключ самого приложения)}"

if [ ! -f "$PROJECT_DIR/router.php" ]; then
  echo "Не вижу router.php в '$PROJECT_DIR' — укажите путь к проекту первым аргументом." >&2
  exit 1
fi

# --- сборка архива ------------------------------------------------------------

echo "Собираю архив кода из '$PROJECT_DIR'…"
tar -czf "$ARCHIVE" \
  --exclude=".env" \
  --exclude=".git" \
  --exclude="*.log" \
  -C "$PROJECT_DIR" .

ARCHIVE_SIZE=$(wc -c < "$ARCHIVE" | tr -d ' ')
echo "Архив готов: $ARCHIVE ($ARCHIVE_SIZE байт)"

if [ "$ARCHIVE_SIZE" -gt 75000000 ]; then
  echo "Внимание: архив больше ~72 МБ — inline-деплой (source.content) его не примет (413 INLINE_SOURCE_TOO_LARGE)." >&2
  echo "Загрузите его как source.url или через POST /v1/infra/servers/:id/sources — этот скрипт такой путь не реализует." >&2
  exit 1
fi

B64=$(base64 < "$ARCHIVE" | tr -d '\n')

# --- сборка тела запроса (jq сериализует UTF-8 корректно, в отличие от
#     ручной конкатенации строк) ------------------------------------------

BODY=$(jq -n \
  --arg content "$B64" \
  --arg appKey "$VIBE_APP_KEY" \
  '{
    source: { content: $content },
    runtime: "php83",
    start: "cd /opt/app && php -S 0.0.0.0:3000 -t public router.php",
    port: 3000,
    env: { VIBE_APP_KEY: $appKey },
    displayName: "Дашборд продаж",
    description: "Сводка по воронке, KPI и последние сделки CRM"
  }')

# --- сам деплой ---------------------------------------------------------------
# --max-time 690: платформа удерживает соединение до 660с на деплое,
# плюс до ~6.5 мин, если сервер спал — таймаут клиента должен быть строго
# больше, иначе оборвём HTTP-соединение раньше, чем платформа закончит
# (а деплой на сервере при этом продолжится — повторный запуск нельзя
# запускать вслепую, см. README проекта).

echo "Отправляю деплой на сервер $SERVER_ID (может занять несколько минут)…"

RESPONSE=$(curl -sS -X POST "$DEPLOY_URL" \
  -H "X-Api-Key: $VIBE_KEY" \
  -H "Content-Type: application/json" \
  --max-time 690 \
  -d "$BODY")

rm -f "$ARCHIVE"

SUCCESS=$(echo "$RESPONSE" | jq -r '.success')

if [ "$SUCCESS" = "true" ]; then
  APP_URL=$(echo "$RESPONSE" | jq -r '.data.appUrl')
  echo "Готово. Приложение живёт: $APP_URL"
  WARNINGS=$(echo "$RESPONSE" | jq -r '.warnings // [] | .[]' 2>/dev/null || true)
  if [ -n "$WARNINGS" ]; then
    echo "Предупреждения платформы:"
    echo "$WARNINGS" | sed 's/^/  - /'
  fi
else
  STEP=$(echo "$RESPONSE" | jq -r '.error.step // "неизвестен"')
  CODE=$(echo "$RESPONSE" | jq -r '.error.code // "неизвестен"')
  MSG=$(echo "$RESPONSE" | jq -r '.error.message // "без сообщения"')
  echo "Деплой не завершился успешно." >&2
  echo "  Код:  $CODE" >&2
  echo "  Шаг:  $STEP" >&2
  echo "  Текст: $MSG" >&2
  echo "" >&2
  echo "Полный ответ платформы:" >&2
  echo "$RESPONSE" | jq . >&2
  exit 1
fi
