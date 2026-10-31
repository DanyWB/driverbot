<?php

namespace App\Http\Requests\Website;

class SaveWebsiteDraftRequest extends WebsiteRevisionRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [...parent::rules(), 'content' => ['present', 'array'], 'cover_asset_id' => ['nullable', 'uuid']];
    }
}
