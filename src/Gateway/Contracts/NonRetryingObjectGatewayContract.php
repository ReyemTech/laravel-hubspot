<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway\Contracts;

/**
 * The object gateway for writes that must never be repeated (SYNC-04, Codex PR #49).
 *
 * Identical surface to {@see ObjectGatewayContract} -- it adds no method and overrides none. What
 * it names is a property of the TRANSPORT underneath: this one does not carry the SDK's
 * internal-errors retry middleware, so a 5xx or a timeout is raised rather than repeated.
 *
 * ## Why that distinction is a type rather than an argument
 *
 * The safety of repeating a request is a property of the OPERATION, and the operation is chosen by
 * the caller. `upsert()` converges, so repeating it is free (D-11); `create()` does not, so
 * repeating it after an ambiguous failure can leave two ACTIVE CRM objects for one model. Expressing
 * that as a flag on a shared gateway would put the decision at every call site and make it
 * forgettable; expressing it as the type a job asks for makes the container answer it once, at
 * resolution, in a way `Sync\RecreateHubspotObjectJob` cannot get wrong.
 *
 * **The rate-limit retry is deliberately kept.** A 429 says the request was refused, so repeating
 * it cannot duplicate anything, and dropping it would trade a real duplication risk for a real
 * availability one. Only the retry that fires on failures which say nothing about whether the write
 * landed is removed.
 *
 * **The guarantee lives in the BINDING, not in the implementing class.** `ObjectGateway` implements
 * both contracts -- it is `final`, and a decorator reimplementing eleven delegating methods to
 * express one transport difference would be more surface for less clarity -- so what makes an
 * instance of this type retry-free is `ServiceProvider::register()` handing it a factory built with
 * `retryInternalErrors: false`. That one line is what `GatewayBindingsTest` asserts, and it is the
 * only thing standing between a recreate and a duplicate object.
 *
 * Under `Hubspot::fake()` this resolves against the same mock transport every other gateway does:
 * a caller-supplied client carries no retry middleware to begin with, which
 * `HubspotClientFactory::forTransport()` has always relied on so `assertRequestCount()` stays
 * exact.
 */
interface NonRetryingObjectGatewayContract extends ObjectGatewayContract {}
