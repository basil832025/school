const results = document.querySelector('#live-results');
const status = document.querySelector('#live-refresh-status');
const normalStatus = status.textContent;

async function refresh() {
    try {
        const response = await fetch(location.href, {cache: 'no-store'});
        if (!response.ok) throw new Error('Refresh failed');
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const updated = page.querySelector('#live-results');
        if (!updated) throw new Error('Results unavailable');
        results.replaceChildren(...updated.childNodes);
        status.textContent = normalStatus;
    } catch {
        status.textContent = 'Не вдалося оновити таблицю. Повторюємо спробу.';
    } finally {
        setTimeout(refresh, 5000);
    }
}

setTimeout(refresh, 5000);
