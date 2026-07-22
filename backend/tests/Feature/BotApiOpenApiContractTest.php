<?php

namespace Tests\Feature;

use App\Domain\Bookings\Enums\BookingStatus;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BotApiOpenApiContractTest extends TestCase
{
    public function test_openapi_document_is_parseable_and_covers_every_bot_route(): void
    {
        $path = base_path('openapi/bot-api.v1.yaml');
        $document = Yaml::parseFile($path);

        $this->assertIsArray($document);
        $this->assertSame('3.1.0', $document['openapi'] ?? null);
        $this->assertSame('1.0.0', $document['info']['version'] ?? null);
        $this->assertIsArray($document['paths'] ?? null);

        $covered = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if (! $route instanceof Route || ! str_starts_with($route->uri(), 'api/v1/bot')) {
                continue;
            }

            if (str_contains($route->uri(), 'fallbackPlaceholder')) {
                continue;
            }

            $specPath = substr($route->uri(), strlen('api/v1/bot')) ?: '/';
            $this->assertArrayHasKey($specPath, $document['paths'], "Missing OpenAPI path {$specPath}.");

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $method = strtolower($method);
                $this->assertArrayHasKey($method, $document['paths'][$specPath], "Missing OpenAPI operation {$method} {$specPath}.");
                $covered[] = "{$method} {$specPath}";
            }
        }

        $this->assertCount(16, array_unique($covered));
    }

    public function test_contract_does_not_expose_internal_fields_or_storage_paths(): void
    {
        $contract = file_get_contents(base_path('openapi/bot-api.v1.yaml'));

        $this->assertIsString($contract);
        $this->assertStringNotContainsString('admin_note', $contract);
        $this->assertStringNotContainsString('token_hash', $contract);
        $this->assertStringNotContainsString('file_path', $contract);
        $this->assertStringContainsString('Idempotency-Key', $contract);
        $this->assertStringContainsString('X-Telegram-User-ID', $contract);
    }

    public function test_contract_matches_public_validation_limits_and_vehicle_types(): void
    {
        $document = Yaml::parseFile(base_path('openapi/bot-api.v1.yaml'));

        $this->assertSame(
            2000,
            $document['paths']['/bookings/{booking}/cancel']['post']['requestBody']['content']['application/json']['schema']['properties']['reason']['maxLength'] ?? null,
        );
        $this->assertSame(
            ['bike', 'scooter', 'car'],
            $document['components']['parameters']['VehicleType']['schema']['enum'] ?? null,
        );
        $this->assertSame(
            ['bike', 'scooter', 'car'],
            $document['components']['schemas']['Vehicle']['properties']['type']['enum'] ?? null,
        );
        $this->assertSame(
            array_column(BookingStatus::cases(), 'value'),
            $document['components']['schemas']['Booking']['properties']['status']['enum'] ?? null,
        );

        $bookingListParameters = collect(
            $document['paths']['/customers/me/bookings']['get']['parameters'] ?? [],
        )->keyBy('name');
        $this->assertSame(10000, $bookingListParameters->get('offset')['schema']['maximum'] ?? null);
        $this->assertContains(
            'delivery_address',
            $document['components']['schemas']['CreateBookingItem']['allOf'][0]['then']['required'] ?? [],
        );
    }
}
