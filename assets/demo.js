const demos = {
    informatics: ['Інформатика', 'Який пристрій зберігає файли?', ['SSD', 'Монітор', 'Миша'], 0, 'Поясни різницю між файлом і папкою.'],
    database: ['Бази даних і СУБД', 'Що таке база даних?', ['Текстовий редактор', 'Організована сукупність даних', 'Мова програмування'], 1, 'Для чого використовують СУБД?'],
    algorithms: ['Алгоритми', 'Яка конструкція повторює команди?', ['Умова', 'Коментар', 'Цикл'], 2, 'Наведи приклад алгоритму з повсякденного життя.'],
    math: ['Лінійні рівняння', 'Знайди x, якщо 2x + 4 = 10.', ['2', '3', '7'], 1, 'Поясни, як ти розв’язав рівняння.'],
};
const grade = document.querySelector('#grade');
const subject = document.querySelector('#subject');
const cards = [...document.querySelectorAll('.card')];
const quiz = document.querySelector('#quiz-form');
const result = document.querySelector('#result-message');
let activeDemo;

function filterTests() {
    for (const card of cards) {
        card.hidden = Boolean((grade.value && card.dataset.grade !== grade.value)
            || (subject.value && card.dataset.subject !== subject.value));
    }
    document.querySelector('#empty').hidden = cards.some(card => !card.hidden);
}
grade.addEventListener('change', filterTests);
subject.addEventListener('change', filterTests);

for (const button of document.querySelectorAll('[data-test]')) {
    button.addEventListener('click', () => {
        activeDemo = demos[button.dataset.test];
        const [title, question, options, , written] = activeDemo;
        document.querySelector('#quiz-title').textContent = title;
        document.querySelector('#quiz-intro').textContent = 'Демонстраційний тест · 2 запитання';
        document.querySelector('#questions').innerHTML = `
            <fieldset><legend>1. ${question}</legend>
            ${options.map((option, index) => `<label><input required type="radio" name="option" value="${index}"> ${option}</label>`).join('')}
            </fieldset>
            <fieldset><legend>2. ${written}</legend>
            <textarea required aria-label="Розгорнута відповідь" placeholder="Введи свою відповідь"></textarea></fieldset>`;
        result.textContent = 'Тут з’явиться результат демонстраційної спроби.';
        quiz.hidden = false;
        quiz.querySelector('input').focus();
    });
}
quiz.addEventListener('submit', event => {
    event.preventDefault();
    const chosen = Number(new FormData(quiz).get('option'));
    const points = Number(chosen === activeDemo[3]);
    result.textContent = `Автоматична частина · ${points} із 1. Розгорнута відповідь потребує перевірки вчителем.`;
    document.querySelector('#result').scrollIntoView({block: 'nearest'});
});
