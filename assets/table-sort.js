const tableCollator = new Intl.Collator('uk', {numeric: true, sensitivity: 'base'});

function tableSortValue(cell) {
    const text = (cell.dataset.sortValue ?? cell.textContent).trim().replace(/^✎\s*/, '');
    if (!text || text === '—' || text === 'Без класу') return null;
    const number = text.match(/^(-?\d+(?:[.,]\d+)?)(?:\s*\/\s*\d+(?:[.,]\d+)?)?$/);
    return number ? Number(number[1].replace(',', '.')) : text;
}

for (const table of document.querySelectorAll('table[data-sortable]')) {
    const headers = [...table.tHead.rows[0].cells];
    const body = table.tBodies[0];
    const originalRows = [...body.rows];
    const stateKey = 'tableSort:' + table.dataset.sortable;
    let activeIndex = -1;
    let direction = 1;

    function sortRows(index, order) {
        activeIndex = index;
        direction = order;
        const rows = originalRows.map(row => ({row, value: tableSortValue(row.cells[index])}));
        rows.sort((a, b) => {
            if (a.value === null) return b.value === null ? 0 : 1;
            if (b.value === null) return -1;
            const comparison = typeof a.value === 'number' && typeof b.value === 'number'
                ? a.value - b.value : tableCollator.compare(String(a.value), String(b.value));
            return comparison * order;
        });
        body.append(...rows.map(({row}) => row));
        for (const [column, header] of headers.entries()) {
            const indicator = header.querySelector('.sort-indicator');
            if (!indicator) continue;
            if (column === index) {
                header.setAttribute('aria-sort', order === 1 ? 'ascending' : 'descending');
                indicator.textContent = order === 1 ? '↑' : '↓';
            } else {
                header.removeAttribute('aria-sort');
                indicator.textContent = '↕';
            }
        }
    }

    for (const [index, header] of headers.entries()) {
        if (header.hasAttribute('data-no-sort')) continue;
        const button = document.createElement('button');
        const indicator = document.createElement('span');
        button.type = 'button';
        button.className = 'result-sort table-sort-button';
        indicator.className = 'sort-indicator';
        indicator.setAttribute('aria-hidden', 'true');
        indicator.textContent = '↕';
        button.append(header.textContent.trim(), indicator);
        header.replaceChildren(button);
        button.addEventListener('click', () => {
            sortRows(index, activeIndex === index ? -direction : 1);
            history.replaceState({...history.state, [stateKey]: [activeIndex, direction]}, '');
        });
    }
    const saved = history.state?.[stateKey];
    if (saved && headers[saved[0]]?.querySelector('button') && [1, -1].includes(saved[1])) {
        sortRows(...saved);
    }
}
