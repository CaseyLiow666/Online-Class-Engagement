<?php
declare(strict_types=1);

/**
 * Reusable live-search bar matching the Admin Users panel (users-search-form).
 *
 * Expected: $reportSearchColumns — comma-separated td column indexes (e.g. "1,2")
 */
$reportSearchColumns = $reportSearchColumns ?? '1,2';
?>
<form class="users-search-form report-search-form no-print" data-report-search-form role="search">
    <div class="users-search">
        <span class="users-search__icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" fill="currentColor"/>
            </svg>
        </span>
        <input type="search"
               class="users-search__input"
               data-report-search
               data-search-columns="<?= htmlspecialchars($reportSearchColumns, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="Search by name or username…"
               autocomplete="off"
               aria-label="Search by name or username">
    </div>
    <button type="submit" class="btn btn-primary users-search__btn" data-report-search-btn>Search</button>
</form>
