<?php
/**
 * Pagination Helper - Reusable server-side pagination utilities
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Static helper class for extracting pagination parameters from requests,
 * computing page metadata (offsets, totals, etc.), and rendering pagination
 * controls. Used across vendor-srs-list, cyber-todo, vendor-onboarding-list,
 * and fourth-party-risk pages.
 */
class Pagination
{
    /**
     * Extract and validate pagination/sort parameters from $_GET.
     *
     * @param array $defaults Default values:
     *   'per_page' => int (default 25)
     *   'per_page_options' => int[] (default [25, 50, 100])
     *   'sort_column' => string (default 'id')
     *   'sort_dir' => string (default 'ASC')
     *   'valid_sort_columns' => string[] (whitelist for SQL injection prevention)
     * @return array ['page', 'per_page', 'sort_column', 'sort_dir']
     */
    public static function getParams(array $defaults = []): array
    {
        $prefix = $defaults['prefix'] ?? '';
        $perPageOptions = $defaults['per_page_options'] ?? [25, 50, 100];
        $defaultPerPage = $defaults['per_page'] ?? 25;
        $defaultSort = $defaults['sort_column'] ?? 'id';
        $defaultDir = $defaults['sort_dir'] ?? 'ASC';
        $validColumns = $defaults['valid_sort_columns'] ?? [];

        $page = max(1, intval($_GET[$prefix . 'page'] ?? 1));
        $perPage = intval($_GET[$prefix . 'per_page'] ?? $defaultPerPage);
        if (!in_array($perPage, $perPageOptions)) {
            $perPage = $defaultPerPage;
        }

        $sortColumn = $_GET[$prefix . 'sort'] ?? $defaultSort;
        if (!empty($validColumns) && !in_array($sortColumn, $validColumns)) {
            $sortColumn = $defaultSort;
        }

        $sortDir = strtoupper($_GET[$prefix . 'order'] ?? $defaultDir);
        if ($sortDir !== 'ASC' && $sortDir !== 'DESC') {
            $sortDir = $defaultDir;
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'sort_column' => $sortColumn,
            'sort_dir' => $sortDir,
            'prefix' => $prefix,
        ];
    }

    /**
     * Compute pagination metadata from a total row count.
     *
     * @param int $totalRows Total matching rows
     * @param int $perPage Items per page
     * @param int $currentPage Requested page number
     * @return array [
     *   'total_rows', 'total_pages', 'current_page', 'per_page',
     *   'offset', 'has_prev', 'has_next', 'start_row', 'end_row'
     * ]
     */
    public static function paginate(int $totalRows, int $perPage, int $currentPage): array
    {
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $currentPage = max(1, $currentPage);
        $offset = ($currentPage - 1) * $perPage;
        $startRow = $totalRows > 0 ? $offset + 1 : 0;
        $endRow = min($offset + $perPage, $totalRows);

        return [
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'current_page' => $currentPage,
            'per_page' => $perPage,
            'offset' => $offset,
            'has_prev' => $currentPage > 1,
            'has_next' => $currentPage < $totalPages,
            'start_row' => $startRow,
            'end_row' => $endRow,
        ];
    }

