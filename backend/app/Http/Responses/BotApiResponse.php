<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotApiResponse
{
    /**
     * @param  array<mixed>|null  $data
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        Request $request,
        ?array $data,
        int $status = 200,
        bool $replayed = false,
        array $meta = [],
    ): JsonResponse {
        return response()->json([
            'data' => $data,
            'meta' => [
                'request_id' => self::requestId($request),
                'idempotency_replayed' => $replayed,
            ] + $meta,
        ], $status);
    }

    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, mixed>  $details
     */
    public static function error(
        Request $request,
        string $code,
        string $message,
        int $status,
        array $fields = [],
        array $details = [],
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => self::code($code),
                'message' => $message,
                'fields' => (object) $fields,
                'details' => (object) $details,
                'request_id' => self::requestId($request),
            ],
        ], $status);
    }

    public static function code(string $code): string
    {
        return strtoupper(str_replace(['-', '.'], '_', trim($code)));
    }

    public static function requestId(Request $request): string
    {
        return (string) $request->attributes->get('request_id', '');
    }
}
