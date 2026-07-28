<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Tests;

use PHPUnit\Framework\TestCase;
use TropikalAI\Connect\Application\PublicChannels\ChangePublicComponentPlacement;
use TropikalAI\Connect\Application\PublicChannels\GetPublicComponentPlacement;
use TropikalAI\Connect\Application\PublicChannels\Ports\ContractCache;
use TropikalAI\Connect\Application\PublicChannels\Ports\ControlPlaneGateway;
use TropikalAI\Connect\Application\PublicChannels\Ports\HttpTransport;
use TropikalAI\Connect\Application\PublicChannels\Ports\HumanVerification;
use TropikalAI\Connect\Application\PublicChannels\Ports\InstallationCredentialsProvider;
use TropikalAI\Connect\Application\PublicChannels\Ports\PublicChannelLogger;
use TropikalAI\Connect\Application\PublicChannels\Ports\PublicComponentSettingsStore;
use TropikalAI\Connect\Application\PublicChannels\Ports\RateLimiter;
use TropikalAI\Connect\Application\PublicChannels\PublicChannelsConfig;
use TropikalAI\Connect\Application\PublicChannels\PublicChannelsService;
use TropikalAI\Connect\Application\PublicChannels\RemoteResponse;
use TropikalAI\Connect\Domain\PublicChannels\BookingRequest;
use TropikalAI\Connect\Domain\PublicChannels\InstallationCredentials;
use TropikalAI\Connect\Domain\PublicChannels\JobLaunchUrl;
use TropikalAI\Connect\Domain\PublicChannels\Origin;
use TropikalAI\Connect\Domain\PublicChannels\PublicComponentPlacement;
use TropikalAI\Connect\Domain\PublicChannels\PublicComponentType;
use TropikalAI\Connect\Domain\Security\SignedRequest;
use TropikalAI\Connect\Infrastructure\PublicChannels\ControlPlaneHumanVerification;
use TropikalAI\Connect\Infrastructure\PublicChannels\SignedControlPlaneGateway;

final class PublicChannelsTest extends TestCase
{
    public function test_booking_request_maps_iso_slot_to_the_served_job_contract(): void
    {
        $request = BookingRequest::fromInput([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'phone' => '+41 44 555 10 10',
            'note' => 'Please cover integrations.',
            'slot_start' => '2030-07-01T09:30:00+02:00',
            'slot_end' => '2030-07-01T09:45:00+02:00',
            'timezone' => 'Europe/Berlin',
            'booking_uuid' => 'booking_123',
            'cf_turnstile_token' => 'verified-token',
        ], new \DateTimeImmutable('2030-06-01T00:00:00Z'));

        self::assertSame([
            'booking_uuid' => 'booking_123',
            'summary' => 'TROPIKAL intro call — Ada Lovelace',
            'start' => '2030-07-01T09:30:00+02:00',
            'end' => '2030-07-01T09:45:00+02:00',
            'attendee_email' => 'ada@example.test',
            'attendee_name' => 'Ada Lovelace',
            'attendee_phone' => '+41 44 555 10 10',
            'description' => 'Please cover integrations.',
            'timezone' => 'Europe/Berlin',
        ], $request->payload());
    }

    public function test_service_preflights_contract_and_returns_typed_confirmation(): void
    {
        $gateway = new CallbackGateway(static function (string $method, string $path, ?array $json): RemoteResponse {
            if (str_ends_with($path, '/contract')) {
                return self::jsonResponse(200, [
                    'route' => [
                        'input_contract' => self::bookingContract(),
                    ],
                ]);
            }
            self::assertSame('POST', $method);
            self::assertSame('booking_123', $json['booking_uuid'] ?? null);

            return self::jsonResponse(200, [
                'output' => [
                    'status' => 'confirmed',
                    'booking_uuid' => 'booking_123',
                    'calendar_event_id' => 'event-1',
                    'calendar_event_url' => 'https://calendar.google.com/event/1',
                    'meet_url' => 'https://meet.google.com/abc-defg-hij',
                ],
            ]);
        });
        $service = self::service($gateway);

        $response = $service->book([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'phone' => '+41 44 555 10 10',
            'note' => '',
            'slot_start' => '2030-07-01T09:30:00+02:00',
            'slot_end' => '2030-07-01T09:45:00+02:00',
            'timezone' => 'Europe/Berlin',
            'booking_uuid' => 'booking_123',
            'cf_turnstile_token' => 'verified-token',
        ], '192.0.2.10');

        self::assertSame(201, $response->status);
        self::assertSame('confirmed', json_decode($response->body, true)['status']);
        self::assertSame([
            '/api/connect-filament/job-routes/intro-call-booking/contract',
            '/api/connect-filament/job-routes/intro-call-booking/invoke-sync',
        ], array_column($gateway->requests, 'path'));
    }

