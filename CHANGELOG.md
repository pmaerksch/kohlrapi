# Changelog

All notable changes to kohlrAPI. Versions follow [Semantic Versioning](https://semver.org/):
1.15.0 is fully backwards compatible with 1.14.0, and 2.1.0 with 2.0.0; 2.0.0 contains breaking
changes, listed under [Upgrading from 1.x](#upgrading-from-1x).

IDs such as S1 or E4 refer to findings of the code audits of 1.14.0 (S = security, E = error)
and of the first 2.0.0 draft (N). Commit hashes refer to this repository.

---

## 2.1.0

No breaking changes.

### Features

- **`notFoundResponse()`:** `404` with the key `api.errors.recordNotFound`, next to
  `accessDeniedResponse()`. Apps that wrote this response by hand in their own actions can
  use it instead. `handleUpdate()` uses it too; its response is unchanged.

---

## 2.0.0

### Upgrading from 1.x

Work through this list in order; most apps only need the first three steps.

1. **PHP 8.4 or newer.** This was already the real minimum, since DoctrineBundle 3 requires it;
   the package now says so.
2. **Restrict writable fields.** In every controller that calls `handleCreate()`,
   `handleUpdate()` or `handleBulk()`, override `getDenormalizationContext()` and return
   non-empty serializer `groups` (without `'*'`) or an `attributes` allow-list. To keep the 1.x
   behaviour for a controller, return `[self::WRITE_ALL_FIELDS => true]`. The 1.15 deprecation
   log lists every entity class that still needs this.
3. **Check your voters / `isAuthorized()` overrides.** Every create and update is now
   authorized a second time on the resulting entity (operations `create.after` /
   `update.after`). Bulk deletes are authorized request-wide with `bulkDelete` instead of
   `delete`. Handle or allow these operations, or return `false` from `reauthorizesWrites()`.
4. **Forbidden entities are `404`.** Clients that relied on `403` for an existing but
   forbidden entity now receive `404`, or `401`/`403` when the class-level check denies.
   `getEntity()` returns `null` for such an entity.
5. **Search limits.** Searches with an offset above 100 000, a term longer than 255
   characters, more than 50 filter constraints or more than 1000 `in` values are rejected
   with `400 api.errors.invalidSearch`. Override `getMaxOffset()`, `getMaxTermLength()`,
   `getMaxConstraints()` or `getMaxInValues()` to change or disable (`null`) a limit. If you
   call `getEntityList()` / `getRandomEntityList()` yourself, catch
   `ApiInvalidSearchException`.
6. **Frontend translations.** Add the new key `api.errors.invalidSearch`.
7. **Overridden signatures.** Subclasses overriding `errorResponse()` must add the
   `array $details = []` parameter; those overriding `handleUpdate()` the
   `?string $uuid = null` parameter.
8. **Removed API.** Replace `ApiEntity::updateFromEntity()` with explicit setters, remove
   named `distinct:` arguments from `searchRandom()` calls, and move calls to the write
   handlers from outside a controller into a controller action.
9. **Logger.** The package no longer pulls in `symfony/monolog-bundle`; require it in your
   app if you relied on that.

### Security

#### Writable fields must be restricted (S1, N1) — `b3c52f9` · breaking

The write handlers deserialized client payloads into the entity with no restriction, so a
client could set every property with a setter: `roles`, `owner`, `password`, and so on. Since
1.15.0 `getDenormalizationContext()` could restrict this. Now `handleCreate()`,
`handleUpdate()` and `handleBulk()` throw a `LogicException` unless it does.

- A restriction is non-empty `groups` without the `'*'` wildcard, or an `attributes` list.
  Empty groups and `'*'` are rejected because the serializer reads both as "every field"; the
  first 2.0 draft accepted them (N1).
- `ApiController::WRITE_ALL_FIELDS` allows every field on purpose. It must be `true`.
- The context is built before the handlers' `try` blocks, so a misconfiguration surfaces as
  an exception, not as a generic `500 saveFailed`. `handleBulk()` checks the `create` and
  `update` contexts up front, whatever the payload contains.
- `uuid` is always ignored.

#### The written state is authorized again by default (S2) — `b7a2c6b` · breaking

1.15.0 added `reauthorizesWrites()` as an opt-in. It now defaults to `true`: every create,
update and bulk item is authorized again after the payload has been applied and before
`flush()`, with operation `create.after` / `update.after` and the resulting entity as subject.
Without this, a payload could move an entity to an owner or tenant the user has no rights on.
A denial discards the changes and returns `401`/`403`.

#### Forbidden entities are answered like missing ones (S3) — `5b4521b` · breaking

The bulk handlers returned `404` for an unknown uuid but `403` for an existing, forbidden one,
so a client could probe which uuids exist. `handleFetch()` / `getEntity()` and
`handleUpdate()` leaked the same way whenever the class-level check allowed what the entity
check denied.

- A forbidden entity is now treated exactly like a missing one. Both fall back to the
  class-level check without a subject: `401`/`403` if that denies, `404` if it allows. This
  also holds for an empty request body.
- In the bulk handlers, whose request-level check has already passed, any forbidden item is
  `404`.
- Anonymous users still get `401`, so login redirects keep working.

#### Search request limits (S4) — `677b5e3` · breaking

Searches had no bounds on offset, term length, number of constraints or `in` list size. A deep
offset or a 100k-value `in` list meant expensive queries or exceeding the database's parameter
limit. `ApiRepository` gets four overridable limits:

| Method | Default |
|---|---|
| `getMaxOffset()` | `100000` |
| `getMaxTermLength()` | `255` characters |
| `getMaxConstraints()` | `50`, across all fields |
| `getMaxInValues()` | `1000` |

Return `null` to disable a limit. Exceeding one throws the new `ApiInvalidSearchException`,
which `handleSearch()` answers with `400 api.errors.invalidSearch`.

#### Distinct operation for the bulk-delete request check (S5) — `e4b7415` · breaking

`handleBulkDelete()` authorized the whole request with operation `delete` and no subject, the
same name as the per-entity checks that carry the entity. An override couldn't tell them apart.
The request-wide check now uses `bulkDelete`; `delete` always comes with a subject.

### Fixes

#### Rejected writes could delete child records (N4) — `59e6cd5`

When a write was rejected (`422`, `401`/`403`, `400`), `discardChanges()` reverted the entity
with `refresh()`. That reloads collections, but doesn't cancel the orphan removals that taking
elements out of an `orphanRemoval` collection had scheduled. The next `flush()` in the request
still deleted them. In the test, a rejected update wiped every child record of the entity.
Removed elements are now put back through `PersistentCollection::add()`, which cancels their
pending removal, before refreshing.

#### Filter values the database rejects return 400 (E3) — `d63e9fd`

On PostgreSQL, a filter value that doesn't fit its column (`"abc"` on an integer or date
column) or a match mode its type has no operator for (`contains` on an integer) caused a `500`.
`search()` and `searchRandom()` now turn SQLSTATE class `22` and `42883` into
`ApiInvalidSearchException` (`400`); every other database error is rethrown. MySQL and SQLite
coerce such values and are unaffected.

#### Pagination by entity (E5) — `f373bf2`

`search()` applied `LIMIT`/`OFFSET` to SQL rows. With a fetch-joined collection, pages came
back short and entities arrived with **partially hydrated collections** (an item with 3 tags
had 1). The count query dropped `GROUP BY` but kept `HAVING`, which counted wrong. Items and
count now come from Doctrine's `Paginator`; its extra id query is only used when the query
joins something.

#### Structured error details (E6) — `840dc1a` · breaking for `errorResponse()` overrides

The field name, the item limit and the unknown uuid were only part of the human-readable
`error` text, which is `null` for users without `ErrorDetailAware`. `errorResponse()` takes a
new `$details` array of client-safe fields, sent to everyone; it can't override `error` or
`key`. The responses now include:

| Response | New field |
|---|---|
| `422 api.errors.missingField` | `field` |
| `413 api.errors.tooManyItems` | `maxItems` |
| bulk `404 api.errors.recordNotFound` | `uuid` |

#### Bulk items are denormalized directly (E7) — `6306b90`

`handleBulk()` decoded the body and then re-encoded each item for `deserialize()`. The audit's
assumption that this turned `{}` into `[]` was wrong: the serializer decodes both to the same
array. The real defect was that values `json_encode()` can't write (e.g. `1e400`, which decodes
to `INF`) made a valid item fail, even in an ignored attribute. Items are now denormalized from
the decoded array, which is exactly what `deserialize()` does after decoding.

