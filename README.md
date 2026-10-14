<p align="center">
  <img src="assets/logo.webp" alt="kohlrAPI logo" width="180">
</p>

# kohlrAPI

This is a lightweight Symfony library that helps to build a RESTful API. It was developed initially for: [AdCaptain](https://adcaptain.de) and later reused and improved
in other private projects of mine. I finally decided to create a standalone package combining the many useful bits from each project and tried to make 
them reusable for other projects and other people.

It features a set of abstract base classes for Symfony API backends, providing generic CRUD, search, filter, and sort logic. Filters use the format PrimeVue DataTables send.

**Author:** Philip Märksch  
**Version:** 2.0.0  
**License:** MIT

---

## Requirements

- PHP >= 8.2
- Symfony 7.4 or 8.0
- Doctrine ORM >= 3.6
- Symfony SecurityBundle (for `getUser()` / `isGranted()` in `ApiController`)

---

## Installation

kohlrAPI is not on Packagist. Add the GitHub repository as a [Composer VCS repository](https://getcomposer.org/doc/05-repositories.md#vcs) to your Symfony project:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/pmaerksch/kohlrapi"
    }
],
"require": {
    "pmaerksch/kohlrapi": "^2.0"
}
```

Then run:

```bash
composer install
```

---

## Classes

All classes live under the `pmaerksch\Kohlrapi` namespace.

---

### `ApiEntity`

Abstract base entity. Extend this in all your Doctrine entities.

- Auto-generated integer `id` (internal, never exposed)
- UUID v4 generated on construction (exposed via the `uuid` serializer group)
- `parseJson($json)` — wraps a client payload (JSON string or already-decoded array) in a [`JsonHelper`](#jsonhelper) for use in `updateFromJson()` implementations

---

### `JsonHelper`

Utility for reading required/optional keys out of a client payload, typically used from an entity's `updateFromJson()` via [`ApiEntity::parseJson()`](#apientity).

```php
class Booking extends ApiEntity
{
    public function updateFromJson(mixed $json): static
    {
        $data = $this->parseJson($json);

        $this->name  = $data->require('name');           // throws if missing/empty
        $this->notes = $data->optional('notes', '');      // falls back to default if absent

        return $this;
    }
}
```

| Method | Description |
|---|---|
| `require($key, $allowEmpty = false)` | Returns `$data[$key]`; throws [`ApiMissingFieldException`](#apimissingfieldexception) if the key is missing, or (unless `$allowEmpty`) if its value is `null`, `''`, or `[]`. Falsy values like `"0"`, `0` and `false` are accepted. |
| `optional($key, $default = null)` | Returns `$data[$key]` if present, otherwise `$default` |

The constructor and `format()` throw an `InvalidArgumentException` if the input isn't a JSON string decodable to an array, or isn't already an array.

Because `require()` throws `ApiMissingFieldException`, a controller can turn it into a `422` with [`missingFieldResponse()`](#apicontroller).

---

### `ArraySerializable`

Optional interface for entities that serialize themselves to an array instead of relying on the Symfony serializer + serializer groups.

```php
interface ArraySerializable
{
    public function getDataAsArray(): array;
}
```

When an entity returned through `singleResponse()` / `listResponse()` (and therefore through `handleFetch` / `handleSearch` / `handleCreate` / …) implements this, its `getDataAsArray()` output is used verbatim; any other object is run through the serializer with the given groups. This lets read endpoints emit rich, relation-aware and computed payloads without serializer-group annotations.

```php
class Booking extends ApiEntity implements ArraySerializable
{
    public function getDataAsArray(): array
    {
        return [
            'uuid'     => $this->uuid,
            'customer' => $this->customer->getDataAsArray(), // nested relation
            'unpaid'   => $this->calcIsUnpaid(),             // computed field
            // ...
        ];
    }
}
```

Entities that don't implement it keep using the serializer-group path — the two strategies coexist, chosen per entity. Include `'uuid'` in your array yourself (the serializer path adds it automatically; the array path does not).

---

### `ErrorDetailAware`

Optional interface implemented by your **user** entity to opt into detailed API error messages.

```php
interface ErrorDetailAware
{
    public function showsErrorDetails(): bool;
}
```

`errorResponse()` always logs the full message server-side and returns a stable `key` the client can localise. The human-readable `error` detail — which may carry internals (exception text, SQL, file paths) — is included in the response **only** when the authenticated user implements this interface and returns `true`; otherwise `error` is `null`. This keeps sensitive detail out of normal responses while letting privileged users (e.g. developers) see it.

---

### `ApiRepository`

Abstract base repository providing generic search, filter, sort, and pagination.

Subclasses **must** implement:

| Method | Description |
|---|---|
| `getAlias()` | DQL alias for the root entity (e.g. `'u'`, `'p'`) |
| `getEntityFieldMap()` | Maps frontend filter/sort keys to DQL expressions. `id` and `uuid` are added automatically. |
| `applyGlobalSearch()` | Full-text WHERE clause applied when a `term` is provided |

Subclasses **may** override:

| Method | Description |
|---|---|
| `applyBaseConditions()` | Mandatory constraints on every query (e.g. soft-delete, visibility) |
| `applyCustomSort()` | Handle aggregate or JOIN-based sort cases; return `true` if handled |
| `getDefaultSort()` | Fallback sort field and direction (default: `id DESC`) |
| `getMaxLimit()` | Per-entity result cap; return `null` for no cap |
| `getMaxOffset()` | Largest pagination offset (default `100000`) |
| `getMaxTermLength()` | Longest global search term in characters (default `255`) |
| `getMaxConstraints()` | Most filter constraints per request, across all fields (default `50`) |
| `getMaxInValues()` | Most values in one `in` constraint (default `1000`) |

Pagination counts entities, not SQL rows (via Doctrine's `Paginator`), so `applyBaseConditions()`, `applyGlobalSearch()` and `applyCustomSort()` may join, fetch-join collections, `GROUP BY` and filter with `HAVING`; `items` and `count` stay correct.

A request exceeding one of the last four limits throws `ApiInvalidSearchException`, which `handleSearch()` answers with `400` (`api.errors.invalidSearch`). Return `null` from a limit to disable it. The limits also apply to `getEntityList()` / `getRandomEntityList()` — catch the exception there if you call them yourself.

#### Filter payload (PrimeVue format)

```json
{
  "fieldKey": {
    "operator": "and|or",
    "constraints": [{ "value": "...", "matchMode": "contains" }]
  }
}
```

Supported `matchMode` values: `contains`, `notContains`, `startsWith`, `endsWith`, `equals`, `notEquals`, `lt`, `lte`, `gt`, `gte`, `in`

`in` expects a non-empty array of scalars; every other mode expects a single scalar. Constraints with a value of the wrong shape are ignored. `%` and `_` in the LIKE-based modes are matched literally, not as wildcards.

#### Sort payload

```json
{ "field": "fieldKey", "order": "ASC|DESC" }
```

---

### `ApiController`

Abstract base controller. Extend this in all your API controllers.

Its constructor is autowired with `EntityManagerInterface`, `SerializerInterface`, `KernelInterface`, `LoggerInterface`, `ValidatorInterface` and `RequestStack` — so a plain subclass needs no constructor. If you do declare one (e.g. to inject a permission service), forward all of these to `parent::__construct(...)`.

**Response helpers:**

| Method | Returns |
|---|---|
| `errorResponse($message, $status, $e, $key, $details)` | `{ error, key, ...details }` — the `error` detail is only exposed to users implementing [`ErrorDetailAware`](#errordetailaware); otherwise `null`. `$details` are structured, client-safe fields sent to everyone |
| `successResponse($data)` | `200 { message, ...data }` |
| `createdResponse($data)` | `201 { message, ...data }` |
| `noContentResponse()` | `204` |
| `accessDeniedResponse()` | `401` for anonymous users, `403` for authenticated ones |
| `validationErrorResponse($violations)` | `422 { error, key, violations: { field: message } }` |
| `tooManyItemsResponse($maxItems)` | `413 { …, maxItems }` — returned by the bulk handlers when a request exceeds `$maxItems` |
| `listResponse($items, $groups, $count, $maxCount)` | `200 { message, items, count?, maxCount? }` — `count` is the number of items on this page, `maxCount` the total matching the search/filters (ignoring only the pagination limit), so the frontend paginator sizes itself to the filtered result set. Items implementing `ArraySerializable` are serialized via `getDataAsArray()`, others via the serializer + `$groups` |
| `singleResponse($data, $groups)` | `200 { message, data }` — same `ArraySerializable`-aware serialization as `listResponse` |

**Input handling:**

| Method | Description |
|---|---|
| `deserializeInput($class, $request)` | Deserializes request JSON into a DTO. On bad JSON it returns a `400` `JsonResponse` **instead of** the DTO — check `instanceof JsonResponse` before using the result |
| `validateInput($input)` | Validates a DTO via Symfony Validator; returns `422` with per-field violations or `null` if valid |
| `collectViolations($input, $prefix)` | Validates an object and returns its violations as a `{ field: message }` array (empty if valid), each key prefixed with `$prefix` |
| `requireField($data, $key, $filter)` | Extracts a field from decoded JSON; throws `ApiMissingFieldException` if absent or invalid |
| `missingFieldResponse($e)` | Converts an `ApiMissingFieldException` into a `422 { …, field }` response |

**Generic CRUD handlers:**

| Method | Description |
|---|---|
| `handleFetch($classname, $uuid, $authLevel, $groups)` | Fetch a single entity by UUID with auth check |
| `handleCreate($classname, $request, $authLevel)` | Deserialize, validate + persist a new entity. Returns `400` if the body is malformed (invalid JSON, wrong value types) and `422` with violations if the entity is invalid. |
| `handleUpdate($classname, $request, $authLevel, $uuid = null)` | Deserialize, validate + update an existing entity by UUID — `$uuid`, or the route parameter `{uuid}` when omitted. Returns `400` if the body is malformed and `422` with violations if the result is invalid; the rejected changes are discarded. |
| `handleBulk($classname, $request, $authLevel, $maxItems = 1000)` | Create or update multiple entities, all-or-nothing; items with a uuid are updated, items without are created. Returns `{ uuids: string[] }` in input order. Authorization runs for the request (`bulk`) and again per item (`update` with the entity as subject, or `create`). Every item is validated; see [Bulk endpoints](#bulk-endpoints--recommended-route-convention). |
| `handleBulkDelete($classname, $request, $authLevel, $maxItems = 1000)` | Delete multiple entities by UUID, all-or-nothing. Expects a JSON array of UUID strings. Returns `204`. Authorization runs for the request (`bulkDelete`, no subject) and again per entity (`delete` with the entity as subject). |
| `handleSearch($classname, $request, $authLevel, $groups)` | Parses search params from the request body, then runs a paginated search; returns `items + count + maxCount`. Returns `400` on invalid JSON (`api.errors.invalidJson`) or a rejected search (`api.errors.invalidSearch`, see [`ApiRepository`](#apirepository)). |
| `getEntity($classname, $uuid, $authLevel)` | Fetch a single entity object (no serialization) |
| `getEntityList($repository, $searchParams, $authLevel)` | Fetch a list of entity objects (no serialization) |
| `getRandomEntityList($repository, $searchParams, $authLevel)` | Fetch a random list of entity objects (no serialization). Portable across databases: the matching ids are streamed and sampled in PHP (reservoir sampling, uniform), so no custom `RAND()` DQL function is needed and PHP holds only `limit` ids at a time. The database still sends every matching id, so keep random searches filtered on very large tables. |

**Utility:**

| Method | Description |
|---|---|
| `resolveByUuid($classname, $uuid)` | Resolves a UUID string to a Doctrine entity instance, or `null` if not found. Useful in custom create/update methods when relations are sent as UUIDs from the frontend. |

#### Writable fields

`handleCreate`, `handleUpdate` and `handleBulk` deserialize the client payload straight into the entity. Without a restriction, every property with a setter (or public) could be set by the client — including fields like `roles`, `owner` or `password`. So every controller using these handlers **must** override `getDenormalizationContext()` and return serializer `groups` or `attributes`; otherwise the handler throws a `LogicException`. `uuid` is always ignored.

```php
protected function getDenormalizationContext(string $classname, string $operation): array
{
    // $operation is 'create' or 'update'
    return ['groups' => ['write']];
    // or: return [AbstractNormalizer::ATTRIBUTES => ['name', 'notes']];
}
```

Empty `groups` and the `'*'` group don't count as a restriction (the serializer reads both as "every field"). To deliberately allow every field (the behaviour before 2.0), return `[self::WRITE_ALL_FIELDS => true]`.

#### Authorization

Every `handle*` and `getEntity*` helper takes an `$authLevel` and routes the check through two methods:

| Method | Role |
|---|---|
| `can($action, $subject?, $subjectClass?, $operation?)` | **Call this.** Assembles an `AuthorizationContext` (auto-filling the current user and request) and asks `isAuthorized()`. Returns `bool`. |
| `isAuthorized(AuthorizationContext $context)` | **Override this.** The decision itself. Default delegates to Symfony's voters. |

By default `$action` is a Symfony role/attribute (e.g. `ROLE_ADMIN`, `IS_AUTHENTICATED_FULLY`) and the CRUD handlers default to `ROLE_ADMIN` — i.e. **fail-closed**; pass a weaker level explicitly to open an endpoint up:

```php
return $this->handleSearch(Booking::class, $request, 'IS_AUTHENTICATED_FULLY');
```

In your own controller actions, run ad-hoc checks through `can()` so they go through the same gate:

```php
if (!$this->can('ROLE_ADMIN')) { /* 403 */ }
```

**Plugging in a non-Symfony permission system:** override `isAuthorized()` in your own base controller. Because it receives the full `AuthorizationContext`, the override can decide on whatever it wants and ignore Symfony roles entirely:

```php
abstract class BaseController extends ApiController
{
    public function __construct(/* …parent deps…, */ private MyPermissions $perms) { parent::__construct(/* … */); }

