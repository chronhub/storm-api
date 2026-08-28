# Storm Api

**HTTP exposure via the API Platform bridge** — plug API Platform 4.3 on a Storm application:
state providers ask the Story `QueryBus`, state processors dispatch on `storm.command.bus`. The
name follows the capability — this is *Storm's API exposure* — but the content promises **no
genericity**: it is 100 % API Platform vocabulary, with no anti-corruption layer on purpose
(YAGNI — swapping the dependency would be a rewrite of this module, not a swap). Not to be
confused with the framework's *operator surface* (console, recipes): this module is the HTTP
bridge only.

## The rule that shapes everything

The bridge is a **strict leaf** (deptrac-enforced): it depends on `Story` + `Contracts` +
`api-platform/symfony`, and **nobody depends on it**. It sees the world through the two buses
only — never Chronicler/Projector/Aggregate directly. A read that "would need" the store is a
**missing query**, not an exception to the rule.

## What the bridge solves (and plain plumbing does not)

Wiring providers/processors is trivial; the module's value is the three problems API Platform
does not solve outside Doctrine, answered event-sourced:

1. **Freshness** — an async command makes "dispatch then re-read" a lie. The contract is
   declared per operation and **verified at container compile time**; the *mechanism* of a fresh
   read is a pluggable strategy (`WriteReceipt` + `FreshnessStrategy`) — no projection is
   synchronous in Storm, so honesty is a strategy, never an assumption. Async → `202` +
   `correlation_id`, always.
2. **Cache invalidation** — only the projector knows a read model changed: invalidation is
   projection-driven (purge at projection commit), ETag first (`version` column, opt-in
   declared).
3. **Error mapping** — domain → RFC 9457 Problem Details by **boundary markers**
   (`ConcurrencyException` → 409, validation → 422, provider `null` → 404, the rest → 500
   opaque), app exceptions by `exceptionToStatus` declaration. The domain never learns HTTP.

## The read side

`State\QueryProvider` is the template method of the read side: one base per app resource,
two hooks — the app names the read and owns the mapping, the base holds the invariants.

```php
final class AccountProvider extends QueryProvider
{
    protected function queryFor(Operation $operation, array $uriVariables, array $context): object
    {
        return $operation instanceof CollectionOperationInterface
            ? new ListAccounts(/* filters/pagination parsed HERE, carried in the query */)
            : new FindAccount($uriVariables['id']);
    }

    protected function toResource(mixed $readModel): object
    {
        return AccountResource::fromReadModel($readModel); // explicit mapping, never a naked read model
    }
}
```

