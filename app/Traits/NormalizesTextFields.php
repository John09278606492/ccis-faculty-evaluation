<?php

namespace App\Traits;

/**
 * Trait for normalizing text fields when saving models.
 *
 * - Title Case: first_name, middle_name, last_name, name (proper names)
 * - Lowercase: email
 * - Uppercase: code, student_id, employee_id (identifiers)
 */
trait NormalizesTextFields
{
    /**
     * Fields to normalize to Title Case (e.g., "john doe" -> "John Doe")
     */
    protected function getTitleCaseFields(): array
    {
        return ['first_name', 'middle_name', 'last_name', 'name'];
    }

    /**
     * Fields to normalize to lowercase (e.g., "John@Example.COM" -> "john@example.com")
     */
    protected function getLowercaseFields(): array
    {
        return ['email'];
    }

    /**
     * Fields to normalize to UPPERCASE (e.g., "abc123" -> "ABC123")
     */
    protected function getUppercaseFields(): array
    {
        return ['code'];
    }

    /**
     * Boot the trait - register saving event handler.
     */
    protected static function bootNormalizesTextFields(): void
    {
        static::saving(function ($model) {
            $model->normalizeTextFields();
        });
    }

    /**
     * Normalize all text fields according to their rules.
     */
    public function normalizeTextFields(): void
    {
        // Title Case for names
        foreach ($this->getTitleCaseFields() as $field) {
            if (isset($this->attributes[$field]) && is_string($this->attributes[$field])) {
                $this->attributes[$field] = $this->toTitleCase($this->attributes[$field]);
            }
        }

        // Lowercase for emails
        foreach ($this->getLowercaseFields() as $field) {
            if (isset($this->attributes[$field]) && is_string($this->attributes[$field])) {
                $this->attributes[$field] = strtolower(trim($this->attributes[$field]));
            }
        }

        // Uppercase for codes/IDs
        foreach ($this->getUppercaseFields() as $field) {
            if (isset($this->attributes[$field]) && is_string($this->attributes[$field])) {
                $this->attributes[$field] = strtoupper(trim($this->attributes[$field]));
            }
        }
    }

    /**
     * Convert string to Title Case, handling multi-word names.
     */
    protected function toTitleCase(string $value): string
    {
        // Trim and convert to title case
        $value = trim($value);

        // Handle empty strings
        if (empty($value)) {
            return $value;
        }

        // Use mb_convert_case for proper UTF-8 support
        return mb_convert_case(mb_strtolower($value), MB_CASE_TITLE, 'UTF-8');
    }
}
