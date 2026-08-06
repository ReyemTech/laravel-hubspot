# API Coverage — HubSpot Webhooks

> Full coverage by default. Opt-outs are explicit, reasoned decisions.
>
> Scope: the HubSpot **webhooks** surface only (inbound HTTP delivery + app-level subscription
> management), not the whole HubSpot API. Reasons marked *(D-NN)* carry an existing decision from
> `05-CONTEXT.md` rather than a new one invented here.

| capability | decision | reason |
|---|---|---|
| inbound-delivery-http-post | INTEGRATE | |
| signature-validation-v3 | INTEGRATE | |
| signature-validation-legacy-v1-v2 | OPT-OUT | v3 is the scheme this package's raw-URI contract is specified against (ROADMAP SC2); a second, weaker verification path has no decided consumer. |
| signature-timestamp-tolerance | INTEGRATE | |
| event-batching-up-to-100 | INTEGRATE | |
| delivery-retry-on-4xx-5xx | INTEGRATE | |
| redelivery-deduplication-by-event-id | INTEGRATE | |
| occurred-at-staleness-affordance | INTEGRATE | |
| subscription-type-object-created | INTEGRATE | |
| subscription-type-object-deleted | INTEGRATE | |
| subscription-type-object-property-changed | INTEGRATE | |
| subscription-type-object-association-changed | INTEGRATE | |
| subscription-type-conversations | OPT-OUT | Conversations / Marketing / CMS APIs are deferred to v2 by the project's own scope decision (STATE.md Deferred Items). |
| subscription-list-legacy-public-app | INTEGRATE | |
| subscription-create-legacy-public-app | INTEGRATE | |
| subscription-update-legacy-public-app | INTEGRATE | |
| subscription-delete | OPT-OUT | D-11 forbids destructive removal; a delete lands on every account that installed the HubSpot app. |
| subscription-report-unmanaged-extras | INTEGRATE | |
| app-settings-target-url-write | OPT-OUT | The delivery URL belongs to the consumer's app configuration; rewriting a live app's target would redirect production traffic for every installed account. |
| app-settings-throttling-write | OPT-OUT | Same blast radius as the target URL, and no decision in 05-CONTEXT.md asks for runtime throttle management. |
| auth-developer-api-key-plus-app-id | INTEGRATE | |
| auth-service-key | OPT-OUT | D-16: HubSpot Service Keys are never accepted as webhook-management credentials. |
| legacy-private-app-manual-setup | INTEGRATE | |
| project-app-webhook-component-export | INTEGRATE | |
| webhook-journal-pull-api | OPT-OUT | D-16: a distinct pull-based capability, explicitly out of scope for this phase. |

## Notes

- `signature-validation-legacy-v1-v2` is the only opt-out that could plausibly be re-opened inside
  v1. If a consumer reports a HubSpot app model that delivers only `X-HubSpot-Signature` v1/v2, the
  `Gateway\Contracts\WebhookGatewayContract` already carries a signature *version* argument
  (05-01), so admitting it is an adapter change inside `Gateway` and not a redesign.
- The three `legacy-private-app-manual-setup`, `project-app-webhook-component-export` and
  `auth-developer-api-key-plus-app-id` rows are the three D-16 app-model paths. They are
  `INTEGRATE` as far as HubSpot permits: HubSpot exposes no subscription-management API for legacy
  private apps, so full coverage there means rendered, validated manual instructions rather than a
  remote call.
