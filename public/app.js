// app.js
//
// Фронтенд ходит ТОЛЬКО к своему бэкенду (/api/...). Никаких ключей,
// токенов и прямых обращений к Vibe API здесь нет и быть не должно
// (раздел 4, п.2–3 памятки). Идентификация пользователя целиком на
// стороне Gateway → сервера приложения.

const stateMessage = document.getElementById('stateMessage');
const kpiSection = document.getElementById('kpiSection');
const funnelSection = document.getElementById('funnelSection');
const recentSection = document.getElementById('recentSection');

function showMessage(text) {
  stateMessage.textContent = text;
  stateMessage.hidden = false;
  kpiSection.hidden = true;
  funnelSection.hidden = true;
  recentSection.hidden = true;
}

function hideMessage() {
  stateMessage.hidden = true;
}

function formatMoney(amount, currency) {
  const n = Number(amount) || 0;
  return `${n.toLocaleString('ru-RU')} ${currency || ''}`.trim();
}

function formatDate(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '—';
  return d.toLocaleDateString('ru-RU');
}

async function loadDashboard(params = {}) {
  showMessage('Загрузка данных…');

  const query = new URLSearchParams(params).toString();
  let res;
  try {
    res = await fetch(`/api/dashboard${query ? `?${query}` : ''}`);
  } catch (e) {
    showMessage('Не удалось связаться с сервером приложения. Проверьте соединение и попробуйте ещё раз.');
    return;
  }

  if (res.status === 401) {
    showMessage('Сессия истекла. Обновите страницу — потребуется повторная авторизация.');
    return;
  }
  if (res.status === 403) {
    const body = await safeJson(res);
    if (body?.error === 'BITRIX_ACCESS_DENIED') {
      showMessage('Недостаточно прав для просмотра данных CRM на этом портале.');
    } else {
      showMessage('Доступ ограничен: ключ приложения работает в режиме «только чтение» или не имеет нужного скоупа.');
    }
    return;
  }
  if (res.status === 429) {
    showMessage('Превышен лимит запросов к API. Попробуйте обновить страницу через минуту.');
    return;
  }
  if (res.status === 502) {
    showMessage('Портал Битрикс24 временно недоступен. Попробуйте позже.');
    return;
  }
  if (res.status === 400) {
    showMessage('Проверьте выбранный период — дата начала не может быть позже даты окончания.');
    return;
  }
  if (!res.ok) {
    showMessage('Не удалось загрузить данные дашборда.');
    return;
  }

  const data = await res.json();

  if (data.empty) {
    showMessage('За выбранный период сделок не найдено. Попробуйте расширить период.');
    return;
  }

  hideMessage();
  renderKpis(data.kpis);
  renderFunnel(data.stageSummary);
  renderRecent(data.recentDeals);
  kpiSection.hidden = false;
  funnelSection.hidden = false;
  recentSection.hidden = false;
}

function renderKpis(kpis) {
  document.getElementById('kpiOpenAmount').textContent = formatMoney(kpis.openAmount, kpis.currency);
  document.getElementById('kpiWonCount').textContent = kpis.wonCount;
  document.getElementById('kpiAvgCheck').textContent = formatMoney(kpis.avgCheck, kpis.currency);
}

function renderFunnel(stages) {
  const container = document.getElementById('funnelChart');
  container.innerHTML = '';
  if (!stages.length) return;
  const maxAmount = Math.max(...stages.map((s) => s.amount), 1);

  for (const stage of stages) {
    const row = document.createElement('div');
    row.className = 'funnel-row';

    const label = document.createElement('div');
    label.className = 'funnel-label';
    label.textContent = `${stage.label} (${stage.count})`;

    const barWrap = document.createElement('div');
    barWrap.className = 'funnel-bar-wrap';
    const bar = document.createElement('div');
    bar.className = 'funnel-bar';
    bar.style.width = `${Math.max((stage.amount / maxAmount) * 100, 2)}%`;
    bar.textContent = formatMoney(stage.amount, '');
    barWrap.appendChild(bar);

    row.appendChild(label);
    row.appendChild(barWrap);
    container.appendChild(row);
  }
}

function renderRecent(deals) {
  const tbody = document.querySelector('#recentTable tbody');
  tbody.innerHTML = '';
  for (const d of deals) {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${escapeHtml(d.title)}</td>
      <td>${formatMoney(d.amount, '')}</td>
      <td>${escapeHtml(d.stage)}</td>
      <td>${escapeHtml(d.assignedTo)}</td>
      <td>${formatDate(d.dateCreate)}</td>
    `;
    tbody.appendChild(tr);
  }
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

async function safeJson(res) {
  try {
    return await res.json();
  } catch {
    return null;
  }
}

document.getElementById('periodForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const dateFrom = document.getElementById('dateFrom').value;
  const dateTo = document.getElementById('dateTo').value;
  loadDashboard({ dateFrom, dateTo });
});

loadDashboard();
