<?php

namespace App\Http\Controllers\Api\V1\Bot;

use App\Http\Controllers\Controller;
use App\Http\Responses\BotApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigurationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return BotApiResponse::success($request, [
            'timezone' => (string) config('business.timezone'),
            'currency' => (string) config('business.currency'),
            'terms_version' => (string) config('business.terms_version'),
            'pending_ttl_hours' => (int) config('business.pending_ttl_hours'),
            'manager_telegram' => config('business.manager_telegram'),
        ]);
    }
}
