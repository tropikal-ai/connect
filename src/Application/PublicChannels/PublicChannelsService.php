<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels;

use TropikalAI\Connect\Application\PublicChannels\Ports\ContractCache;
use TropikalAI\Connect\Application\PublicChannels\Ports\ControlPlaneGateway;
use TropikalAI\Connect\Application\PublicChannels\Ports\HumanVerification;
use TropikalAI\Connect\Application\PublicChannels\Ports\PublicChannelLogger;
use TropikalAI\Connect\Application\PublicChannels\Ports\PublicComponentSettingsStore;
use TropikalAI\Connect\Application\PublicChannels\Ports\RateLimiter;
use TropikalAI\Connect\Domain\PublicChannels\AvailabilityQuery;
use TropikalAI\Connect\Domain\PublicChannels\BookingRequest;
use TropikalAI\Connect\Domain\PublicChannels\ContractValidator;
use TropikalAI\Connect\Domain\PublicChannels\PublicComponentType;
use TropikalAI\Connect\Domain\PublicChannels\RouteKey;

final readonly class PublicChannelsService
{
    private const CHAT_INFO_PATH = '/api/connect-filament/embed/info';

    private const CHAT_PATH = '/api/connect-filament/embed/chat';

    private const CHAT_SESSION_PATH = '/api/connect-filament/embed/session';

    private const EMBED_ASSET_PATH = '/embed';

    private const JOB_ROUTES_PATH = '/api/connect-filament/job-routes';

    private const ASSETS = [
        'chat-widget.js' => 'application/javascript; charset=utf-8',
        'iframe.html' => 'text/html; charset=utf-8',
        'iframe.js' => 'application/javascript; charset=utf-8',
        'iframe.css' => 'text/css; charset=utf-8',
    ];

    public function __construct(
        private ControlPlaneGateway $gateway,
        private ContractCache $contracts,
        private HumanVerification $humanVerification,
        private RateLimiter $rateLimiter,
        private PublicChannelLogger $logger,
        private PublicChannelsConfig $config = new PublicChannelsConfig,
        private ?PublicComponentSettingsStore $componentSettings = null,
    ) {}

    public function chatInfo(): PublicResponse
    {
        if (! $this->chatPlacementEnabled()) {
            return $this->chatNotEnabled();
        }

        try {
            return $this->proxyJson($this->gateway->request('GET', self::CHAT_INFO_PATH));
        } catch (\Throwable $exception) {
            $this->warn('connect.public_chat.info_failed', $exception);

            return PublicResponse::json(503, [
                'error' => 'chat_unavailable',
                'message' => 'Website chat is temporarily unavailable.',
            ]);
        }
    }

    /**
     * Restore a visitor's own transcript from an opaque resume capability.
     *
     * The token is passed straight through as a query parameter; this adapter
     * never inspects or stores it. The control plane returns the same empty
     * body for an absent, forged, or expired capability, so there is nothing
     * here to branch on and nothing to leak.
     */
    public function chatSession(string $resumeToken): PublicResponse
    {
        if (! $this->chatPlacementEnabled()) {
            return $this->chatNotEnabled();
        }

        try {
            return $this->proxyJson(
                $this->gateway->request('GET', self::CHAT_SESSION_PATH, null, ['resume_token' => $resumeToken])
            );
        } catch (\Throwable $exception) {
            $this->warn('connect.public_chat.session_failed', $exception);

            // Degrade to "greet as new" rather than surfacing a failure: a
            // transcript that cannot be restored must never block the chat.
            return PublicResponse::json(200, [
                'session_id' => '',
                'messages' => [],
            ]);
        }
    }

    /** @param array<string, mixed> $body */
    public function chat(array $body): PublicResponse
    {
        if (! $this->chatPlacementEnabled()) {
            return $this->chatNotEnabled();
        }

        try {
            return $this->proxyJson($this->gateway->request('POST', self::CHAT_PATH, $body));
        } catch (\Throwable $exception) {
            $this->warn('connect.public_chat.request_failed', $exception);

            return PublicResponse::json(503, [
                'error' => 'chat_unavailable',
                'message' => 'Website chat is temporarily unavailable.',
            ]);
        }
    }

    /** Proxy only immutable, declared Ops embed assets. */
    public function embedAsset(string $name, string $version): PublicResponse
    {
        $contentType = self::ASSETS[$name] ?? null;
        if ($contentType === null || preg_match('/^[A-Za-z0-9._-]{1,80}$/', $version) !== 1) {
            return new PublicResponse(404, 'Not found.', [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        try {
            $response = $this->gateway->request(
                'GET',
                self::EMBED_ASSET_PATH.'/'.$name,
                query: ['v' => $version],
                bindOrigin: false,
                maxResponseBytes: $this->config->assetMaxBytes,
            );
            if ($response->status < 200 || $response->status >= 300) {
                throw new PublicChannelException('embed_asset_unavailable', 502);
            }

            return new PublicResponse(200, $response->body, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable $exception) {
            $this->warn('connect.public_chat.asset_failed', $exception, ['asset' => $name]);

            return new PublicResponse(502, 'Connect embed asset unavailable.', [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        }
    }

    /** @param array<string, mixed> $query */
    public function availability(array $query, string $source): PublicResponse
    {
        try {
            if (! $this->rateLimiter->allow('booking-availability', $source, 60, 60)) {
                return PublicResponse::json(429, ['error' => 'rate_limited']);
            }
            $request = AvailabilityQuery::fromInput(
                $query,
                $this->config->businessTimezone,
                $this->config->slotMinutes,
                $this->config->durationMinutes,
                $this->config->maxAdvanceDays,
            );
            $payload = $request->payload();
            $this->assertPayloadMatchesContract($this->config->availabilityRouteKey, $payload);
            $response = $this->invokeSync($this->config->availabilityRouteKey, $payload);
            $output = $response['output'] ?? null;
            if (! is_array($output) || ! is_array($output['slots'] ?? null) || ! is_string($output['timezone'] ?? null)) {
                throw new PublicChannelException('availability_output_invalid', 502);
            }

            return PublicResponse::json(200, [
                'timezone' => $output['timezone'],
                'slots' => array_values(array_filter($output['slots'], static fn (mixed $slot): bool => is_array($slot))),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return PublicResponse::json(422, ['error' => 'invalid_availability_query', 'message' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            $this->warn('connect.booking.availability_failed', $exception);

            return PublicResponse::json(503, ['error' => 'availability_unavailable']);
        }
    }

    /** @param array<string, mixed> $input */
    public function book(array $input, string $remoteIp): PublicResponse
    {
        try {
            if (! $this->rateLimiter->allow('booking-create', $remoteIp, 6, 60)) {
                return PublicResponse::json(429, ['error' => 'rate_limited']);
            }
            $booking = BookingRequest::fromInput($input);
            if (! $this->humanVerification->verify($booking->turnstileToken, $remoteIp)) {
                return PublicResponse::json(422, [
                    'error' => 'human_verification_failed',
                    'message' => 'Please complete the human verification again.',
                ]);
            }
            $payload = $booking->payload();
            $this->assertPayloadMatchesContract($this->config->bookingRouteKey, $payload);
            $response = $this->invokeSync($this->config->bookingRouteKey, $payload);
            $output = $response['output'] ?? null;
            if (! is_array($output)) {
                throw new PublicChannelException('booking_output_invalid', 502);
            }

            return $this->bookingResult($output);
        } catch (\InvalidArgumentException $exception) {
            return PublicResponse::json(422, ['error' => 'invalid_booking', 'message' => $exception->getMessage()]);
        } catch (PublicChannelException $exception) {
            $this->warn('connect.booking.request_failed', $exception, ['error' => $exception->errorCode]);

            return PublicResponse::json($exception->httpStatus, [
                'error' => $exception->errorCode,
                'message' => 'The booking could not be completed.',
            ]);
        } catch (\Throwable $exception) {
            $this->warn('connect.booking.request_failed', $exception);

            return PublicResponse::json(503, [
                'error' => 'booking_unavailable',
                'message' => 'The booking service is temporarily unavailable.',
            ]);
        }
    }

    public function bookingStatus(string $bookingUuid, string $source): PublicResponse
    {
        if (preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $bookingUuid) !== 1) {
            return PublicResponse::json(422, ['error' => 'invalid_booking_id']);
        }

        try {
            if (! $this->rateLimiter->allow('booking-status', $source, 60, 60)) {
                return PublicResponse::json(429, ['error' => 'rate_limited']);
            }
            $path = self::JOB_ROUTES_PATH.'/'.rawurlencode($this->config->bookingRouteKey).'/status';

            return $this->proxyJson($this->gateway->request('GET', $path, query: ['booking_uuid' => $bookingUuid]));
        } catch (\Throwable $exception) {
            $this->warn('connect.booking.status_failed', $exception);

            return PublicResponse::json(503, ['error' => 'booking_status_unavailable']);
        }
    }

    public function health(string $assetVersion): PublicResponse
    {
        $chat = $this->chatInfo();
        $chatBody = json_decode($chat->body, true);
        $chatState = $chat->status === 200 && is_array($chatBody) && isset($chatBody['channel_id'])
            ? 'active'
            : (($chatBody['error'] ?? '') === 'chat_not_enabled' ? 'not_enabled' : 'unavailable');
        $availabilityState = $this->routeState($this->config->availabilityRouteKey);
        $bookingState = $this->routeState($this->config->bookingRouteKey);
        $booking = in_array('unavailable', [$availabilityState, $bookingState], true)
            ? 'unavailable'
            : ($availabilityState === 'active' && $bookingState === 'active' ? 'active' : 'not_enabled');

        return PublicResponse::json(200, [
            'status' => 'ok',
            'installation' => 'connected',
            'chat' => $chatState,
            'booking' => $booking,
            'asset_version' => $assetVersion,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function assertPayloadMatchesContract(string $routeKey, array $payload): void
    {
        $contract = $this->routeContract($routeKey);
        $route = is_array($contract['route'] ?? null) ? $contract['route'] : $contract;
        $inputContract = is_array($route['input_contract'] ?? null) ? $route['input_contract'] : [];
        $violations = ContractValidator::violations($inputContract, $payload);
        if ($violations !== []) {
            throw new PublicChannelException('connector_contract_mismatch', 503, ['count' => count($violations)]);
        }
    }

    /** @return array<string, mixed> */
    private function routeContract(string $routeKey): array
    {
        $routeKey = RouteKey::fromString($routeKey)->value;
        $cacheKey = 'connect.public-channel.contract.'.$routeKey;
        $cached = $this->contracts->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        $path = self::JOB_ROUTES_PATH.'/'.rawurlencode($routeKey).'/contract';
        $response = $this->gateway->request('GET', $path);
        $body = $this->successfulJson($response);
        $this->contracts->put($cacheKey, $body, $this->config->contractCacheSeconds);

        return $body;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function invokeSync(string $routeKey, array $payload): array
    {
        $routeKey = RouteKey::fromString($routeKey)->value;
        $path = self::JOB_ROUTES_PATH.'/'.rawurlencode($routeKey).'/invoke-sync';
        $response = $this->gateway->request('POST', $path, $payload);
        if ($response->status === 504) {
            throw new PublicChannelException('booking_pending', 202);
        }

        return $this->successfulJson($response);
    }

    /** @return array<string, mixed> */
    private function successfulJson(RemoteResponse $response): array
    {
        $payload = $response->json();
        if ($response->status < 200 || $response->status >= 300) {
            $error = is_string($payload['error'] ?? null) ? $payload['error'] : 'control_plane_unavailable';
            throw new PublicChannelException($error, $response->status >= 400 ? $response->status : 503);
        }
        if (($payload['_tropikal_connect'] ?? null) === true && is_array($payload['data'] ?? null)) {
            return $payload['data'];
        }
        if ($payload === []) {
            throw new PublicChannelException('control_plane_contract_invalid', 502);
        }

        return $payload;
    }

    private function proxyJson(RemoteResponse $response): PublicResponse
    {
        $payload = $response->json();
        if (($payload['_tropikal_connect'] ?? null) === true && is_array($payload['data'] ?? null)) {
            $payload = $payload['data'];
        }
        if ($payload === []) {
            throw new PublicChannelException('control_plane_contract_invalid', 502);
        }

        return PublicResponse::json($response->status, $payload);
    }

    /** @param array<string, mixed> $output */
    private function bookingResult(array $output): PublicResponse
    {
        $status = (string) ($output['status'] ?? '');
        $uuid = (string) ($output['booking_uuid'] ?? '');
        if ($uuid === '' || ! in_array($status, ['confirmed', 'unavailable', 'failed'], true)) {
            throw new PublicChannelException('booking_output_invalid', 502);
        }
        if ($status === 'confirmed') {
            if (trim((string) ($output['calendar_event_id'] ?? '')) === ''
                || filter_var((string) ($output['calendar_event_url'] ?? ''), FILTER_VALIDATE_URL) === false) {
                throw new PublicChannelException('booking_output_invalid', 502);
            }

            return PublicResponse::json(201, $output);
        }

        return PublicResponse::json($status === 'unavailable' ? 409 : 502, $output);
    }

    private function routeState(string $routeKey): string
    {
        try {
            $this->routeContract($routeKey);

            return 'active';
        } catch (PublicChannelException $exception) {
            return $exception->httpStatus === 404 ? 'not_enabled' : 'unavailable';
        } catch (\Throwable) {
            return 'unavailable';
        }
    }

    private function chatPlacementEnabled(): bool
    {
        return $this->componentSettings?->get(PublicComponentType::Chat)->autoInject ?? true;
    }

    private function chatNotEnabled(): PublicResponse
    {
        return PublicResponse::json(404, [
            'error' => 'chat_not_enabled',
            'message' => 'Website chat is not enabled for this site.',
        ]);
    }

    /** @param array<string, scalar|null> $context */
    private function warn(string $event, \Throwable $exception, array $context = []): void
    {
        $this->logger->warning($event, [
            ...$context,
            'exception' => $exception::class,
            'code' => $exception instanceof PublicChannelException ? $exception->errorCode : 'internal_error',
        ]);
    }
}