    protected function isAuthorized(AuthorizationContext $ctx): bool
    {
        return $this->perms->decide($ctx); // your rules, your inputs
    }
}
```

Routing every check through `can()` keeps authorization to a single chokepoint that your `isAuthorized()` override fully controls.

**Checking the result of a write:** checking an update only against the entity *before* the payload is applied would let a payload e.g. move the entity to an owner or tenant the user has no rights on. So every create/update (including bulk items) is checked again after the payload has been applied and before anything is written — with operation `create.after` / `update.after` and the resulting entity as `$subject`. A denial discards the changes and returns `401`/`403`.

Voters and `isAuthorized()` overrides must handle (or deliberately allow) these two operations. To turn the second check off for a controller:

```php
protected function reauthorizesWrites(): bool
{
    return false;
}
```

##### `AuthorizationContext`

Immutable bundle passed to `isAuthorized()`. Built by `can()` — you never construct it yourself; overrides only read the fields they need. New fields may be added in future versions without breaking your override.

| Field | Type | Description |
|---|---|---|
| `$action` | `string` | The requested permission token (a role for the default impl) |
| `$subjectClass` | `?string` | FQCN of the entity the action concerns, when known (set even with no instance, e.g. create/search) |
| `$subject` | `?object` | The loaded entity the action concerns (fetch/update); `null` otherwise |
| `$operation` | `?string` | `'search' \| 'fetch' \| 'create' \| 'update' \| 'bulk' \| 'bulkDelete' \| 'delete'`, plus `'create.after' \| 'update.after'` for the second check on writes (see `reauthorizesWrites()`) |
| `$user` | `?object` | The authenticated user, or `null` |
| `$request` | `?Request` | The current HTTP request, when available |

> **Note:** for `fetch`/`update` the entity is loaded *before* the auth check so it is available as `$subject` (enabling per-resource decisions). The default Symfony implementation passes `$subject` on to `isGranted()`, so your voters receive the entity.

**Existence is never revealed.** An entity the user may not access is answered exactly like a missing one, so a client can't probe which uuids exist. Both cases fall back to the class-level check without a subject: if that denies, the response is `401`/`403`; if it allows, `404`. In the bulk handlers (whose request-level check has already passed) a forbidden item is always `404`. `getEntity()` returns `null` for a forbidden entity when the class-level check allows.

#### Bulk endpoints — recommended route convention

```php
#[Route('/bulk', name: 'items_bulk',        methods: ['POST'])]   // create + update
#[Route('/bulk', name: 'items_bulk_delete', methods: ['DELETE'])] // delete
```

Bulk save request body (array of payloads — uuid present = update, absent = create):

```json
[
    { "uuid": "existing-uuid", "name": "Updated" },
    { "name": "New item" }
]
```

Bulk delete request body (array of UUIDs):

```json
["uuid-1", "uuid-2", "uuid-3"]
```

Both bulk requests are **all-or-nothing**: if any item is not found or not permitted (both `404 { …, uuid }`, see below) or invalid, nothing is written.

**Validation.** Each item is validated against the entity's Symfony Validator constraints. Violations for all items are returned together, keyed `<index>.<field>`:

```json
{ "error": "Validation failed", "key": "api.errors.validationFailed", "violations": { "1.name": "This value should not be blank." } }
```

**Item limit.** Both handlers accept at most `$maxItems` items per request (default `1000`) and return `413` (`key: api.errors.tooManyItems`) above that. Choose a limit per endpoint, or pass `null` to disable it:

```php
return $this->handleBulk(Booking::class, $request, 'ROLE_ADMIN', maxItems: 200);
return $this->handleBulk(LogEntry::class, $request, 'ROLE_ADMIN', maxItems: null); // no limit
```

> **Note:** UUID-to-entity resolution for relation fields (e.g. `"owner": "some-uuid"`) requires a `UuidEntityDenormalizer` registered in the consuming application. This is intentionally kept out of the package since it depends on the app's Doctrine setup.

---

### `ApiSearchParams`

Immutable value object representing a search request. Constructed internally by `handleSearch`; use directly only when calling `getEntityList` or `getRandomEntityList`.

```php
// Parsed from a JSON request body (used internally by handleSearch)
$params = ApiSearchParams::fromRequest($request);

