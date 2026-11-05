<?php

namespace Tests\Feature;

use App\Domain\Integrations\GoogleCalendar\Contracts\GoogleCalendarOperationProcessor;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationContext;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationRequest;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarOperation;
use App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarProcessingException;
use App\Domain\Integrations\GoogleCalendar\Services\GoogleApiCalendarOperationProcessor;
use App\Domain\Integrations\GoogleCalendar\Services\LocalGoogleCalendarOperationProcessor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleApiCalendarOperationProcessorTest extends TestCase
{
    private const BASE = 'https://www.googleapis.com/calendar/v3/calendars/pickup%40group.calendar.google.com/events';

    private const EVENT_ID = 'abc123';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $opensslConfig = ['config' => base_path('tests/Fixtures/openssl-test.cnf')];
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, ...$opensslConfig]);
        openssl_pkey_export($key, $pem, null, $opensslConfig);
        config([
            'google_calendar.driver' => 'google',
            'google_calendar.service_account.credentials_json' => json_encode([
                'type' => 'service_account',
                'client_email' => 'bot@project.iam.gserviceaccount.com',
                'private_key' => $pem,
            ]),
        ]);
    }

    public function test_driver_config_selects_the_processor(): void
    {
        $this->assertInstanceOf(GoogleApiCalendarOperationProcessor::class, app(GoogleCalendarOperationProcessor::class));

        config(['google_calendar.driver' => 'local']);
        $this->assertInstanceOf(LocalGoogleCalendarOperationProcessor::class, app(GoogleCalendarOperationProcessor::class));
    }

    public function test_upsert_inserts_new_event_with_stable_id_and_verifies_readback(): void
    {
        $gets = 0;
        Http::fake(function (Request $request) use (&$gets) {
            if (str_starts_with($request->url(), 'https://oauth2.googleapis.com/token')) {
                $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $request['grant_type']);
                $this->assertCount(3, explode('.', $request['assertion']));

                return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            }
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer tok'));
            if ($request->method() === 'GET') {
                return ++$gets === 1 ? Http::response(['error' => ['code' => 404]], 404) : Http::response($this->remote());
            }
            if ($request->method() === 'POST') {
                $this->assertSame(self::BASE, $request->url());
                $this->assertSame(self::EVENT_ID, $request['id']);
                $this->assertSame('confirmed', $request['status']);

                return Http::response($this->remote());
            }

            return Http::response([], 500);
        });

        $result = $this->processor()->process($this->request(), $this->context());

        $this->assertSame(self::EVENT_ID, $result->googleEventId);
        $this->assertTrue($result->remoteStateVerified);
        Http::assertSentCount(4);
    }

    public function test_upsert_updates_existing_event_and_reports_mismatch_as_unverified(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::BASE.'/'.self::EVENT_ID => Http::sequence()
                ->push($this->remote(['summary' => 'old']))
                ->push($this->remote())
                ->push($this->remote(['summary' => 'changed by someone'])),
        ]);

        $result = $this->processor()->process($this->request(), $this->context());

        $this->assertFalse($result->remoteStateVerified);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['summary'] === 'Pickup #B1');
    }

    public function test_insert_conflict_falls_back_to_update(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::BASE => Http::response(['error' => ['code' => 409]], 409),
            self::BASE.'/'.self::EVENT_ID => Http::sequence()
                ->push([], 404)
                ->push($this->remote())
                ->push($this->remote()),
        ]);

        $result = $this->processor()->process($this->request(), $this->context());

        $this->assertTrue($result->remoteStateVerified);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT');
    }

    public function test_delete_treats_missing_event_as_done(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::BASE.'/'.self::EVENT_ID => Http::sequence()->push([], 410)->push($this->remote(['status' => 'cancelled'])),
        ]);

        $result = $this->processor()->process($this->request(GoogleCalendarOperation::Delete), $this->context());

        $this->assertTrue($result->remoteStateVerified);
    }

    public function test_access_denied_is_permanent(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*' => Http::response(['error' => ['code' => 403, 'message' => 'Forbidden', 'errors' => [['reason' => 'forbidden']]]], 403),
        ]);

        try {
            $this->processor()->process($this->request(), $this->context());
            $this->fail('Expected exception.');
        } catch (GoogleCalendarProcessingException $exception) {
            $this->assertFalse($exception->retryable);
        }
    }

    public function test_server_error_after_write_is_uncertain(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::BASE => Http::response([], 503),
            self::BASE.'/'.self::EVENT_ID => Http::response([], 404),
        ]);
        try {
            $this->processor()->process($this->request(), $this->context());
            $this->fail('Expected exception.');
        } catch (GoogleCalendarProcessingException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertTrue($exception->remoteOutcomeUnknown);
        }
    }

    public function test_rate_limit_is_retryable_with_known_outcome(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*' => Http::response(['error' => ['code' => 429]], 429),
        ]);
        try {
            $this->processor()->process($this->request(), $this->context());
            $this->fail('Expected exception.');
        } catch (GoogleCalendarProcessingException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertFalse($exception->remoteOutcomeUnknown);
        }
    }

    public function test_write_timeout_is_uncertain(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'oauth2')) {
                return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            }
            if ($request->method() === 'GET') {
                return Http::response([], 404);
            }

            throw new ConnectionException('timeout');
        });

        try {
            $this->processor()->process($this->request(), $this->context());
            $this->fail('Expected exception.');
        } catch (GoogleCalendarProcessingException $exception) {
            $this->assertTrue($exception->remoteOutcomeUnknown);
        }
    }

    public function test_lost_lease_stops_before_remote_calls(): void
    {
        Http::fake();

        $this->expectException(GoogleCalendarProcessingException::class);
        try {
            $this->processor()->process($this->request(), new GoogleCalendarOperationContext(10, fn (): bool => false));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_missing_credentials_is_permanent(): void
    {
        config(['google_calendar.service_account.credentials_json' => null, 'google_calendar.service_account.credentials_path' => null]);
        Http::fake();

        try {
            $this->processor()->process($this->request(), $this->context());
            $this->fail('Expected exception.');
        } catch (GoogleCalendarProcessingException $exception) {
            $this->assertFalse($exception->retryable);
        }
    }

    private function processor(): GoogleApiCalendarOperationProcessor
    {
        return app(GoogleApiCalendarOperationProcessor::class);
    }

    private function context(): GoogleCalendarOperationContext
    {
        return new GoogleCalendarOperationContext(10, fn (): bool => true);
    }

    private function request(GoogleCalendarOperation $operation = GoogleCalendarOperation::Upsert): GoogleCalendarOperationRequest
    {
        return new GoogleCalendarOperationRequest(
            'B1',
            GoogleCalendarEventType::Pickup,
            $operation,
            'pickup@group.calendar.google.com',
            self::EVENT_ID,
            1,
            $operation === GoogleCalendarOperation::Delete ? null : [
                'summary' => 'Pickup #B1',
                'description' => 'Status: APPROVED',
                'start' => ['dateTime' => '2026-11-10T10:00:00+07:00', 'timeZone' => 'Asia/Bangkok'],
                'end' => ['dateTime' => '2026-11-10T11:00:00+07:00', 'timeZone' => 'Asia/Bangkok'],
            ],
        );
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function remote(array $overrides = []): array
    {
        return [
            'id' => self::EVENT_ID,
            'status' => 'confirmed',
            'summary' => 'Pickup #B1',
            'description' => 'Status: APPROVED',
            'start' => ['dateTime' => '2026-11-10T03:00:00Z', 'timeZone' => 'Asia/Bangkok'],
            'end' => ['dateTime' => '2026-11-10T11:00:00+07:00', 'timeZone' => 'Asia/Bangkok'],
            ...$overrides,
        ];
    }
}
