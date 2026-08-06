<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use JsonException;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookGatewayContract;
use Throwable;

/**
 * The HTTP adapter `Route::hubspotWebhook()` (see {@see RouteRegistrar}) routes every inbound
 * request to. **Verify before parse, enqueue before acknowledge** (05-RESEARCH.md Pattern 1): the
 * raw signature is checked against the raw body and raw URI before a single byte of JSON is
 * decoded, and every validated item is handed off to the queue before this returns 204 — a batch
 * this method acknowledges is a batch it fully queued, never a partially-dispatched one (D-14).
 *
 * This plan (05-01) ships the deterministic 401/400/500/204 shape for the tracer path. The D-15
 * unsigned-local-development bypass and its payload-free warning, and the fuller safe-diagnostic
 * logging this class's failure branches carry, are a later task's concern (05-01-PLAN.md Task 3).
 *
 * `final`; the route's only invokable target, with no documented extension point (STANDARDS §8).
 */
final class WebhookController
{
    public function __construct(
        private readonly WebhookGatewayContract $gateway,
        private readonly Dispatcher $bus,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (! $this->verified($request)) {
            return new Response('', 401);
        }

        try {
            $events = $this->normalize($request->getContent());
        } catch (InvalidArgumentException) {
            return new Response('', 400);
        }

        try {
            foreach ($events as $event) {
                $this->bus->dispatch(new ProcessWebhookEventJob($event));
            }
        } catch (Throwable) {
            // A dispatch failure escapes the loop here rather than being swallowed per item: D-14
            // forbids acknowledging a batch this package did not fully hand off to the queue, and
            // HubSpot retries a 500 in full.
            return new Response('', 500);
        }

        return new Response('', 204);
    }

    /**
     * `$request->fullUrl()` is never used here: Symfony sorts its query parameters when building
     * it, and HubSpot signs the raw, unsorted request URI (AGENTS.md, PROJECT.md "Protocol —
     * webhook signature"). `$request->server->get('REQUEST_URI')` is the framework's untouched
     * copy of what the client actually sent.
     */
    private function verified(Request $request): bool
    {
        /** @var string $requestUri */
        $requestUri = $request->server->get('REQUEST_URI', '');

        return $this->gateway->verify(
            method: $request->getMethod(),
            uri: $request->getSchemeAndHttpHost().$requestUri,
            rawBody: $request->getContent(),
            signatureVersion: (string) $request->headers->get('X-HubSpot-Signature-Version', 'v3'),
            signature: (string) $request->headers->get('X-HubSpot-Signature', ''),
            timestamp: $request->headers->get('X-HubSpot-Request-Timestamp'),
        );
    }

    /**
     * Decodes and normalizes the whole batch before dispatching any of it, so an invalid item maps
     * to 400 (D-13) rather than a queue-handoff failure mapping to 500 for the wrong reason.
     *
     * @return list<NormalizedWebhookEvent>
     *
     * @throws InvalidArgumentException if the body is not valid JSON, not a JSON array, or
     *                                  contains an item `NormalizedWebhookEvent::fromArray()`
     *                                  rejects
     */
    private function normalize(string $rawBody): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The webhook body is not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new InvalidArgumentException('The webhook body must be a JSON array of events.');
        }

        return array_map(static function (mixed $item): NormalizedWebhookEvent {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Every webhook event must be a JSON object.');
            }

            /** @var array<string, mixed> $item */
            return NormalizedWebhookEvent::fromArray($item);
        }, $decoded);
    }
}
