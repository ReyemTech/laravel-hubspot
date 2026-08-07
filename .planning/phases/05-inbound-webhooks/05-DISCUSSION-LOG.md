# Phase 5: Inbound Webhooks - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-08-05
**Phase:** 5-inbound-webhooks
**Areas discussed:** Delivery deduplication, Event routing surface, Subscription sync, Request failures

---

## Delivery Deduplication

| Decision | Alternatives considered | Selected |
|----------|-------------------------|----------|
| Duplicate identity | Persistent event ID; cache event ID; audit table only | Persistent event ID |
| Opt-in | Webhooks enabled; explicit store mode; always load migration | Webhooks enabled |
| Completion point | After successful dispatch; before dispatch; configurable policy | After successful dispatch |
| Retention | Configurable retention; retain forever; short fixed window | Configurable retention |

## Event Routing Surface

| Decision | Alternatives considered | Selected |
|----------|-------------------------|----------|
| Handler payload | Normalized typed event; raw payload; both forms | Normalized typed event |
| Unknown event | Generic plus wildcard; ignore; fail delivery | Generic plus wildcard |
| Handler map | Event key to handlers; callable; global dispatcher | Event key to handlers |
| Handler error | Fail and retry; log and continue; per-handler isolation | Fail and retry |
| Generic event granularity | Per event; per delivery; both levels | Per event |
| Hook order | Generic, typed, handlers; handlers first; typed only | Generic, typed, handlers |
| Handler contract | Package interface; invokable; Laravel listener shape | Package interface |
| Typed-event breadth | Core semantic families; every type; generic only | Core semantic families |

## Subscription Sync

| Decision | Alternatives considered | Selected |
|----------|-------------------------|----------|
| Declaration | Explicit subscription list; infer from handlers; broad subscription | Explicit subscription list |
| Reconciliation | Add/update only; full reconciliation; deletion flag | Add/update only |
| Command default | Apply with dry-run; dry-run by default; interactive | Apply with dry-run |
| Validation timing | Sync-time; boot-time; silently skip | Sync-time |

## Request Failures

| Decision | Alternatives considered | Selected |
|----------|-------------------------|----------|
| Invalid signature | 401; 403; 204 | 401 |
| Malformed signed body | 400 and safe log; 204/drop; 500/retry | 400 and safe log |
| Queue handoff failure | 500/retry; 204/log; 202 | 500/retry |
| Enforcement disabled | Local-development bypass; disabled endpoint; permissive production | Local-development bypass |

## Agent's Discretion

- Exact names, data fields, defaults, schema indexes, and Gateway translation details.

## Deferred Ideas

- Destructive subscription pruning and broad typed-event expansion are outside this phase.
