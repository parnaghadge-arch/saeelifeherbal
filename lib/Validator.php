<?php
/**
 * Server side validation helper producing field level error bags.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Validator
{
    private array $data;
    private array $errors = [];
    private array $labels = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function label(string $field, string $label): self
    {
        $this->labels[$field] = $label;
        return $this;
    }

    public function value(string $field, mixed $default = null): mixed
    {
        $v = $this->data[$field] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public function raw(string $field, mixed $default = null): mixed
    {
        return $this->data[$field] ?? $default;
    }

    private function name(string $field): string
    {
        return $this->labels[$field] ?? ucwords(str_replace(['_', '-'], ' ', $field));
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function required(string $field, ?string $message = null): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '' || $v === []) {
            $this->addError($field, $message ?? $this->name($field) . ' is required.');
        }
        return $this;
    }

    public function email(string $field, bool $required = false): self
    {
        $v = $this->value($field);
        if ($v === '' && !$required) {
            return $this;
        }
        if (!filter_var((string) $v, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, 'Please enter a valid email address.');
        }
        return $this;
    }

    public function phone(string $field, bool $required = false): self
    {
        $v = preg_replace('/[^0-9+]/', '', (string) $this->value($field));
        if ($v === '' && !$required) {
            return $this;
        }
        $digits = ltrim($v, '+');
        if (!preg_match('/^[6-9]\d{9}$/', $digits) && !preg_match('/^91[6-9]\d{9}$/', $digits)) {
            $this->addError($field, 'Enter a valid 10 digit Indian mobile number.');
        }
        return $this;
    }

    public function min(string $field, int $length): self
    {
        $v = (string) $this->value($field);
        if ($v !== '' && mb_strlen($v) < $length) {
            $this->addError($field, $this->name($field) . " must be at least $length characters.");
        }
        return $this;
    }

    public function max(string $field, int $length): self
    {
        $v = (string) $this->value($field);
        if (mb_strlen($v) > $length) {
            $this->addError($field, $this->name($field) . " must not exceed $length characters.");
        }
        return $this;
    }

    public function numeric(string $field, bool $required = false): self
    {
        $v = $this->value($field);
        if (($v === '' || $v === null) && !$required) {
            return $this;
        }
        if (!is_numeric($v)) {
            $this->addError($field, $this->name($field) . ' must be a number.');
        }
        return $this;
    }

    public function minValue(string $field, float|int $min): self
    {
        $v = $this->value($field);
        if ($v === '' || $v === null) {
            return $this;
        }
        if (!is_numeric($v) || (float) $v < $min) {
            $this->addError($field, $this->name($field) . " must be at least $min.");
        }
        return $this;
    }

    public function integer(string $field, bool $required = false): self
    {
        $v = $this->value($field);
        if (($v === '' || $v === null) && !$required) {
            return $this;
        }
        if (filter_var($v, FILTER_VALIDATE_INT) === false) {
            $this->addError($field, $this->name($field) . ' must be a whole number.');
        }
        return $this;
    }

    public function in(string $field, array $allowed, bool $required = true): self
    {
        $v = $this->value($field);
        if (($v === '' || $v === null) && !$required) {
            return $this;
        }
        if (!in_array((string) $v, array_map('strval', $allowed), true)) {
            $this->addError($field, 'Please select a valid option for ' . strtolower($this->name($field)) . '.');
        }
        return $this;
    }

    public function matches(string $field, string $otherField): self
    {
        if ($this->value($field) !== $this->value($otherField)) {
            $this->addError($field, $this->name($field) . ' does not match ' . strtolower($this->name($otherField)) . '.');
        }
        return $this;
    }

    public function regex(string $field, string $pattern, string $message): self
    {
        $v = (string) $this->value($field);
        if ($v !== '' && !preg_match($pattern, $v)) {
            $this->addError($field, $message);
        }
        return $this;
    }

    public function pan(string $field, bool $required = false): self
    {
        $v = strtoupper((string) $this->value($field));
        if ($v === '' && !$required) {
            return $this;
        }
        if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $v)) {
            $this->addError($field, 'Enter a valid PAN number (e.g. ABCDE1234F).');
        }
        return $this;
    }

    public function aadhaar(string $field, bool $required = false): self
    {
        $v = preg_replace('/\D/', '', (string) $this->value($field));
        if ($v === '' && !$required) {
            return $this;
        }
        if (!preg_match('/^[2-9]\d{11}$/', $v)) {
            $this->addError($field, 'Enter a valid 12 digit Aadhaar number.');
        }
        return $this;
    }

    public function ifsc(string $field, bool $required = false): self
    {
        $v = strtoupper((string) $this->value($field));
        if ($v === '' && !$required) {
            return $this;
        }
        if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $v)) {
            $this->addError($field, 'Enter a valid IFSC code (e.g. SBIN0001234).');
        }
        return $this;
    }

    public function pincode(string $field, bool $required = false): self
    {
        $v = (string) $this->value($field);
        if ($v === '' && !$required) {
            return $this;
        }
        if (!preg_match('/^[1-9][0-9]{5}$/', $v)) {
            $this->addError($field, 'Enter a valid 6 digit PIN code.');
        }
        return $this;
    }

    public function date(string $field, bool $required = false): self
    {
        $v = (string) $this->value($field);
        if ($v === '' && !$required) {
            return $this;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            $this->addError($field, $this->name($field) . ' must be a valid date (YYYY-MM-DD).');
        }
        return $this;
    }

    public function password(string $field): self
    {
        $v = (string) ($this->data[$field] ?? '');
        foreach (Security::passwordIssues($v) as $issue) {
            $this->addError($field, $issue);
        }
        return $this;
    }

    public function accepted(string $field): self
    {
        $v = $this->value($field);
        if (!in_array($v, ['1', 'on', 'yes', 'true', true, 1], true)) {
            $this->addError($field, 'Please accept ' . strtolower($this->name($field)) . '.');
        }
        return $this;
    }

    public function custom(string $field, bool $condition, string $message): self
    {
        if (!$condition) {
            $this->addError($field, $message);
        }
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        foreach ($this->errors as $messages) {
            if ($messages !== []) {
                return (string) $messages[0];
            }
        }
        return '';
    }

    public function allErrors(): array
    {
        return array_merge(...array_values($this->errors ?: [[]]));
    }

    public function old(string $field, mixed $default = ''): string
    {
        return Security::escape($this->value($field, $default));
    }
}