// From an array
$params = ApiSearchParams::fromArray($data);

// For internal/programmatic use
$params = ApiSearchParams::fromInternal(limit: 10, filters: ['status' => ...]);
```

| Property | Type | Default | Description |
|---|---|---|---|
| `$term` | `string` | `''` | Global search term |
| `$filters` | `array` | `[]` | Per-field filter constraints |
| `$sort` | `?array` | `null` | Sort field and direction |
| `$offset` | `int` | `0` | Pagination offset |
| `$limit` | `int` | `25` | Page size (capped at 500) |

---

### `ApiMissingFieldException`

Thrown by `requireField()` and `JsonHelper::require()` when a required field is absent or fails the provided filter callable. Carries the field name and produces the message `"Missing or invalid field: {field}"`.

---

## Development

```bash
composer install
composer test      # PHPUnit: Doctrine ORM on in-memory SQLite + the real Symfony serializer/validator
composer analyse   # PHPStan, level 8
```

CI runs both on PHP 8.2–8.5, plus once with the lowest allowed dependency versions.

---

## Changelog

### 2.0.0

**Breaking changes**

- `handleCreate()`, `handleUpdate()` and `handleBulk()` throw a `LogicException` unless `getDenormalizationContext()` restricts the writable fields with serializer `groups` or `attributes`. Use `ApiController::WRITE_ALL_FIELDS` to deliberately allow every field. See [Writable fields](#writable-fields).
- `ApiEntity::updateFromEntity()` is removed.
- `handleCreate()`, `handleUpdate()`, `handleBulk()` and `handleBulkDelete()` are `protected`, like the other handlers.
- The deprecated `$distinct` parameter of `ApiRepository::searchRandom()` is removed.
- `symfony/deprecation-contracts` is no longer a dependency.

**Upgrading from 1.x**

1. In every controller that calls `handleCreate()`, `handleUpdate()` or `handleBulk()`, override `getDenormalizationContext()`. Prefer serializer groups (`['groups' => ['write']]` plus `#[Groups(['write'])]` on the client-writable properties) or an `attributes` allow-list. To keep the old behaviour for a controller, return `[self::WRITE_ALL_FIELDS => true]`. The 1.15 deprecation log lists every entity class that still needs this.
2. Replace `updateFromEntity()` calls with explicit setters, e.g. in an `updateFromJson()` via `parseJson()`.
3. If code outside a controller calls one of the write handlers, move that call into a controller action.
4. Remove any named `distinct:` argument from `searchRandom()` calls.