    /**
     * Render HTML pagination controls: info text, page links, and per-page selector.
     *
     * @param array $pg Pagination metadata from paginate()
     * @param string $itemLabel Label for items (e.g., "vendors", "items")
     * @param array $perPageOptions Options for per-page dropdown
     */
    public static function renderControls(array $pg, string $itemLabel = 'items', array $perPageOptions = [25, 50, 100], string $prefix = ''): void
    {
        if ($pg['total_rows'] === 0) return;
        $prefixAttr = $prefix !== '' ? ' data-prefix="' . htmlspecialchars($prefix, ENT_QUOTES) . '"' : '';
        ?>
        <div class="pagination-bar">
            <div class="pagination-info">
                Showing <?php echo $pg['start_row']; ?>-<?php echo $pg['end_row']; ?> of <?php echo $pg['total_rows']; ?> <?php echo e($itemLabel); ?>
            </div>

            <?php if ($pg['total_pages'] > 1): ?>
            <ul class="pagination">
                <?php if ($pg['has_prev']): ?>
                <li><a href="<?php echo self::buildPageUrl(1, $prefix); ?>">&laquo;</a></li>
                <li><a href="<?php echo self::buildPageUrl($pg['current_page'] - 1, $prefix); ?>">&lsaquo;</a></li>
                <?php else: ?>
                <li class="disabled"><span>&laquo;</span></li>
                <li class="disabled"><span>&lsaquo;</span></li>
                <?php endif; ?>

                <?php
                $startPage = max(1, $pg['current_page'] - 2);
                $endPage = min($pg['total_pages'], $pg['current_page'] + 2);

                if ($startPage > 1): ?>
                <li><a href="<?php echo self::buildPageUrl(1, $prefix); ?>">1</a></li>
                <?php if ($startPage > 2): ?><li class="disabled"><span>...</span></li><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="<?php echo $i === $pg['current_page'] ? 'active' : ''; ?>">
                    <?php if ($i === $pg['current_page']): ?>
                    <span><?php echo $i; ?></span>
                    <?php else: ?>
                    <a href="<?php echo self::buildPageUrl($i, $prefix); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                </li>
                <?php endfor; ?>

                <?php if ($endPage < $pg['total_pages']): ?>
                <?php if ($endPage < $pg['total_pages'] - 1): ?><li class="disabled"><span>...</span></li><?php endif; ?>
                <li><a href="<?php echo self::buildPageUrl($pg['total_pages'], $prefix); ?>"><?php echo $pg['total_pages']; ?></a></li>
                <?php endif; ?>

                <?php if ($pg['has_next']): ?>
                <li><a href="<?php echo self::buildPageUrl($pg['current_page'] + 1, $prefix); ?>">&rsaquo;</a></li>
                <li><a href="<?php echo self::buildPageUrl($pg['total_pages'], $prefix); ?>">&raquo;</a></li>
                <?php else: ?>
                <li class="disabled"><span>&rsaquo;</span></li>
                <li class="disabled"><span>&raquo;</span></li>
                <?php endif; ?>
            </ul>
            <?php endif; ?>

            <div class="per-page-group">
                <label>Show:</label>
                <select data-action="changePerPage"<?php echo $prefixAttr; ?>>
                    <?php foreach ($perPageOptions as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo $pg['per_page'] == $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                    <?php endforeach; ?>
                </select>
                <label>per page</label>
            </div>
        </div>
        <?php
    }

    /**
     * Filter $_GET to only known parameter keys.
     * Prevents reflection of arbitrary parameter names (CWE-20/CWE-116).
     */
    private static function safeParams(): array
    {
        $allowed = ['page', 'per_page', 'sort', 'order', 'search', 'filter', 'status',
                     'mode', 'q', 'export', 'id', 'vendor_id', 'tab',
                     'c_sort', 'c_order', 'c_page', 'c_cat', 'c_q',
                     'sp_sort', 'sp_order', 'sp_page', 'sp_q',
                     'section', 'view', 'scope_id', 'framework_id',
                     'year', 'type', 'severity', 'risk_level',
                     'api_tab', 'edit_token', 'new',
                     'manage_scopes', 'detail_uri', 'detail_method', 'ip',
                     'tier', 'grade', 'needs_rescore', 'show_shadow_saas'];
        return array_intersect_key($_GET, array_flip($allowed));
    }

    /**
     * Build a URL for a specific page, preserving only known query params.
     */
    public static function buildPageUrl(int $page, string $prefix = ''): string
    {
        $params = self::safeParams();
        $params[$prefix . 'page'] = $page;
        return '?' . http_build_query($params);
    }

    /**
     * Build a sort URL that toggles direction when clicking the same column.
     */
    public static function buildSortUrl(string $column, string $currentSort, string $currentOrder, string $prefix = ''): string
    {
        $params = self::safeParams();
        $params[$prefix . 'sort'] = $column;
        $params[$prefix . 'order'] = ($currentSort === $column && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
        $params[$prefix . 'page'] = 1;
        return '?' . http_build_query($params);
    }

    /**
     * Get sort direction indicator arrow for column headers.
     */
    public static function getSortIndicator(string $column, string $currentSort, string $currentOrder): string
    {
        if ($currentSort !== $column) return '';
        return $currentOrder === 'ASC' ? ' &#9650;' : ' &#9660;';
    }
}
