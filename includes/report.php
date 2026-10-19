<?php
declare(strict_types=1);

/**
 * يعرض تقريراً جدولياً مع طباعة وتصدير CSV (بشرط صلاحية reports.export).
 * $rows: مصفوفة صفوف مصفوفية القيم نصوص جاهزة؛ $summary: [label => value] اختياري.
 */
function report_page(string $title, array $headers, array $rows, string $filtersHtml = '', string $csvName = 'report', array $summary = [], string $note = ''): void
{
    if (get('export') === 'csv') {
        require_perm('reports.export');
        audit('export_report', 'report', null, ['report' => $csvName, 'rows' => count($rows)]);
        csv_download($csvName . '_' . date('Ymd') . '.csv', $headers, $rows);
    }
    $print = get('print') === '1';
    render_header($title, $print ? ['print' => true] : []);
    if (!$print) {
        echo '<form class="filter-bar row g-2 align-items-end no-print" method="get">' . $filtersHtml . '<div class="col-auto d-flex gap-1"><button class="btn btn-primary"><i class="bi bi-funnel"></i> عرض</button><a class="btn btn-light" href="?">مسح</a></div></form>';
    }
    echo '<div class="no-print page-actions">';
    echo $print ? '<button class="btn btn-primary" data-print><i class="bi bi-printer"></i> طباعة</button>' : '<a class="btn btn-outline-secondary" target="_blank" href="' . e(query_with(['print' => '1'])) . '"><i class="bi bi-printer"></i> طباعة</a>';
    if (can('reports.export') && !$print) {
        echo '<a class="btn btn-outline-secondary" href="' . e(query_with(['export' => 'csv'])) . '"><i class="bi bi-filetype-csv"></i> تصدير CSV</a>';
    }
    echo '</div><div class="ecm-card"><div class="text-center mb-3"><h4 class="mb-0">' . e((string)setting('center_name', '')) . '</h4><div class="fw-semibold">' . e($title) . '</div><div class="small text-muted">' . e(fdatetime(now())) . '</div></div>';
    if ($summary) {
        echo '<div class="d-flex flex-wrap gap-3 mb-3">';
        foreach ($summary as $k => $v) {
            echo '<div class="border rounded px-3 py-2"><div class="small text-muted">' . e($k) . '</div><div class="fw-bold money">' . e((string)$v) . '</div></div>';
        }
        echo '</div>';
    }
    if (!$rows) {
        echo empty_state('لا توجد بيانات مطابقة', 'غيّر الفلاتر أو الفترة.', '', 'bar-chart');
    } else {
        echo '<div class="table-responsive"><table class="table ecm-table table-sm"><thead><tr>';
        foreach ($headers as $h) {
            echo '<th>' . e($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach (array_slice($rows, 0, 2000) as $r) {
            echo '<tr>';
            foreach ($r as $c) {
                echo '<td>' . e((string)$c) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        if (count($rows) > 2000) {
            echo '<div class="alert alert-warning">عُرض أول 2000 صف؛ استخدم التصدير للحصول على الكل.</div>';
        }
    }
    if ($note) {
        echo '<div class="form-text mt-2">' . e($note) . '</div>';
    }
    echo '</div>';
    render_footer();
}

function report_date_filters(string $from, string $to): string
{
    return '<div class="col-6 col-md-2"><label class="form-label small mb-0">من</label><input class="form-control" type="date" name="from" value="' . e($from) . '"></div><div class="col-6 col-md-2"><label class="form-label small mb-0">إلى</label><input class="form-control" type="date" name="to" value="' . e($to) . '"></div>';
}
