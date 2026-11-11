const questionType = document.querySelector('#question-type');
const optionRows = document.querySelector('#option-rows');
const addOption = document.querySelector('#add-option');

function updateOptions() {
    const written = questionType.value === 'written';
    document.querySelector('#answer-options').hidden = written;
    for (const input of optionRows.querySelectorAll('input, select')) {
        input.disabled = written;
    }
}

if (questionType) {
    questionType.addEventListener('change', updateOptions);
    updateOptions();
    addOption.addEventListener('click', () => {
        const index = optionRows.children.length;
        if (index >= 30) return;
        const row = optionRows.firstElementChild.cloneNode(true);
        const label = row.querySelector('input[type="text"]');
        label.value = '';
        label.placeholder = `Варіант ${index + 1}`;
        label.setAttribute('aria-label', label.placeholder);
        const correct = row.querySelector('input[type="checkbox"]');
        correct.value = index;
        correct.checked = false;
        row.querySelector('details').open = false;
        row.querySelector('input[type="url"]').value = '';
        row.querySelector('select').value = 'medium';
        for (const input of row.querySelectorAll('[name]')) {
            input.name = input.name.replace(/\[\d+\]/, `[${index}]`);
        }
        optionRows.append(row);
        row.querySelector('input').focus();
        addOption.disabled = index === 29;
    });
}