#### `handleUpdate()` takes the uuid explicitly (E8) — `223ab99` · breaking for overrides

`handleUpdate()` only read the route parameter `{uuid}`; a route naming it differently got
`404` for every update. It now takes an optional `$uuid` and falls back to `{uuid}`.

#### String match modes only (E9) — `59d6aec`

A non-string `matchMode` reached a loosely comparing `switch`: `true` matched the first case
(`startsWith`). Non-string match modes now mean `contains`, like unknown ones.

#### PHPStan level 8 findings — `e6142d2`

- `resolvePayload()` called `normalize()` on a `SerializerInterface`, which doesn't declare it.
  Serializers without `NormalizerInterface` now fall back to a JSON round-trip instead of a
  fatal error.
- Validation messages can be `Stringable`, which `json_encode()` writes as `{}`; they are cast
  to strings.
- The write handlers called `getUuid()` on whatever the serializer returned. A class that
  doesn't extend `ApiEntity` now raises a clear `LogicException`.
- `applyFilters()` narrows each constraint value to the type its match mode needs.
- Precise types throughout: `class-string` parameters, array shapes, and `ApiRepository` is
  generic over its entity (`@template T`).

### Performance

- **Bulk lookups (perf) — `b8166de`:** `handleBulk()` and `handleBulkDelete()` ran one query
  per uuid. They now load all entities with one `IN` query per 1000 uuids, matched
  case-insensitively. On PostgreSQL one malformed uuid fails the whole `IN` query (`22P02`);
  that chunk falls back to one lookup per uuid, so the bad uuid is still reported as not found.
