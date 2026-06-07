# kohlrAPI

This is a lightweight Symfony library that helps to build a RESTful API. The name is a play on words and stems from one of the several projects this 
was developed for/with: [AdCaptain](https://adcaptain.de). I finally decided to create a standalone package combining the many 
useful bits from each project and tried to make them reusable for other projects.

It features a set of abstract base classes for Symfony API backends, providing generic CRUD, search, filter, and sort logic compatible with PrimeVue / Vuetify DataTable payloads.

**Author:** Philip Märksch  
**Version:** 1.11.0  
**License:** MIT

---

## Requirements

- PHP >= 8.2
- Symfony 7.4 or 8.0
- Doctrine ORM >= 3.6

---

## Installation

Add the package to your Symfony project via a [Composer path repository](https://getcomposer.org/doc/05-repositories.md#path):

```json
"repositories": [
    {
        "type": "path",
        "url": "packages/kohlrapi",
        "options": { "symlink": true }
    }
],
"require": {
    "pmaerksch/kohlrapi": "*"
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
- `updateFromEntity()` — copies all properties except `uuid` from another instance of the same class

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
| `errorResponse($message, $status)` | `{ error, key }` — the `error` detail is only exposed to users implementing [`ErrorDetailAware`](#errordetailaware); otherwise `null` |
| `successResponse($data)` | `200 { message, ...data }` |
| `createdResponse($data)` | `201 { message, ...data }` |
| `noContentResponse()` | `204` |
| `listResponse($items, $groups, $count, $maxCount)` | `200 { message, items, count?, maxCount? }` — items implementing `ArraySerializable` are serialized via `getDataAsArray()`, others via the serializer + `$groups` |
| `singleResponse($data, $groups)` | `200 { message, data }` — same `ArraySerializable`-aware serialization as `listResponse` |

**Input handling:**

| Method | Description |
|---|---|
| `deserializeInput($class, $request)` | Deserializes request JSON into a DTO; returns `400` on bad JSON |
| `validateInput($input)` | Validates a DTO via Symfony Validator; returns `422` with per-field violations or `null` if valid |
| `requireField($data, $key, $filter)` | Extracts a field from decoded JSON; throws `ApiMissingFieldException` if absent or invalid |
| `missingFieldResponse($e)` | Converts an `ApiMissingFieldException` into a `422` response |

**Generic CRUD handlers:**

| Method | Description |
|---|---|
| `handleFetch($classname, $uuid, $authLevel, $groups)` | Fetch a single entity by UUID with auth check |
| `handleCreate($classname, $request, $authLevel)` | Deserialize + persist a new entity |
| `handleUpdate($classname, $request, $authLevel)` | Deserialize + update an existing entity by UUID |
| `handleBulk($classname, $request, $authLevel)` | Create or update multiple entities in one transaction; items with a uuid are updated, items without are created. Returns `{ uuids: string[] }` in input order. |
| `handleBulkDelete($classname, $request, $authLevel)` | Delete multiple entities by UUID in one transaction. Expects a JSON array of UUID strings. Returns `204`. |
| `handleSearch($classname, $request, $authLevel, $groups)` | Parses search params from the request body, then runs a paginated search; returns `items + count + maxCount`. Returns `400` on invalid JSON. |
| `getEntity($classname, $uuid, $authLevel)` | Fetch a single entity object (no serialization) |
| `getEntityList($repository, $searchParams, $authLevel)` | Fetch a list of entity objects (no serialization) |
| `getRandomEntityList($repository, $searchParams, $authLevel)` | Fetch a random list of entity objects (no serialization) |

**Utility:**

| Method | Description |
|---|---|
| `resolveByUuid($classname, $uuid)` | Resolves a UUID string to a Doctrine entity instance, or `null` if not found. Useful in custom create/update methods when relations are sent as UUIDs from the frontend. |

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

##### `AuthorizationContext`

Immutable bundle passed to `isAuthorized()`. Built by `can()` — you never construct it yourself; overrides only read the fields they need. New fields may be added in future versions without breaking your override.

| Field | Type | Description |
|---|---|---|
| `$action` | `string` | The requested permission token (a role for the default impl) |
| `$subjectClass` | `?string` | FQCN of the entity the action concerns, when known (set even with no instance, e.g. create/search) |
| `$subject` | `?object` | The loaded entity the action concerns (fetch/update); `null` otherwise |
| `$operation` | `?string` | `'search' \| 'fetch' \| 'create' \| 'update' \| 'bulk' \| 'delete'` |
| `$user` | `?object` | The authenticated user, or `null` |
| `$request` | `?Request` | The current HTTP request, when available |

> **Note:** for `fetch`/`update` the entity is loaded *before* the auth check so it is available as `$subject` (enabling per-resource decisions). The default Symfony implementation ignores `$subject`, so its behavior is unchanged.

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

Thrown by `requireField()` when a required field is absent or fails the provided filter callable. Carries the field name and produces the message `"Missing or invalid field: {field}"`.

---

## License

MIT — see [LICENSE](LICENSE).
