<?php

namespace App\Http\Requests\Website;

use Illuminate\Foundation\Http\FormRequest;

class WebsiteRevisionRequest extends FormRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['expected_revision' => ['required', 'integer', 'min:0', 'max:4294967294']];
    }
}