    public function test_contract_drift_fails_before_route_invocation(): void
    {
        $gateway = new CallbackGateway(static fn (): RemoteResponse => self::jsonResponse(200, [
            'route' => [
                'input_contract' => [
                    'type' => 'object',
                    'required' => ['booking_uuid'],
                    'properties' => ['booking_uuid' => ['type' => 'string']],
                    'additionalProperties' => false,
                ],
            ],
        ]));
        $response = self::service($gateway)->book([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'phone' => '+41 44 555 10 10',
            'note' => '',
            'slot_start' => '2030-07-01T09:30:00+02:00',
            'slot_end' => '2030-07-01T09:45:00+02:00',
            'timezone' => 'Europe/Berlin',
            'booking_uuid' => 'booking_123',
            'cf_turnstile_token' => 'verified-token',
        ], '192.0.2.10');

        self::assertSame(503, $response->status);
        self::assertSame('connector_contract_mismatch', json_decode($response->body, true)['error']);
        self::assertCount(1, $gateway->requests);
    }

    public function test_signed_gateway_binds_registered_site_origin(): void
    {
        $transport = new RecordingTransport;
        $credentials = new class implements InstallationCredentialsProvider
        {
            public function current(): ?InstallationCredentials
            {
                return new InstallationCredentials(
                    'cfi_test123',
                    'server-signing-secret',
                    'https://wordpress-connect.tropikal.dev/path',
                    'https://app.tropikal.ai',
                );
            }
        };
        $gateway = new SignedControlPlaneGateway($credentials, $transport);
        $gateway->request(
            'GET',
            '/api/connect-filament/job-routes/intro-call-booking/status',
            query: ['booking_uuid' => 'booking_123'],
        );

        $request = $transport->request;
        self::assertSame(
            'https://wordpress-connect.tropikal.dev',
            $request['headers'][SignedRequest::REQUEST_ORIGIN_HEADER],
        );
        $headers = $request['headers'];
        $canonical = SignedRequest::canonical(
            'cfi_test123',
            'GET',
            '/api/connect-filament/job-routes/intro-call-booking/status',
            ['booking_uuid' => 'booking_123'],
            (int) $headers[SignedRequest::TIMESTAMP_HEADER],
            $headers[SignedRequest::NONCE_HEADER],
            $headers[SignedRequest::BODY_HASH_HEADER],
        )."\nhttps://wordpress-connect.tropikal.dev";
        self::assertSame(
            hash_hmac('sha256', $canonical, 'server-signing-secret'),
            $headers[SignedRequest::SIGNATURE_HEADER],
        );
    }

    public function test_human_verification_is_delegated_to_the_signed_control_plane(): void
    {
        $gateway = new CallbackGateway(
            static function (string $method, string $path, ?array $json): RemoteResponse {
                self::assertSame('POST', $method);
                self::assertSame('/api/connect-filament/public/human-verification', $path);
                self::assertSame([
                    'token' => 'verified-token',
                    'remote_ip' => '192.0.2.10',
                ], $json);

                return self::jsonResponse(200, ['verified' => true]);
            },
        );

        self::assertTrue(
            (new ControlPlaneHumanVerification($gateway))->verify('verified-token', '192.0.2.10'),
        );
    }

