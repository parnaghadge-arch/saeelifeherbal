<?php
/**
 * Presentation helpers for the admin screens.
 */
declare(strict_types=1);

if (!function_exists('admin_form_open')) {
    /**
     * Should the add/edit form be visible for this request?
     *
     * Data-first admin screens keep the form collapsed until "+ New" (?new=1) or an edit
     * link (?id=…) is followed; validation errors re-open it automatically.
     */
    function admin_form_open(?array $record, array $errors = [], bool $newRequested = false): bool
    {
        return $record !== null || $errors !== [] || $newRequested || \Saee\Lib\Request::query('new') !== null;
    }
}

if (!function_exists('admin_new_button')) {
    /** "+ New <label>" button that opens the collapsed form on the same page. */
    function admin_new_button(string $route, string $label): string
    {
        return '<a class="btn btn-sm btn-primary" href="' . e($route . '?new=1') . '"><i class="fa-solid fa-plus me-1"></i>New ' . e($label) . '</a>';
    }
}

if (!function_exists('admin_back_to_list')) {
    /** "Back to list" button shown while a form is open. */
    function admin_back_to_list(string $route): string
    {
        return '<a class="btn btn-sm btn-outline-secondary" href="' . e($route) . '"><i class="fa-solid fa-list me-1"></i>Back to list</a>';
    }
}
