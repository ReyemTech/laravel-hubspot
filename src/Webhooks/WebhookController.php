<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
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
 * The deterministic status mapping (D-13, D-14): 401 for a missing, stale, or invalid signature
 * (before any decoding, event emission, handler work, or dispatch); 400 for a validly signed body
 * that is not valid JSON, not a JSON array, or carries an invalid item; 500 if any item fails to
 * reach the queue, so HubSpot's own retry redelivers the whole batch rather than this package
 * silently acknowledging a partial handoff; 204 only once every item is durably queued.
 *
 * `ConfigRepository` (an `Illuminate\Contracts` type, not the bare `config()` helper) is what reads
 * `hubspot.webhooks.enforce` for D-15's local-development bypass: `config()` is declared,
 * unnamespaced, in `Illuminate\Foundation\helpers.php`, a root this package does not declare — the
 * identical reason `response()` was replaced with `new Response(...)` in this same class during
 * 05-01's tracer task. A namespaced `Illuminate\Contracts\*` type is already admitted by R4's
 * widened allow-list with no further amendment needed.
 *
 * Every log call in this class carries a short, static reason code, a route path, and (where
 * meaningful) an item count — never the raw body, the signature header, or the configured secret
 * (STANDARDS §10; `tests/Arch/WebhookBoundaryTest.php` pins this over the shipped tree).
 *
 * `final`; the route's only invokable target, with no documented extension point (STANDARDS §8).
 */