    public function test_origin_is_strict_and_normalizes_default_ports(): void
    {
        self::assertSame(
            'https://wordpress-connect.tropikal.dev',
            Origin::fromUrl('HTTPS://WordPress-Connect.Tropikal.Dev.:443/path')->value,
        );
        self::assertSame('http://[::1]:8899', Origin::fromUrl('http://[::1]:8899/path')->value);
        self::assertSame(
            'https://app.tropikal.ai',
            (new InstallationCredentials(
                'cfi_test',
                'secret',
                'https://example.test',
                'HTTPS://APP.TROPIKAL.AI:443/api',
            ))->controlPlaneUrl,
        );

        foreach ([
            'http://wordpress-connect.tropikal.dev',
            'https://user:pass@example.test',
            'https://bad host.test',
        ] as $invalid) {
            try {
                Origin::fromUrl($invalid);
                self::fail("Expected origin to be rejected: {$invalid}");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_job_launch_url_contains_only_the_connected_installation_and_return_url(): void
    {
        self::assertSame(
            'https://app.tropikal.ai/jobs/create?installation_public_id=cfi_test123'
                .'&return_url=https%3A%2F%2Fn2n-connect.tropikal.dev%2Ftropikal-connect%2Fadmin',
            JobLaunchUrl::build(
                'https://app.tropikal.ai/',
                'cfi_test123',
                'https://n2n-connect.tropikal.dev/tropikal-connect/admin',
            ),
        );
    }

    public function test_availability_preflights_contract_and_returns_public_slots(): void
    {
        $gateway = new CallbackGateway(static function (string $method, string $path, ?array $json): RemoteResponse {
            if (str_ends_with($path, '/contract')) {
                return self::jsonResponse(200, [
                    'route' => ['input_contract' => self::availabilityContract()],
                ]);
            }
            self::assertSame('POST', $method);
            self::assertSame('2030-07-01', $json['from'] ?? null);

            return self::jsonResponse(200, [
                'output' => [
                    'timezone' => 'Europe/Berlin',
                    'slots' => [[
                        'start' => '2030-07-01T09:30:00+02:00',
                        'end' => '2030-07-01T09:45:00+02:00',
                    ]],
                ],
            ]);
        });

        $response = self::service($gateway)->availability(
            ['from' => '2030-07-01', 'to' => '2030-07-01'],
            '192.0.2.10',
        );

        self::assertSame(200, $response->status);
        self::assertSame('Europe/Berlin', json_decode($response->body, true)['timezone']);
        self::assertCount(2, $gateway->requests);
    }

    public function test_rate_limits_fail_closed_before_upstream_calls(): void
    {
        $gateway = new CallbackGateway(static fn (): RemoteResponse => self::jsonResponse(500, []));
        $service = new PublicChannelsService(
            $gateway,
            new ArrayContractCache,
            new AlwaysHuman,
            new NeverAllowed,
            new NullPublicChannelLogger,
        );

        self::assertSame(429, $service->availability([], '192.0.2.10')->status);
        self::assertSame(429, $service->book([], '192.0.2.10')->status);
        self::assertSame(429, $service->bookingStatus('booking_123', '192.0.2.10')->status);
        self::assertSame([], $gateway->requests);
    }

    public function test_rate_limit_storage_failure_returns_safe_unavailable_response(): void
    {
        $gateway = new CallbackGateway(static fn (): RemoteResponse => self::jsonResponse(500, []));
        $service = new PublicChannelsService(
            $gateway,
            new ArrayContractCache,
            new AlwaysHuman,
            new FailingRateLimiter,
            new NullPublicChannelLogger,
        );

        self::assertSame(503, $service->availability([], '192.0.2.10')->status);
        self::assertSame(503, $service->book(self::bookingInput(), '192.0.2.10')->status);
        self::assertSame(503, $service->bookingStatus('booking_123', '192.0.2.10')->status);
        self::assertSame([], $gateway->requests);
    }

    public function test_failed_human_verification_never_invokes_booking_route(): void
    {
        $gateway = new CallbackGateway(static fn (): RemoteResponse => self::jsonResponse(500, []));
        $service = new PublicChannelsService(
            $gateway,
            new ArrayContractCache,
            new NeverHuman,
            new AlwaysAllowed,
            new NullPublicChannelLogger,
        );

        $response = $service->book(self::bookingInput(), '192.0.2.10');

        self::assertSame(422, $response->status);
        self::assertSame('human_verification_failed', json_decode($response->body, true)['error']);
        self::assertSame([], $gateway->requests);
    }

    public function test_sync_timeout_returns_pollable_booking_attempt(): void
    {
        $gateway = new CallbackGateway(static function (string $method, string $path): RemoteResponse {
            return str_ends_with($path, '/contract')
                ? self::jsonResponse(200, ['route' => ['input_contract' => self::bookingContract()]])
                : self::jsonResponse(504, ['error' => 'workflow_route_timeout']);
        });

        $response = self::service($gateway)->book(self::bookingInput(), '192.0.2.10');

        self::assertSame(202, $response->status);
        self::assertSame('booking_pending', json_decode($response->body, true)['error']);
    }

    public function test_health_distinguishes_active_not_enabled_and_unavailable(): void
    {
        $active = new CallbackGateway(static function (string $method, string $path): RemoteResponse {
            if ($path === '/api/connect-filament/embed/info') {
                return self::jsonResponse(200, ['channel_id' => 'wfc_1']);
            }

            return self::jsonResponse(200, ['route' => ['input_contract' => ['type' => 'object']]]);
        });
        $activeBody = json_decode(self::service($active)->health('asset-1')->body, true);
        self::assertSame('active', $activeBody['chat']);
        self::assertSame('active', $activeBody['booking']);

        $disabled = new CallbackGateway(static fn (): RemoteResponse => self::jsonResponse(
            404,
            ['error' => 'chat_not_enabled'],
        ));
        $disabledBody = json_decode(self::service($disabled)->health('asset-1')->body, true);
        self::assertSame('not_enabled', $disabledBody['chat']);
        self::assertSame('not_enabled', $disabledBody['booking']);

        $unavailable = new CallbackGateway(static fn (): never => throw new \RuntimeException('offline'));
        $unavailableBody = json_decode(self::service($unavailable)->health('asset-1')->body, true);
        self::assertSame('unavailable', $unavailableBody['chat']);
        self::assertSame('unavailable', $unavailableBody['booking']);
    }

    public function test_missing_component_setting_defaults_to_enabled_and_explicit_false_wins(): void
    {
        $settings = new ArrayPublicComponentSettingsStore;
        $get = new GetPublicComponentPlacement($settings);
        $change = new ChangePublicComponentPlacement($settings);

        self::assertTrue($get->handle(PublicComponentType::Chat)->autoInject);
        $change->handle(PublicComponentType::Chat, false);
        self::assertFalse($get->handle(PublicComponentType::Chat)->autoInject);
    }

    public function test_local_chat_placement_off_avoids_every_upstream_chat_request(): void
    {
        $gateway = new CallbackGateway(static fn (): never => throw new \RuntimeException('must not be called'));
        $settings = new ArrayPublicComponentSettingsStore(false);
        $service = new PublicChannelsService(
            $gateway,
            new ArrayContractCache,
            new AlwaysHuman,
            new AlwaysAllowed,
            new NullPublicChannelLogger,
            new PublicChannelsConfig,
            $settings,
        );

        foreach ([
            $service->chatInfo(),
            $service->chatSession('opaque-resume-token'),
            $service->chat(['message' => 'private chat text']),
        ] as $response) {
            self::assertSame(404, $response->status);
            self::assertSame('chat_not_enabled', json_decode($response->body, true)['error']);
        }
        self::assertSame([], $gateway->requests);
        self::assertSame('not_enabled', json_decode($service->health('asset-1')->body, true)['chat']);
        self::assertSame([], array_filter(
            $gateway->requests,
            static fn (array $request): bool => str_contains($request['path'], '/embed/'),
        ));
    }

    public function test_invalid_booking_status_and_embed_asset_are_not_proxied(): void
    {
        $gateway = new CallbackGateway(static fn (): RemoteResponse => self::jsonResponse(500, []));
        $service = self::service($gateway);

        self::assertSame(422, $service->bookingStatus('../secret', '192.0.2.10')->status);
        self::assertSame(404, $service->embedAsset('../secret', 'v1')->status);
        self::assertSame(404, $service->embedAsset('iframe.js', '../bad')->status);
        self::assertSame([], $gateway->requests);
    }

    /** @return array<string, mixed> */
    private static function bookingContract(): array
    {
        $properties = [];
        foreach ([
            'booking_uuid',
            'summary',
            'start',
            'end',
            'attendee_email',
            'attendee_name',
            'attendee_phone',
            'description',
            'timezone',
        ] as $field) {
            $properties[$field] = ['type' => 'string'];
        }
        $properties['attendee_email']['format'] = 'email';

        return [
            'type' => 'object',
            'required' => ['booking_uuid', 'summary', 'start', 'end', 'attendee_email'],
            'properties' => $properties,
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function availabilityContract(): array
    {
        return [
            'type' => 'object',
            'required' => ['from', 'to', 'timezone', 'slot_minutes', 'duration_minutes'],
            'properties' => [
                'from' => ['type' => 'string'],
                'to' => ['type' => 'string'],
                'timezone' => ['type' => 'string'],
                'slot_minutes' => ['type' => 'integer'],
                'duration_minutes' => ['type' => 'integer'],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, string> */
    private static function bookingInput(): array
    {
        return [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'phone' => '+41 44 555 10 10',
            'note' => '',
            'slot_start' => '2030-07-01T09:30:00+02:00',
            'slot_end' => '2030-07-01T09:45:00+02:00',
            'timezone' => 'Europe/Berlin',
            'booking_uuid' => 'booking_123',
            'cf_turnstile_token' => 'verified-token',
        ];
    }

    private static function service(ControlPlaneGateway $gateway): PublicChannelsService
    {
        return new PublicChannelsService(
            $gateway,
            new ArrayContractCache,
            new AlwaysHuman,
            new AlwaysAllowed,
            new NullPublicChannelLogger,
        );
    }

    /** @param array<string, mixed> $body */
    private static function jsonResponse(int $status, array $body): RemoteResponse
    {
        return new RemoteResponse($status, (string) json_encode($body, JSON_THROW_ON_ERROR));
    }
}

final class CallbackGateway implements ControlPlaneGateway
{
    /** @var list<array{method: string, path: string}> */
    public array $requests = [];

    public function __construct(private readonly \Closure $callback) {}

    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $query = [],
        bool $bindOrigin = true,
        int $maxResponseBytes = 1_048_576,
    ): RemoteResponse {
        $this->requests[] = ['method' => $method, 'path' => $path];

        return ($this->callback)($method, $path, $json, $query, $bindOrigin, $maxResponseBytes);
    }
}

final class ArrayContractCache implements ContractCache
{
    /** @var array<string, array<string, mixed>> */
    private array $values = [];

    public function get(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->values[$key] = $value;
    }
}

final class AlwaysHuman implements HumanVerification
{
    public function verify(string $token, string $remoteIp): bool
    {
        return $token === 'verified-token';
    }
}

final class AlwaysAllowed implements RateLimiter
{
    public function allow(string $bucket, string $source, int $limit, int $windowSeconds): bool
    {
        return true;
    }
}

final class NeverAllowed implements RateLimiter
{
    public function allow(string $bucket, string $source, int $limit, int $windowSeconds): bool
    {
        return false;
    }
}

final class NeverHuman implements HumanVerification
{
    public function verify(string $token, string $remoteIp): bool
    {
        return false;
    }
}

final class FailingRateLimiter implements RateLimiter
{
    public function allow(string $bucket, string $source, int $limit, int $windowSeconds): bool
    {
        throw new \RuntimeException('rate limiter offline');
    }
}

final class NullPublicChannelLogger implements PublicChannelLogger
{
    public function warning(string $event, array $context = []): void {}
}

final class ArrayPublicComponentSettingsStore implements PublicComponentSettingsStore
{
    private ?bool $chat;

    public function __construct(?bool $chat = null)
    {
        $this->chat = $chat;
    }

    public function get(PublicComponentType $type): PublicComponentPlacement
    {
        return new PublicComponentPlacement($type, $this->chat ?? true);
    }

    public function save(PublicComponentPlacement $placement): void
    {
        $this->chat = $placement->autoInject;
    }
}

final class RecordingTransport implements HttpTransport
{
    /** @var array<string, mixed> */
    public array $request = [];

    public function send(
        string $method,
        string $url,
        array $headers,
        string $body,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): RemoteResponse {
        $this->request = compact('method', 'url', 'headers', 'body', 'timeoutSeconds', 'maxResponseBytes');

        return new RemoteResponse(200, '{}');
    }
}
