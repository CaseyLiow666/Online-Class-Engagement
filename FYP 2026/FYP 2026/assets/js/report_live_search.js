(function () {
    'use strict';

    function initReportLiveSearch() {
        document.querySelectorAll('[data-report-search-form]').forEach(function (form) {
            const input = form.querySelector('[data-report-search]');
            const searchBtn = form.querySelector('[data-report-search-btn]');
            const block = form.closest('.report-table-block');
            if (!input || !block) {
                return;
            }

            const table = block.querySelector('[data-report-search-table]');
            const emptyMsg = block.querySelector('[data-report-search-empty]');
            if (!table) {
                return;
            }
            const tbody = table.querySelector('tbody');
            if (!tbody) {
                return;
            }

            const colIndexes = (input.getAttribute('data-search-columns') || '')
                .split(',')
                .map(function (v) { return parseInt(v.trim(), 10); })
                .filter(function (n) { return !isNaN(n); });

            function filterRows() {
                const query = input.value.trim().toLowerCase();
                const rows = tbody.querySelectorAll('tr');
                let visible = 0;

                rows.forEach(function (row) {
                    let haystack = (row.getAttribute('data-search') || '').toLowerCase();

                    if (colIndexes.length > 0) {
                        const cells = row.querySelectorAll('td');
                        const parts = colIndexes.map(function (i) {
                            return cells[i] ? cells[i].textContent.trim() : '';
                        });
                        haystack = parts.join(' ').toLowerCase();
                    }

                    const match = query === '' || haystack.indexOf(query) !== -1;
                    row.style.display = match ? '' : 'none';
                    if (match) {
                        visible++;
                    }
                });

                if (emptyMsg) {
                    emptyMsg.hidden = visible > 0 || rows.length === 0;
                }
            }

            input.addEventListener('input', filterRows);
            input.addEventListener('keyup', filterRows);

            if (searchBtn) {
                searchBtn.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    filterRows();
                });
            }

            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                filterRows();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', initReportLiveSearch);
})();