- **Random sampling (perf) — `f0c6248`:** `searchRandom()` loaded every matching id into a PHP
  array. It now streams the ids and keeps a uniform reservoir sample (Algorithm R), so PHP
  holds only `limit` ids at a time. The database still sends every matching id.

### Operations

- **Log levels — `6eefe45`:** client errors (`4xx`) are logged at `info`, server errors
  (`5xx`) at `error`. Previously every `401`, `404` and `422` was logged as an error. The
  status is always part of the log context.
- **`composer.json` — `b4575ee` · breaking:**
  - PHP `>=8.4`, the real minimum because of DoctrineBundle 3.
  - `psr/log` instead of `symfony/monolog-bundle`.
  - Explicit `doctrine/dbal ^4.0`.
  - Removed: the unused `ext-ctype`; the app-only `conflict` and Flex `extra` settings; and the
    `version` field. For a VCS package the git tag is the version, and a mismatching field
    makes Composer skip the tag.

### Tooling

- **Tests and CI (N2) — `b22d178`:** a PHPUnit suite (113 tests at release) runs the controller
  and repository against Doctrine ORM on in-memory SQLite and the real Symfony serializer,
  validator and token storage. PostgreSQL errors are simulated through a DBAL middleware, so
  they go through DBAL's real exception conversion. PHPStan runs at level 8. GitHub Actions
  runs both on PHP 8.4 and 8.5, plus once with the lowest allowed dependencies. Run them
  locally with `composer test` and `composer analyse`.
- **Separate changelog (N3):** this file replaces the changelog section of the README.

### Breaking changes carried over from the first 2.0 draft

- **`ApiEntity::updateFromEntity()` removed (E1) — `28c8155`.** It skipped private properties
  (see 1.15.0). Drops `symfony/deprecation-contracts`.
- **Write handlers are `protected` — `39cd26d`.** `handleCreate()`, `handleUpdate()`,
  `handleBulk()` and `handleBulkDelete()` match the other handlers. Subclasses may redeclare
  them as `public`.
- **`$distinct` removed from `searchRandom()` — `8617600`.** It had been deprecated without
  effect since 1.13.0. Only named `distinct:` arguments break.
- **Version bump — `3ec848e`.**

---

## 1.15.0

No breaking changes: every new behaviour is a bug fix with a corrected status code, or opt-in.

### Security

#### Restricting writable fields (S1) — `c87f187`

New protected hook `getDenormalizationContext(string $classname, string $operation): array`
(`'create'` / `'update'`). Its result is merged into the serializer context of
`handleCreate()`, `handleUpdate()` and every `handleBulk()` item. `ignored_attributes` it
returns are merged with `uuid`, not replacing it. The unrestricted default `[]` triggers a
deprecation once per entity class (`symfony/deprecation-contracts` added).

#### Optional re-authorization of the written state (S2) — `aabdad3`

New protected hook `reauthorizesWrites(): bool`, `false` by default. When `true`, creates,
updates and bulk items are authorized again after deserialization (operations `create.after` /
`update.after`, resulting entity as subject); a denial discards the changes. 2.0.0 turns it on
by default.

