<?php

namespace App\Http\Responses;

use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Integrations\Bot\Exceptions\BotApiException;
use App\Domain\Pricing\Exceptions\PricingException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class BotApiExceptionRenderer
{
    public function render(Request $request, Throwable $exception): JsonResponse
    {
        if ($exception instanceof HttpResponseException && $exception->getResponse() instanceof JsonResponse) {
            return $exception->getResponse();
        }

        if ($exception instanceof BotApiException) {
            return BotApiResponse::error(
                $request,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
                $exception->fields,
                $exception->details,
            );
        }

        if ($exception instanceof ValidationException) {
            return BotApiResponse::error(
                $request,
                'validation_failed',
                'The request data is invalid.',
                422,
                $exception->errors(),
            );
        }

        if ($exception instanceof BookingException) {
            return BotApiResponse::error(
                $request,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
                details: $exception->context,
            );
        }

        if ($exception instanceof PricingException) {
            return BotApiResponse::error(
                $request,
                $exception->errorCode,
                $exception->getMessage(),
                422,
                details: $exception->context,
            );
        }

        if ($exception instanceof ModelNotFoundException) {
            return BotApiResponse::error($request, 'resource_not_found', 'The requested resource was not found.', 404);
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $code = match ($status) {
                404 => 'route_not_found',
                405 => 'method_not_allowed',
                429 => 'rate_limit_exceeded',
                default => 'http_error',
            };

            return BotApiResponse::error($request, $code, Response::$statusTexts[$status] ?? 'HTTP error.', $status);
        }

        Log::error('Unhandled Bot API exception.', [
            'exception' => $exception,
            'request_id' => BotApiResponse::requestId($request),
        ]);

        return BotApiResponse::error($request, 'internal_error', 'The service could not process the request.', 500);
    }
}
