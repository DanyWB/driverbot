<?php

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCustomersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'documents' => ['nullable', Rule::in(['all', 'yes', 'no'])],
            'sort' => ['nullable', Rule::in(['name', 'created_at', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', Rule::in([15, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'search' => (string) $this->validated('search', ''),
            'documents' => (string) $this->validated('documents', 'all'),
            'sort' => (string) $this->validated('sort', 'updated_at'),
            'direction' => (string) $this->validated('direction', 'desc'),
            'per_page' => (int) $this->validated('per_page', 25),
        ];
    }
}
