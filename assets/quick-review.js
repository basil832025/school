const quickDialog = document.querySelector('#quick-review-dialog');
const quickContent = document.querySelector('#quick-review-content');
let quickRequest;

function updateScoreButtons(input) {
    for (const button of input.closest('.quick-score').querySelectorAll('[data-score]')) {
        button.setAttribute('aria-pressed', String(input.value !== '' && Number(input.value) === Number(button.dataset.score)));
    }
}

function showQuickReview(html) {
    const page = new DOMParser().parseFromString(html, 'text/html');
    const review = page.querySelector('[data-quick-review]');
    if (!review) throw new Error('Review unavailable');
    quickContent.replaceChildren(review);
    quickContent.querySelectorAll('.quick-score input').forEach(updateScoreButtons);
}

document.addEventListener('click', async event => {
    const score = event.target.closest('[data-score]');
    if (score) {
        const input = score.closest('.quick-score').querySelector('input');
        input.value = score.dataset.score;
        input.dispatchEvent(new Event('input', {bubbles: true}));
        return;
    }
    const link = event.target.closest('[data-quick-link]');
    if (!link || !quickDialog?.showModal || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    quickRequest?.abort();
    const request = new AbortController();
    quickRequest = request;
    quickContent.textContent = 'Завантажуємо відповіді…';
    quickDialog.showModal();
    try {
        const response = await fetch(link.href, {cache: 'no-store', signal: request.signal});
        showQuickReview(await response.text());
    } catch (error) {
        if (error.name !== 'AbortError') quickContent.textContent = 'Не вдалося завантажити відповіді. Відкрий перевірку ще раз.';
    }
});

document.addEventListener('input', event => {
    if (event.target.matches('.quick-score input')) updateScoreButtons(event.target);
});

document.querySelectorAll('.quick-score input').forEach(updateScoreButtons);
quickDialog?.addEventListener('close', () => quickRequest?.abort());

quickDialog?.addEventListener('submit', async event => {
    const form = event.target;
    if (!form.matches('[data-quick-form]')) return;
    event.preventDefault();
    const save = form.querySelector('button:not([type])');
    const data = new FormData(form);
    data.set('dialog', '1');
    save.disabled = true;
    try {
        const response = await fetch(form.action, {method: 'POST', body: data});
        if (response.status === 204) {
            quickDialog.close();
            document.dispatchEvent(new Event('quick-review-saved'));
        } else if (quickDialog.open) {
            showQuickReview(await response.text());
        }
    } catch {
        if (quickDialog.open) {
            const message = document.createElement('p');
            message.className = 'alert error';
            message.setAttribute('role', 'alert');
            message.textContent = 'Не вдалося зберегти бали. Спробуй ще раз.';
            form.prepend(message);
        }
    } finally {
        save.disabled = false;
    }
});
