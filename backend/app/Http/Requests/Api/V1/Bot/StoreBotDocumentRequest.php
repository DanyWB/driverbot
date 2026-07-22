<?php

namespace App\Http\Requests\Api\V1\Bot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreBotDocumentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document' => [
                'required',
                File::types(['pdf', 'jpg', 'jpeg', 'png', 'webp'])
                    ->max((int) config('business.customer_document_max_mb') * 1024),
            ],
            'type' => ['required', Rule::in(['passport', 'driver_license', 'photo', 'other'])],
        ];
    }
}