### Fixes

#### Malformed write payloads return 400 instead of 500 (E2) — `30071e9`

The write handlers reported every deserialization failure as `500 api.errors.saveFailed`. A
`TypeError` from a typed setter escaped them entirely. Serializer exceptions are now caught
first:

| Cause | Status | Key |
|---|---|---|
| Body is not valid JSON | `400` | `api.errors.invalidJson` |
| Wrong value type / shape, missing constructor argument | `400` | `api.errors.invalidBody` |
| Anything else | `500` | `api.errors.saveFailed` |

Both keys already existed. `handleCreate()` / `handleUpdate()` now catch `Throwable` for the
`500` case. Changes applied to managed entities are discarded.

#### Malformed UUIDs return 404 on PostgreSQL (E4) — `d479e20`

PostgreSQL's `uuid` type rejects a malformed value (SQLSTATE `22P02`), which surfaced as a
`500` from every uuid lookup. All lookups go through one helper that maps exactly that error to
"not found" and rethrows anything else. There is no up-front format check, so non-RFC uuids
stored on MySQL/SQLite stay reachable.

### Deprecations

- **`ApiEntity::updateFromEntity()` (E1) — `b46fa0d`:** `get_object_vars()` runs in
  `ApiEntity`'s scope and silently skips a subclass's `private` properties. Behaviour is
  unchanged; removed in 2.0.0.
- **Unrestricted write handlers:** see S1.

### Version — `6203373`

---

## 1.14.0

### Features

- `handleCreate()`, `handleUpdate()` and `handleBulk()` validate entities with the Symfony
  Validator and return `422` with per-field violations (bulk: keyed `<index>.<field>`).
  Entities without constraints are unaffected. Rejected changes on managed entities are
  discarded.
- `handleBulk()` and `handleBulkDelete()` take an optional `$maxItems` limit (default `1000`,
  `null` for none) and return `413` above it.
- New `collectViolations()` and `tooManyItemsResponse()` helpers.

### Fixes

- Bulk requests are now truly all-or-nothing: previously a `404`/`403` part-way through could
  leave earlier changes (or scheduled deletions) in Doctrine's unit of work, to be written by
  any later `flush()` in the same request.

---

## 1.13.0

### Fixes

- `ApiEntity::updateFromEntity()` no longer copies `id` onto the target entity (previously it
  could null the id of a managed entity and corrupt Doctrine's identity map).
- `JsonHelper::require()` accepts falsy-but-present values such as `"0"`; only `null`, `''`
  and `[]` count as empty.
- `ApiRepository::searchRandom()` no longer relies on a non-standard `RAND()` DQL function and
  works on every database Doctrine supports. It now also honours `getMaxLimit()`.
- LIKE-based filters (`contains`, `notContains`, `startsWith`, `endsWith`) match `%` and `_`
  literally instead of as wildcards.
- Malformed search payloads (non-object bodies, filters/sorts of the wrong shape) return `400`
  or are ignored instead of causing a `500`.
- `handleBulk()` / `handleBulkDelete()` run authorization per item with the entity as subject,
  so resource-level rules in `isAuthorized()` also apply to bulk writes. Malformed bulk bodies
  return `400`.
- Added the missing `symfony/security-bundle` dependency.

### Changes

- `JsonHelper::require()` throws `ApiMissingFieldException` (convertible to a `422` via
  `missingFieldResponse()`), and invalid input throws `InvalidArgumentException`, both instead
  of a plain `\Exception` with codes 1–3. Code catching `\Exception` is unaffected.
- New `accessDeniedResponse()` helper (`401` for anonymous users, `403` otherwise).
- The `$distinct` parameter of `searchRandom()` is deprecated and has no effect; results are
  always distinct.

---

## Known limitations

- **PostgreSQL is tested by simulation.** Its errors (`22P02`, `22007`, `42883`) are produced
  by a DBAL middleware in the tests; there is no test run against a real PostgreSQL server.
- **Aborted transactions.** If an application wraps a request in its own transaction,
  PostgreSQL aborts it after a rejected statement (malformed uuid, mismatched filter value).
  The handler answers correctly, but later queries in that transaction fail.
- **`searchRandom()` on huge unfiltered tables.** PHP memory stays bounded, but the database
  still sends every matching id.
- **Supported versions.** CI covers PHP 8.4/8.5 with the newest and the lowest allowed
  dependencies. The workflow had not run on GitHub yet at the time of writing.
