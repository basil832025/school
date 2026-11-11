const localizedFields = new Set();

function validationMessage(field) {
    const state = field.validity;
    if (state.valueMissing) {
        if (field.type === 'radio') return 'Обери один із варіантів відповіді.';
        if (field.type === 'checkbox') return 'Познач цей пункт.';
        if (field.type === 'file') return 'Вибери файл.';
        if (field.tagName === 'SELECT') return 'Обери варіант зі списку.';
        return 'Заповни це поле.';
    }
    if (state.badInput) return 'Введи число.';
    if (state.typeMismatch) {
        if (field.type === 'email') return 'Введи коректну адресу електронної пошти.';
        if (field.type === 'url') return 'Введи коректну вебадресу.';
        return 'Перевір формат введених даних.';
    }
    if (state.patternMismatch) {
        if (field.name === 'birth_year') return 'Введи рік народження чотирма цифрами.';
        return 'Введи значення в указаному форматі.';
    }
    if (state.tooShort) return 'Введи щонайменше стільки символів: ' + field.minLength + '.';
    if (state.tooLong) return 'Скороти значення. Максимум символів: ' + field.maxLength + '.';
    if (state.rangeUnderflow) return 'Мінімальне значення — ' + field.min + '.';
    if (state.rangeOverflow) return 'Максимальне значення — ' + field.max + '.';
    if (state.stepMismatch) return 'Введи число з допустимим кроком: ' + (field.step || '1') + '.';
    return 'Перевір значення в цьому полі.';
}

document.addEventListener('invalid', event => {
    const field = event.target;
    if (localizedFields.has(field)) field.setCustomValidity('');
    if (field.validity.valid || field.validity.customError) return;
    field.setCustomValidity(validationMessage(field));
    localizedFields.add(field);
}, true);

function clearLocalizedMessages(event) {
    const changed = event.target;
    for (const field of localizedFields) {
        if (field === changed || field.form === changed
            || (changed.type === 'radio' && field.type === 'radio'
                && field.form === changed.form && field.name === changed.name)) {
            field.setCustomValidity('');
            localizedFields.delete(field);
        }
    }
}

document.addEventListener('input', clearLocalizedMessages);
document.addEventListener('change', clearLocalizedMessages);
document.addEventListener('reset', clearLocalizedMessages);