`provide()` is final on purpose: a missing item stays `null` (API Platform's native 404), a
collection is re-keyed as a list and mapped item by item (a read model is never exposed naked),
and a handler failure travels out **still wrapped** — the single unwrapping point is the error
boundary below, not each provider.

### Pagination — the recipe (nothing is automatic outside Doctrine)

The provider owns pagination; the rule is the same as ownership — **in the query, never on the
result**:

- **Default: cursor over a stable order.** An event-sourced read model moves under the reader;
  with `OFFSET`, page 2 skips or repeats the rows written between two calls. Order by a stable
  key pair (`sort_key, id`), return the last row's pair as an opaque cursor, and read
  `WHERE (sort_key, id) > (:after, :id) LIMIT :n`.
- **Offset is fine when the list is small, bounded and slow-moving** (ops/admin screens) — the
  drift window is real but harmless there; say so in the resource, don't default to it.
- Parse the parameters in `queryFor` and carry them in the query DTO. Post-filtering or slicing
  the handler's result in the provider is the read-side equivalent of post-filtering ownership:
  wrong by shape, even when the numbers look right.

### The app-side minimum (hardened defaults prepended)

The bundle **prepends** the hardened defaults — every one an opinion the app's own config
overrides, none a cage:

| Prepended default | Why |
|---|---|
| `strict_query_parameter_validation: true` | an unknown query parameter is a 400, never silently ignored |
| `extra_properties.throw_on_access_denied: true` | a **write** to a property the caller may not change is an explicit 403, never a silent revert-and-2xx (the flag drives the post-denormalize check, `securityPostDenormalize`); reads stay per-role shaping. ⚠️ Only on a resource-as-input: custom input DTOs ride the plain ObjectNormalizer and property security is NEVER evaluated there |
| `formats: { json }` | a CQRS bridge needs no Hydra vocabulary; one line re-enables it |
| `doctrine: false` | the bridge never rides the ORM — and letting it auto-enable breaks any app without `api-platform/doctrine-orm` |
| `framework.property_access` on | opt-in outside the full-stack; the item normalizer needs it |

So the documented app-side minimum shrinks to:

```yaml
api_platform:
    mapping: { paths: ['%kernel.project_dir%/src/<YourHttpLayer>'] }
```

Conventions that stay the app's job: ownership **in the query**, never post-filtered; a failed
ownership check is a **404, not a 403** (no existence oracle); reserved filters guarded by
`QueryParameter(security:)`; typed identifiers in input DTOs, resolved by the query.

## The error boundary

One listener is the whole boundary — `Error\ExceptionTranslator`, on `kernel.exception` above
API Platform's own listener. It unwraps Messenger's envelope (a handler failure travels
wrapped; a middleware failure arrives naked — both accepted), translates the framework
boundaries by marker, and leaves everything else naked:

| What surfaces | Becomes | Status |
|---|---|---|
| `ConcurrencyException` marker | `ApiProblem::conflict()` — versions never reach the body | 409 |
| Messenger `ValidationFailedException` | API Platform `ValidationException`, same violations | 422 + `violations[]` |
| Provider returns `null` | native API Platform | 404 |
| App domain exception | its **declared** `exceptionToStatus` on the resource (declaring is the opt-in that sends the message into `detail`) | app's choice |
| Everything else (`StorageFailure` included) | opaque problem, detail masked outside debug | 500 |

Two facts worth knowing, both proven by the gate:

- A **sync** conflict is the *first* conflict: `RecoverConcurrencyConflict` only drives worker
  redeliveries and passes straight through in-process — 409 means "replay the request", and
  the bridge never retries either.
- The non-leak contract is a **production** (`debug=false`) statement: under debug API
  Platform ships the stack trace in the body by design.

## The write side (sync)

`State\CommandProcessor` is the template method of the write side — two hooks, the base holds
the invariants:

```php
final class WithdrawProcessor extends CommandProcessor
{
    protected function commandFor(mixed $data, Operation $op, array $uriVariables, array $context): object
    {
        // the voter has passed: fabricate the typed witness HERE — the sensitive
        // command is unconstructible without it (securite.md)
        return WithdrawCashMoney::with($uriVariables['id'], $data->amount, $witness);
    }

    protected function refresh(WriteReceipt $receipt, Operation $op, array $uriVariables, array $context): ?object
    {
        // the same Story query the GET provider asks, mapped by the explicit factory
    }
}
```

The invariants: the bridge **never retries** (a sync conflict is the FIRST conflict — 409,
the client replays); a declared-fresh operation must dispatch the command it declared;
not-fresh-in-time is an honest **202 + `correlation_id`**, never a stale 200; an undeclared
sync write is a **204**; and a write that **left on the wire** (the returned envelope carries a
`SentStamp` toward a real transport, even beside a sync route's `HandledStamp` on a
double-routed command) is a **202 + `correlation_id` whatever the operation declared** — the
honest async answer, and the runtime backstop of the compile-time gate against the
`TransportNamesStamp` bypass. Declare async operations truthfully:
`status: 202, output: false`.

### Freshness is a declared contract with a pluggable mechanism

The operation declares it — and the container refuses the impossible promise at compile time
(`GuardFreshnessTopologyPass`: declared fresh × command routed to a transport = build error):

```php
new Post(uriTemplate: '/accounts/{id}/withdraw', status: 200, input: WithdrawInput::class,
    processor: WithdrawProcessor::class,
    extraProperties: [ReadAfterWrite::KEY => [
        'command' => WithdrawCashMoney::class,   // the promise names its command
        'projection' => 'account_balance',       // what "fresh" waits on
    ]])
```

The *mechanism* is a strategy (`Freshness\FreshnessStrategy`): the app elects its default with
ONE alias, any operation may name its own (`strategy` key) — the framework elects nothing.
Shipped: **(b)** `AtHeadFreshness`, token-less read-your-writes over the contracted
`ProjectionFreshness` port (wait until the projection catches the head — every write committed
before the wait is covered); **(d)** `HandlerResultFreshness`, the handler's own return served
without re-reading. Row-version and inline-fold remain app-side implementations of the same seam.

**Recommended default, measured: the app-side row-version strategy** — poll the resource's own
`version` property through the re-read until it reaches the version the handler returned. It is
never worse than at-head and wins in the tail: the head is a MOVING target (background appends
alone inflated at-head's p95 by ~45%, concurrent writes by ~40%, measured), while a row version
is a fixed one; and each poll is a trivial PK read instead of a safe-head scan. Its price is two
app contracts: the handler returns the written version, the resource exposes the very `version`
column the ETag already declares — one convention, three usages. `AtHeadFreshness` stays the
zero-convention choice when neither contract is wanted.

Input DTOs: give the POST input **no field named `id`** — API Platform's item normalizer
treats the resource identifier in a POST body as an update and refuses it.

## The cache contract

The operation declares, the bridge stamps, the projector purges:

```php
new Get(uriTemplate: '/books/{id}', extraProperties: [ResourceCache::KEY => [
    'etag_property' => 'version',    // the SAME version-column convention freshness strategy (a) rides
    'projection' => 'book_catalog',  // whose commit invalidates this response
]])
```

- **Tier 1, zero infra**: the declared property becomes `ETag: "v<n>"`; If-None-Match
  revalidation answers **304** (Symfony native). Items only — a collection has no single
  version (API Platform's own body-hash ETag coexists untouched).
- **Tier 2, projector-driven**: responses carry the projection's surrogate key; the runner
  fires the contracted `ProjectionCommitListener` **after** a batch commits (and only when it
  applied something), and `ProjectionCommitPurger` purges that one tag through API Platform's
  `PurgerInterface` — alias it to your configured purger, one line:
  `ApiPlatform\HttpCache\PurgerInterface: '@api_platform.http_cache.purger.souin'`.
  No purger → no-op; purger down → logged, stale until TTL — never a projection failure.
- **Coarse by design** (the advertised price): any commit purges every response of the
  projection. Per-key precision needs the projection to report touched keys — deferred behind
  a real trigger.
- **Tier 2 needs a storable response, and storability is YOURS**: the bridge stamps the tag but
  never the `Cache-Control` that lets a shared cache store the entry — declare `cacheHeaders`
  (e.g. `shared_max_age`) on the operation or in your `defaults`, or configure the surrogate
  cache to store on its own rules. Until then the purge tier is declared but inert and
  correctness rides revalidation + TTL.
- **Shared entries are keyed by identity by default**: with the projection tag the bridge also
  merges `Vary: Authorization, Cookie`, so the role-shaped or owner-shaped representation the
  write side's `throw_on_access_denied` posture leaves to "per-role shaping" never gets served
  to a different caller. A genuinely identity-free read opts out with `'vary' => []`; a chosen
  list replaces the default.

## Proven by the integration gates

Everything above is observed, not promised: the 304 with an empty body and the purge fired by a
real projection commit (none by a no-op batch); the hardened defaults proven by subtraction (the
fixture kernels drop them and every suite passes unchanged); the write side all-real — a 200
served fresh with the projection daemon catching up in its own process, the honest 202, a genuine
CAS race answered 409, the compile-time topology refusal; and the error table covered end to end
on a `debug=false` boot.

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Pre-version: this package changes without deprecation cycles — pin a commit if you need
stability, expect resets rather than migrations until the first tagged version.*
