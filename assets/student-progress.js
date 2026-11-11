const quiz = document.querySelector('#live-quiz');
if (quiz) {
    const status = document.querySelector('#progress-status');
    const pending = new Set();
    let saving = false;
    let submitted = false;
    let timer;

    async function save() {
        if (saving || submitted || !pending.size) return;
        saving = true;
        for (const field of pending) {
            if (submitted) break;
            pending.delete(field);
            const data = new URLSearchParams({
                action: 'save_progress',
                attempt_token: quiz.elements.attempt_token.value,
                question_id: field.dataset.question,
            });
            for (const input of field.querySelectorAll('input, textarea')) {
                if (input.matches('textarea, :checked')) data.append(input.name, input.value);
            }
            try {
                const response = await fetch(location.href, {method: 'POST', body: data});
                if (response.status === 401 || response.status === 409) {
                    status.textContent = 'Спроба більше недоступна або сеанс завершився. Перевір свій кабінет.';
                    pending.clear();
                    break;
                }
                if (response.status !== 204) throw new Error('Save failed');
                status.textContent = 'Відповіді збережено.';
            } catch {
                pending.add(field);
                status.textContent = 'Очікуємо з’єднання, щоб зберегти відповіді.';
                clearTimeout(timer);
                timer = setTimeout(save, 4000);
                break;
            }
        }
        saving = false;
    }

    quiz.addEventListener('input', event => {
        const field = event.target.closest('[data-question]');
        if (!field) return;
        pending.add(field);
        clearTimeout(timer);
        timer = setTimeout(save, event.target.matches('textarea') ? 500 : 0);
    });
    quiz.addEventListener('submit', () => {
        submitted = true;
        clearTimeout(timer);
    });
}
