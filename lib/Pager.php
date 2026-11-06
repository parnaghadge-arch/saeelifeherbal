<?php
/**
 * Pagination helper producing Bootstrap 5 markup.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Pager
{
    public int $page;
    public int $perPage;
    public int $total;
    public int $pages;
    public int $offset;

    public function __construct(int $total, int $perPage = 20, ?int $page = null)
    {
        $this->perPage = max(1, min(200, $perPage));
        $this->total = max(0, $total);
        $this->pages = max(1, (int) ceil($this->total / $this->perPage));
        $requested = $page ?? (int) ($_GET['page'] ?? 1);
        $this->page = max(1, min($this->pages, $requested));
        $this->offset = ($this->page - 1) * $this->perPage;
    }

    public function limitSql(): string
    {
        return ' LIMIT ' . $this->perPage . ' OFFSET ' . $this->offset;
    }

    public function infoText(): string
    {
        $from = $this->total === 0 ? 0 : $this->offset + 1;
        $to = min($this->total, $this->offset + $this->perPage);
        return 'Showing <strong>' . $from . '</strong>–<strong>' . $to . '</strong> of <strong>' . $this->total . '</strong>';
    }

    public function links(array $params = []): string
    {
        if ($this->pages <= 1) {
            return '';
        }
        $base = Request::uri();
        $build = static function (int $page) use ($base, $params): string {
            $params['page'] = $page;
            return e($base . '?' . http_build_query(array_filter($params, static fn ($v) => $v !== null && $v !== '')));
        };
        $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0 flex-wrap">';
        $prevDisabled = $this->page <= 1 ? ' disabled' : '';
        $html .= '<li class="page-item' . $prevDisabled . '"><a class="page-link" href="' . $build(max(1, $this->page - 1)) . '">Prev</a></li>';
        $start = max(1, $this->page - 2);
        $end = min($this->pages, $this->page + 2);
        if ($start > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $build(1) . '">1</a></li>';
            if ($start > 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
        }
        for ($i = $start; $i <= $end; $i++) {
            $active = $i === $this->page ? ' active' : '';
            $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $build($i) . '">' . $i . '</a></li>';
        }
        if ($end < $this->pages) {
            if ($end < $this->pages - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            $html .= '<li class="page-item"><a class="page-link" href="' . $build($this->pages) . '">' . $this->pages . '</a></li>';
        }
        $nextDisabled = $this->page >= $this->pages ? ' disabled' : '';
        $html .= '<li class="page-item' . $nextDisabled . '"><a class="page-link" href="' . $build(min($this->pages, $this->page + 1)) . '">Next</a></li>';
        $html .= '</ul></nav>';
        return $html;
    }
}