### 1.15.0

**Security**

- New `getDenormalizationContext()` hook to restrict which fields clients may write in `handleCreate()`, `handleUpdate()` and `handleBulk()` (serializer `groups` or `attributes`). Without it every writable property can be set by the payload; this default is deprecated and will be rejected in 2.0. See [Writable fields](#writable-fields).
- New opt-in `reauthorizesWrites()`: authorizes every create/update again on the resulting entity (operations `create.after` / `update.after`) before anything is written, so a payload can't move an entity to an owner or tenant the user has no rights on.

**Fixes**

- Malformed write payloads (invalid JSON, wrong value types) return `400` (`api.errors.invalidJson` / `api.errors.invalidBody`) instead of `500` `api.errors.saveFailed`. Errors that aren't `Exception`s (e.g. a `TypeError` from a typed setter) are now caught and return `500` as well.
- Malformed UUIDs return `404` instead of `500` on PostgreSQL, whose `uuid` column type rejects them (SQLSTATE `22P02`). Applies to `handleFetch()`, `handleUpdate()`, the bulk handlers, `getEntity()` and `resolveByUuid()`.

**Deprecations**

- `ApiEntity::updateFromEntity()`: it silently skips `private` properties. Removed in 2.0; set the fields explicitly instead.
- Calling the write handlers without restricting writable fields (see above).

**Changes**

- Added `symfony/deprecation-contracts` as an explicit dependency.

### 1.14.0

**Features**

- `handleCreate()`, `handleUpdate()` and `handleBulk()` validate entities with the Symfony Validator and return `422` with per-field violations (bulk: keyed `<index>.<field>`). Entities without constraints are unaffected. Rejected changes on managed entities are discarded.
- `handleBulk()` and `handleBulkDelete()` take an optional `$maxItems` limit (default `1000`, `null` for none) and return `413` above it.
- New `collectViolations()` and `tooManyItemsResponse()` helpers.

**Fixes**

- Bulk requests are now truly all-or-nothing: previously a `404`/`403` part-way through could leave earlier changes (or scheduled deletions) in Doctrine's unit of work, to be written by any later `flush()` in the same request.

### 1.13.0

**Fixes**

- `ApiEntity::updateFromEntity()` no longer copies `id` onto the target entity (previously it could null the id of a managed entity and corrupt Doctrine's identity map).
- `JsonHelper::require()` accepts falsy-but-present values such as `"0"`; only `null`, `''` and `[]` count as empty.
- `ApiRepository::searchRandom()` no longer relies on a non-standard `RAND()` DQL function and works on every database Doctrine supports. It now also honours `getMaxLimit()`.
- LIKE-based filters (`contains`, `notContains`, `startsWith`, `endsWith`) match `%` and `_` literally instead of as wildcards.
- Malformed search payloads (non-object bodies, filters/sorts of the wrong shape) return `400` or are ignored instead of causing a `500`.
- `handleBulk()` / `handleBulkDelete()` run authorization per item with the entity as subject, so resource-level rules in `isAuthorized()` also apply to bulk writes. Malformed bulk bodies return `400`.
- Added the missing `symfony/security-bundle` dependency.

**Changes**

- `JsonHelper::require()` throws `ApiMissingFieldException` (convertible to a `422` via `missingFieldResponse()`), and invalid input throws `InvalidArgumentException`, both instead of a plain `\Exception` with codes 1–3. Code catching `\Exception` is unaffected.
- New `accessDeniedResponse()` helper (`401` for anonymous users, `403` otherwise).
- The `$distinct` parameter of `searchRandom()` is deprecated and has no effect; results are always distinct.

---

## License

MIT — see [LICENSE](LICENSE).
