// assets/js/sortable-table.js - Click-to-sort table columns (replaces DataTables)
//
//   const table = sortableTable('#main-table', { column: 0 });
//   tbody.append(row); table.refresh();   // re-apply the current order
//
// Every <th> in the <thead> sorts its column, unless it has
// data-sortable="false". A cell sorts by its data-order attribute if it has
// one, else by its text. A column whose non-empty keys are all numbers sorts
// numerically; any other sorts as text (natural order: "file2" < "file10").
// Empty keys sort first in ascending order. Ties keep their current order.
// The sorted header carries aria-sort; the header text is wrapped in a
// <button> so sorting works from the keyboard.

const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

function sortKey(row, column) {
    const cell = row.cells[column];
    if (!cell) return '';
    return (cell.dataset.order ?? cell.textContent).trim();
}

const isNumber = key => key !== '' && Number.isFinite(Number(key));

export function sortableTable(target, { column = 0, direction = 'ascending' } = {}) {
    const table = typeof target === 'string' ? document.querySelector(target) : target;
    if (!table) return null;

    const headers = [...(table.tHead?.rows[0]?.cells ?? [])];
    let current = { column, direction };

    function sort(newColumn = current.column, newDirection = current.direction) {
        current = { column: newColumn, direction: newDirection };
        const body = table.tBodies[0];
        if (!body) return;

        const rows = [...body.rows];
        const keys = new Map(rows.map(row => [row, sortKey(row, newColumn)]));
        const numeric = [...keys.values()].every(key => key === '' || isNumber(key));
        const sign = newDirection === 'descending' ? -1 : 1;

        rows.sort((a, b) => {
            const ka = keys.get(a), kb = keys.get(b);
            if (ka === '' || kb === '') return sign * ((ka === '' ? 0 : 1) - (kb === '' ? 0 : 1));
            return sign * (numeric ? Number(ka) - Number(kb) : collator.compare(ka, kb));
        });
        body.append(...rows); // moves the rows, in order

        headers.forEach((header, index) => {
            if (index === newColumn) {
                header.setAttribute('aria-sort', newDirection);
            } else {
                header.removeAttribute('aria-sort');
            }
        });
    }

    table.classList.add('sortable');
    headers.forEach((header, index) => {
        if (header.dataset.sortable === 'false') return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'sort-button';
        button.append(...header.childNodes);
        header.append(button);
        button.addEventListener('click', () => {
            const direction = current.column === index && current.direction === 'ascending' ? 'descending' : 'ascending';
            sort(index, direction);
        });
    });

    sort();
    return {
        sort,
        refresh: () => sort(),
        get column() { return current.column; },
        get direction() { return current.direction; },
    };
}