final class WebhookController
{
    public function __construct(
        private readonly WebhookGatewayContract $gateway,
        private readonly Dispatcher $bus,
        private readonly ConfigRepository $config,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (! $this->verified($request)) {
            return new Response('', 401);
        }

        // The DISPATCH half of `hubspot.disabled`, which config/hubspot.php writes as "checked at
        // DISPATCH and again on the WORKER" for both directions. `ProcessWebhookEventJob` holds the
        // worker half, which is what stops items already queued when the switch was thrown; it
        // cannot stop new ones arriving, so without this a disabled deployment kept decoding and
        // enqueuing every delivery for the length of an incident.
        //
        // 500 for the same reason the receipt flag below returns one, and the two are deliberately
        // the same shape: a 204 tells HubSpot the event is handled and it is never re-sent, so
        // acknowledging during an outage destroys exactly the events the switch was thrown to
        // protect. Refuse what this deployment cannot process; never acknowledge it.
        //
        // AFTER verification, like every other guard here, so switch state stays invisible to an
        // unauthenticated caller.
        if ($this->config->get('hubspot.disabled') === true) {
            Log::warning('A HubSpot webhook was refused because the package kill switch is on.', [
                'error_code' => 'package_disabled',
                'route' => $request->path(),
                'fix' => 'Set HUBSPOT_DISABLED=false to resume receiving webhooks.',
            ]);

            return new Response('', 500);
        }

        // Receipt needs the durable store: D-01 makes dedupe durable and HOOK-01 requires a
        // redelivered eventId to be handled exactly once, which nothing can provide without it.
        // So refuse HERE rather than accepting and failing in the worker.
        //
        // 500, emphatically not 204. HubSpot treats 2xx as delivered and never re-sends, so
        // acknowledging work this deployment cannot perform DESTROYS the event; a 5xx is retried,
        // and an operator who then enables the feature receives the backlog instead of a silent
        // hole. Answering "accepted" for something that will fail is the one outcome with no
        // recovery.
        //
        // Placed AFTER verification on purpose: an unauthenticated caller must not be able to
        // probe whether a deployment has webhooks enabled. An unsigned request gets 401 either way.
        if ($this->config->get('hubspot.webhooks.enabled') !== true) {
            Log::error('A HubSpot webhook was rejected because receipt is disabled.', [
                'error_code' => 'webhooks_disabled',
                'route' => $request->path(),
                'fix' => 'Set HUBSPOT_WEBHOOKS=true and run `php artisan migrate`.',
            ]);

            return new Response('', 500);
        }

        try {
            $events = $this->decodeBatch($request->getContent());
        } catch (InvalidArgumentException $exception) {
            Log::error('A HubSpot webhook request failed shape validation.', [
                'error_code' => $exception->getMessage(),
                'item_count' => $exception->getCode() ?: null,
                'route' => $request->path(),
            ]);

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
     * D-15: `HUBSPOT_WEBHOOK_ENFORCE=false` is a local-development bypass ONLY — it accepts an
     * unsigned (or wrongly signed) request rather than rejecting it, and warns loudly every time it
     * does, naming the bypass rather than the request. Enforcement defaults `true`; a consumer who
     * never sets the env var gets the fail-closed behaviour D-20 requires.
     *
     * `$request->fullUrl()` is never used here for the enforced path: Symfony sorts its query
     * parameters when building it, and HubSpot signs the raw, unsorted request URI (AGENTS.md,
     * PROJECT.md "Protocol — webhook signature"). `$request->server->get('REQUEST_URI')` is the
     * framework's untouched copy of what the client actually sent.
     */
    private function verified(Request $request): bool
    {
        if ($this->config->get('hubspot.webhooks.enforce', true) === false) {
            Log::warning(
                'UNSAFE: HUBSPOT_WEBHOOK_ENFORCE is false, so an unverified HubSpot webhook '
                .'request was accepted without checking its signature. This bypass exists for '
                .'local development only and must never be set in a deployed environment.',
                ['route' => $request->path()],
            );

            return true;
        }

        /** @var string $requestUri */
        $requestUri = $request->server->get('REQUEST_URI', '');

        $signatureVersion = (string) $request->headers->get('X-HubSpot-Signature-Version', 'v3');

        // The digest lives in a DIFFERENT header per version, verified against HubSpot's live
        // documentation on 2026-08-07 rather than recall:
        // https://developers.hubspot.com/docs/guides/apps/authentication/validating-requests
        //
        //   v3      -> X-HubSpot-Signature-v3
        //   v1, v2  -> X-HubSpot-Signature
        //
        // Reading the legacy header while defaulting the version to v3 meant a genuine delivery
        // presented an empty signature and was rejected 401 -- every request, in production only.
        // The suite could not see it: its own fixtures signed into the header the controller read,
        // so implementation and tests agreed with each other and with nothing else.
        $signature = $signatureVersion === 'v3'
            ? (string) $request->headers->get('X-HubSpot-Signature-v3', '')
            : (string) $request->headers->get('X-HubSpot-Signature', '');

        return $this->gateway->verify(
            method: $request->getMethod(),
            uri: $request->getSchemeAndHttpHost().$requestUri,
            rawBody: $request->getContent(),
            signatureVersion: $signatureVersion,
            signature: $signature,
            timestamp: $request->headers->get('X-HubSpot-Request-Timestamp'),
        );
    }

    /**
     * Decodes and normalizes the whole batch before dispatching any of it, so an invalid item maps
     * to 400 (D-13) rather than a queue-handoff failure mapping to 500 for the wrong reason. An
     * empty JSON array (`[]`) is a valid, zero-item batch: it dispatches nothing and still returns
     * 204, since HubSpot delivering nothing to act on is not a shape failure.
     *
     * The thrown exception's message is always one of the three static reason codes below, never
     * body-derived text, and its code carries the decoded item count when one is meaningful (0/null
     * otherwise) — both safe to log directly.
     *
     * @return list<NormalizedWebhookEvent>
     *
     * @throws InvalidArgumentException with message `invalid_json`, `not_a_json_array`, or
     *                                  `invalid_item`
     */
    private function decodeBatch(string $rawBody): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('invalid_json', previous: $exception);
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new InvalidArgumentException('not_a_json_array');
        }

        $events = [];

        foreach ($decoded as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('invalid_item', count($decoded));
            }

            try {
                /** @var array<string, mixed> $item */
                $events[] = NormalizedWebhookEvent::fromArray($item);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('invalid_item', count($decoded));
            }
        }

        return $events;
    }
}
