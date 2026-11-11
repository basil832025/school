const questionType = document.querySelector('#question-type');
const optionRows = document.querySelector('#option-rows');
const addOption = document.querySelector('#add-option');

function updateOptions() {
    const written = questionType.value === 'written';
    document.querySelector('#answer-options').hidden = written;
    for (const input of optionRows.querySelectorAll('input')) {
        input.disabled = written;
    }
}

if (questionType) {
    questionType.addEventListener('change', updateOptions);
    updateOptions();
    addOption.addEventListener('click', () => {
        const index = optionRows.children.length;
        if (index >= 30) return;
        const row = document.createElement('div');
        row.className = 'option-row';
        row.innerHTML = `<input type="text" name="options[${index}]" maxlength="2000"
            aria-label="Варіант ${index + 1}" placeholder="Варіант ${index + 1}">
            <label><input type="checkbox" name="correct[]" value="${index}"> Правильна</label>`;
        optionRows.append(row);
        row.querySelector('input').focus();
        addOption.disabled = index === 29;
    });
}
