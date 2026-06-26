<?php

/**
 * ApiController — Abstract base controller for all API endpoints.
 * @author  Philip Märksch
 * Provides shared response helpers and generic CRUD action handlers:
 *   - errorResponse / successResponse / createdResponse / noContentResponse
 *   - listResponse        — standardized list response: { items, count?, maxCount? }
 *   - singleResponse      — standardized single-entity response: { data }
 *   - deserializeInput    — deserialize request JSON into a DTO; returns 400 on bad JSON
 *   - validateInput       — validate a DTO via Symfony Validator; returns 422 with per-field violations or null if valid
 *   - requireField        — extract + validate a field from decoded JSON; throws ApiMissingFieldException on failure
 *   - missingFieldResponse — converts a ApiMissingFieldException into a 422 error response
 *   - handleFetch         — fetch a single entity by UUID with auth check
 *   - handleCreate        — deserialize + persist a new entity from request body
 *   - handleUpdate        — deserialize + update an existing entity from request body
 *   - handleSearch        — paginated search with auth check, returns items + count
 *   - getEntity           — fetch a single entity object (no serialization)
 *   - getEntityList       — fetch a list of entity objects (no serialization)
 *   - getRandomEntityList — fetch a random list of entity objects (no serialization)
 */

namespace pmaerksch\Kohlrapi;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\KernelInterface;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

class ApiController extends AbstractController
{
	protected EntityManagerInterface $em;
	protected SerializerInterface    $serializer;
	protected KernelInterface        $kernel;
	protected ValidatorInterface     $validator;
	protected RequestStack           $requestStack;



	public function __construct(EntityManagerInterface $em, SerializerInterface $serializer, KernelInterface $kernel, protected LoggerInterface $logger, ValidatorInterface $validator, RequestStack $requestStack)
	{
		$this->em           = $em;
		$this->serializer   = $serializer;
		$this->kernel       = $kernel;
		$this->validator    = $validator;
		$this->requestStack = $requestStack;
	}



	/**
	 * Generates an error response with a given message and HTTP status code.
	 * @param string $message The error message to include in the response.
	 * @param int $status The HTTP status code to set for the response.
	 * @return JsonResponse JSON response containing the error message and status code.
	 */
	protected function errorResponse(string $message, int $status, ?Throwable $e = null, string $key = ''): JsonResponse
	{
		$this->logger->error($message, $e ? ['exception' => $e] : ['status' => $status]);

		// The detailed message may carry internals (exception text, SQL, paths).
		// Only expose it to users explicitly allowed to see error details; everyone
		// else receives just the key, which the client maps to a safe, localized
		// message. The full message is always written to the server log above.
		$user      = $this->getUser();
		$mayDetail = $user instanceof ErrorDetailAware && $user->showsErrorDetails();

		return new JsonResponse(['error' => $mayDetail ? $message : null, 'key' => $key], $status);
	}



	/**
	 * Returns a successful JSON response with a default message and additional data.
	 * @param array $data Optional associative array of additional data to include in the response.
	 * @return JsonResponse JSON response with a status code of HTTP 200 (OK).
	 */
	protected function successResponse(array $data = []): JsonResponse
	{
		return new JsonResponse(array_merge(['message' => 'OK'], $data), Response::HTTP_OK);
	}



	/**
	 * Returns a standardized list response.
	 * Each item in $items may be an entity object or an already-serialized array (used as-is). Objects implementing
	 * {@see ArraySerializable} are serialized via getDataAsArray(); other objects via the serializer + $serializerGroups.
	 * @param array $items Entity objects or pre-serialized arrays.
	 * @param array $serializerGroups Serializer groups applied to non-ArraySerializable entity objects. 'uuid' is always included.
	 * @param int|null $count Number of items in this result set (for pagination).
	 * @param int|null $maxCount Total number of possible results regardless of limit (for pagination).
	 * @return JsonResponse { message, items, count?, maxCount? }
	 */
	protected function listResponse(array $items, array $serializerGroups = [], ?int $count = null, ?int $maxCount = null): JsonResponse
	{
		$resolved = array_map(
			fn($item) => $this->resolvePayload($item, $serializerGroups),
			$items
		);

		$body = ['message' => 'OK', 'items' => $resolved];

		if ( $count !== null )
		{
			$body[ 'count' ] = $count;
		}

		if ( $maxCount !== null )
		{
			$body[ 'maxCount' ] = $maxCount;
		}

		return new JsonResponse($body, Response::HTTP_OK);
	}



	/**
	 * Returns a standardized single-entity response.
	 * $data may be an entity object or an already-serialized array (used as-is). An object implementing
	 * {@see ArraySerializable} is serialized via getDataAsArray(); any other object via the serializer + $serializerGroups.
	 * @param object|array $data Entity object or pre-serialized array.
	 * @param array $serializerGroups Serializer groups applied to a non-ArraySerializable entity object. 'uuid' is always included.
	 * @return JsonResponse { message, data }
	 */
	protected function singleResponse(object|array $data, array $serializerGroups = []): JsonResponse
	{
		return new JsonResponse(['message' => 'OK', 'data' => $this->resolvePayload($data, $serializerGroups)], Response::HTTP_OK);
	}



	/**
	 * Resolves a response payload item: an ArraySerializable entity via its
	 * getDataAsArray(), any other object via the serializer (with $serializerGroups
	 * plus the always-present 'uuid' group), and an array verbatim.
	 * @param mixed $item Entity object or pre-serialized array.
	 * @param array $serializerGroups Groups applied to non-ArraySerializable entity objects.
	 * @return mixed The serialized array (or the original scalar/array).
	 */
	private function resolvePayload(mixed $item, array $serializerGroups): mixed
	{
		if ( $item instanceof ArraySerializable )
		{
			return $item->getDataAsArray();
		}

		if ( is_object($item) )
		{
			return $this->serializer->normalize($item, 'json', ['groups' => array_merge($serializerGroups, ['uuid'])]);
		}

		return $item;
	}



	/**
	 * Generates a JSON response with HTTP status code 201 (Created).
	 * @param array $data Optional additional data to include in the response body.
	 * @return JsonResponse JSON response containing a success message and optional data.
	 */
	protected function createdResponse(array $data = []): JsonResponse
	{
		return new JsonResponse(array_merge(['message' => 'OK'], $data), Response::HTTP_CREATED);
	}



	/**
	 * Returns an HTTP response with no content.
	 * @return Response An HTTP response with a 204 No Content status.
	 */
	protected function noContentResponse(): Response
	{
		return new Response(null, Response::HTTP_NO_CONTENT);
	}



	/**
	 * Deserializes the JSON request body into an object of the specified class.
	 * Validates the request body and handles errors for missing or invalid JSON data.
	 * @param string $class The fully-qualified class name to deserialize the JSON data into.
	 * @param Request $request The HTTP request containing the JSON body to be deserialized.
	 * @return object The deserialized object or an error response in case of failure.
	 */
	protected function deserializeInput(string $class, Request $request): object
	{
		$content = $request->getContent();

		if ( trim($content) === '' )
		{
			return $this->errorResponse('Missing request body', Response::HTTP_BAD_REQUEST, null, 'api.errors.invalidBody');
		}

		try
		{
			return $this->serializer->deserialize($content, $class, 'json');
		}
		catch ( Throwable )
		{
			return $this->errorResponse('Invalid JSON data', Response::HTTP_BAD_REQUEST, null, 'api.errors.invalidJson');
		}
	}



	/**
	 * Validates a DTO using Symfony's Validator.
	 * Returns null if validation passes, or a 422 response with per-field violation messages if it fails.
	 */
	protected function validateInput(object $input): ?JsonResponse
	{
		$violations = $this->validator->validate($input);

		if ( count($violations) === 0 )
		{
			return null;
		}

		$errors = [];
		foreach ( $violations as $violation )
		{
			$field            = ltrim($violation->getPropertyPath(), '.');
			$errors[ $field ] = $violation->getMessage();
		}

		return $this->validationErrorResponse($errors);
	}



	/**
	 * Returns a 422 Unprocessable Entity response with per-field violation messages.
	 * Format: { "error": "Validation failed", "key": "...", "violations": { "field": "message" } }
	 */
	protected function validationErrorResponse(array $violations): JsonResponse
	{
		return new JsonResponse(
			['error' => 'Validation failed', 'key' => 'api.errors.validationFailed', 'violations' => $violations],
			Response::HTTP_UNPROCESSABLE_ENTITY
		);
	}



	/**
	 * Extracts a field from a decoded JSON array, optionally filtering/transforming the value.
	 * The $filter callable receives the raw value and should return the processed value,
	 * or null/false if the value is invalid (matching filter_var behaviour).
	 * Throws ApiMissingFieldException if the field is absent or the filter rejects the value.
	 * @param array $data Decoded JSON array.
	 * @param string $key Field name to extract.
	 * @param callable|null $filter Optional callable: fn(mixed): mixed — returns processed value or null/false on failure.
	 * @return mixed The (filtered) field value.
	 * @throws ApiMissingFieldException
	 */
	protected function requireField(array $data, string $key, ?callable $filter = null): mixed
	{
		$value = $data[ $key ] ?? null;

		if ( $value === null )
		{
			throw new ApiMissingFieldException($key);
		}

		if ( $filter !== null )
		{
			$value = $filter($value);

			if ( $value === null || $value === false )
			{
				throw new ApiMissingFieldException($key);
			}
		}

		return $value;
	}



	/**
	 * Converts a ApiMissingFieldException into a 422 Unprocessable Entity error response.
	 */
	protected function missingFieldResponse(ApiMissingFieldException $e): JsonResponse
	{
		return $this->errorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, null, 'api.errors.missingField');
	}



	/**
	 * Handles the search functionality for a specific entity class.
	 * @param string $classname The fully qualified class name of the entity to search.
	 * @param Request $request The HTTP request containing the search parameters as JSON body.
	 * @param string $authLevel Defines the required authorization level for the search. Default is 'ROLE_ADMIN'.
	 * @param array $serializerGroups Array of serialization groups to be applied to the response data. Optional.
	 * @return JsonResponse JSON response containing the search results or an error message.
	 * @throws Exception If the user lacks required permissions or an error occurs during the search process.
	 */
	protected function handleSearch(string $classname, Request $request, string $authLevel = 'ROLE_ADMIN', array $serializerGroups = []): JsonResponse
	{
		try
		{
			$searchParams = ApiSearchParams::fromRequest($request);
		}
		catch ( \JsonException )
		{
			return $this->errorResponse('Invalid JSON format', Response::HTTP_BAD_REQUEST, null, 'api.errors.invalidJson');
		}

		$repository = $this->em->getRepository($classname);

		if ( !$repository instanceof ApiRepository )
		{
			return $this->errorResponse('Search is not supported for this entity.', Response::HTTP_INTERNAL_SERVER_ERROR, null, 'api.errors.searchError');
		}

		try
		{
			$result = $this->getEntityList($repository, $searchParams, $authLevel);
		}
		catch ( AccessDeniedException )
		{
			$status = $this->getUser() === null ? Response::HTTP_UNAUTHORIZED : Response::HTTP_FORBIDDEN;
			return $this->errorResponse('Access denied!', $status, null, 'api.errors.accessDenied');
		}

		// `count` is the number of items in this page; `maxCount` is the total number of
		// results matching the search term/filters (ignoring only the pagination limit).
		// The frontend paginator needs the filtered total, NOT the unfiltered grand total —
		// otherwise it renders pages for records the current search doesn't return.
		return $this->listResponse($result[ 'items' ], $serializerGroups, count($result[ 'items' ]), $result[ 'count' ]);
	}



	/**
	 * Handles the retrieval and response of an entity by class name and UUID, with authorization checks.
	 * @param string $classname The fully qualified class name of the entity to fetch.
	 * @param string $uuid The unique identifier of the entity to fetch.
	 * @param string $authLevel The required authorization level to access the entity. Default is 'ROLE_ADMIN'.
	 * @param array $serializerGroups Optional serialization groups to apply when generating the response.
	 * @return JsonResponse JSON response containing the fetched entity or an error message if the operation fails.
	 */
	protected function handleFetch(string $classname, string $uuid, string $authLevel = 'ROLE_ADMIN', array $serializerGroups = []): JsonResponse
	{
		try
		{
			$entity = $this->getEntity($classname, $uuid, $authLevel);
		}
		catch ( AccessDeniedException )
		{
			$status = $this->getUser() === null ? Response::HTTP_UNAUTHORIZED : Response::HTTP_FORBIDDEN;
			return $this->errorResponse('Access denied!', $status, null, 'api.errors.accessDenied');
		}

		if ( is_null($entity) )
		{
			return $this->errorResponse('Entity not found!', Response::HTTP_NOT_FOUND, null, 'api.errors.entityNotFound');
		}

		return $this->singleResponse($entity, $serializerGroups);
	}



	/**
	 * Handles the creation of an entity by deserializing the request data and saving it to the database.
	 * Checks the user's authorization level and validates the request body before processing.
	 * @param string $classname Fully qualified class name of the entity to be created.
	 * @param Request $request HTTP request containing the serialized entity data in JSON format.
	 * @param string $authLevel Required authorization level to perform the operation. Defaults to 'ROLE_ADMIN'.
	 * @return JsonResponse JSON response indicating the result of the operation.
	 *                       Returns an error response if the authorization fails, the request body is invalid,
	 *                       or there is an error during the persistence process.
	 */
	public function handleCreate(string $classname, Request $request, string $authLevel = 'ROLE_ADMIN'): JsonResponse
	{
		if ( !$this->can($authLevel, null, $classname, 'create') )
		{
			$status = $this->getUser() === null ? Response::HTTP_UNAUTHORIZED : Response::HTTP_FORBIDDEN;
			return $this->errorResponse('Access denied!', $status, null, 'api.errors.accessDenied');
		}

		if ( trim($request->getContent()) === '' )
		{
			return $this->errorResponse('Missing or invalid request body!', Response::HTTP_BAD_REQUEST, null, 'api.errors.invalidBody');
		}

		try
		{
			$entity = $this->serializer->deserialize($request->getContent(), $classname, 'json', ['ignored_attributes' => ['uuid']]);
			$this->em->persist($entity);
			$this->em->flush();
		}
		catch ( Exception $e )
		{
			$message = $this->kernel->getEnvironment() === 'dev' ? $e->getMessage() : 'Failed to save data!';

			return $this->errorResponse($message, Response::HTTP_INTERNAL_SERVER_ERROR, $e, 'api.errors.saveFailed');
		}

		return $this->createdResponse(['uuid' => $entity->getUuid()]);
	}



	/**
	 * Updates an existing entity with data provided in the request.
	 * @param string $classname The class name of the entity to update.
	 * @param Request $request The HTTP request containing the update data.
	 * @return JsonResponse JSON response indicating success, an error message if the entity is not found, or if the request data is invalid.
	 * @throws Exception|ExceptionInterface Thrown if an error occurs during the deserialization or persistence process.
	 */
	public function handleUpdate(string $classname, Request $request, string $authLevel = 'ROLE_ADMIN'): JsonResponse
	{
		// Loaded before the auth check so the entity is available as the
		// authorization subject (for resource-level decisions).
		$entity = $this->em->getRepository($classname)->findOneBy(['uuid' => $request->attributes->get('uuid')]);

		if ( !$this->can($authLevel, $entity, $classname, 'update') )
		{
			$status = $this->getUser() === null ? Response::HTTP_UNAUTHORIZED : Response::HTTP_FORBIDDEN;
			return $this->errorResponse('Access denied!', $status, null, 'api.errors.accessDenied');
		}

		if ( trim($request->getContent()) === '' )
		{
			return $this->errorResponse('Missing or invalid request body!', Response::HTTP_BAD_REQUEST, null, 'api.errors.invalidBody');
		}

		if ( is_null($entity) )
		{
			return $this->errorResponse('Record not found!', Response::HTTP_NOT_FOUND, null, 'api.errors.recordNotFound');
		}

		try
		{
			$this->serializer->deserialize($request->getContent(), $classname, 'json', [
				AbstractNormalizer::OBJECT_TO_POPULATE => $entity,
				'ignored_attributes'                   => ['uuid']
			]);

			$this->em->flush();
		}
		catch ( Exception $e )
		{
			$message = $this->kernel->getEnvironment() === 'dev' ? $e->getMessage() : 'Failed to save data!';

			return $this->errorResponse($message, Response::HTTP_INTERNAL_SERVER_ERROR, $e, 'api.errors.saveFailed');
		}

		return $this->successResponse();
	}



	/**
	 * Handles bulk create/update for an array of entity payloads in a single transaction.
	 * Items with a uuid are updated; items without are created.
	 * Returns the uuids of all items in the same order as the input.
	 */
	public function handleBulk(string $classname, Request $request, string $authLevel = 'ROLE_ADMIN'): JsonResponse
	{
		if ( !$this->can($authLevel, null, $classname, 'bulk') )
		{
			$status = $this->getUser() === null ? Response::HTTP_UNAUTHORIZED : Response::HTTP_FORBIDDEN;
			return $this->errorResponse('Access denied!', $status, null, 'api.errors.accessDenied');
		}

		$items = json_decode($request->getContent(), true);

		if ( !is_array($items) )
		{
			return $this->errorResponse('Expected a JSON array', Response::HTTP_BAD_REQUEST, null, 'api.errors.invalidBody');
		}

		$uuids = [];

		try
		{
			$this->em->beginTransaction();

			foreach ( $items as $data )
			{
				$uuid = $data[ 'uuid' ] ?? null;

				if ( $uuid )
				{
					$entity = $this->em->getRepository($classname)->findOneBy(['uuid' => $uuid]);

					if ( $entity === null )
					{
						$this->em->rollback();
						return $this->errorResponse("Record $uuid not found", Response::HTTP_NOT_FOUND, null, 'api.errors.recordNotFound');
					}

					$this->serializer->deserialize(json_encode($data), $classname, 'json', [
						AbstractNormalizer::OBJECT_TO_POPULATE => $entity,
						'ignored_attributes'                   => ['uuid'],
					]);

					$uuids[] = $uuid;
				}
				else
				{
					$entity = $this->serializer->deserialize(json_encode($data), $classname, 'json', [
						'ignored_attributes' => ['uuid'],
					]);

					$this->em->persist($entity);
					$uuids[] = $entity->getUuid();
				}
			}

			$this->em->flush();
			$this->em->commit();
		}
		catch ( Exception $e )
		{
			$this->em->rollback();
			$message = $this->kernel->getEnvironment() === 'dev' ? $e->getMessage() : 'Failed to save data!';
			return $this->errorResponse($message, Response::HTTP_INTERNAL_SERVER_ERROR, $e, 'api.errors.saveFailed');
		}

		return $this->successResponse(['uuids' => $uuids]);
	}



	/**
	 * Handles bulk deletion of entities by UUID in a single transaction.
	 */
	public function handleBulkDelete(string $classname, Request $request, string $authLevel = 'ROLE_ADMIN'): Response
	{
		if ( !$this->can($authLevel, null, $classname, 'delete') )
		{
			$status = $this->getUser() === null ? Response::HTTP_UNAUTHORIZED : Response::HTTP_FORBIDDEN;
			return $this->errorResponse('Access denied!', $status, null, 'api.errors.accessDenied');
		}

		$uuids = json_decode($request->getContent(), true);

		if ( !is_array($uuids) )
		{
			return $this->errorResponse('Expected a JSON array of UUIDs', Response::HTTP_BAD_REQUEST, null, 'api.errors.invalidBody');
		}

		try
		{
			$this->em->beginTransaction();

			foreach ( $uuids as $uuid )
			{
				$entity = $this->em->getRepository($classname)->findOneBy(['uuid' => $uuid]);

				if ( $entity === null )
				{
					$this->em->rollback();
					return $this->errorResponse("Record $uuid not found", Response::HTTP_NOT_FOUND, null, 'api.errors.recordNotFound');
				}

				$this->em->remove($entity);
			}

			$this->em->flush();
			$this->em->commit();
		}
		catch ( Exception $e )
		{
			$this->em->rollback();
			$message = $this->kernel->getEnvironment() === 'dev' ? $e->getMessage() : 'Failed to delete data!';
			return $this->errorResponse($message, Response::HTTP_INTERNAL_SERVER_ERROR, $e, 'api.errors.deleteFailed');
		}

		return $this->noContentResponse();
	}



	/**
	 * Resolves a UUID string to a Doctrine entity instance.
	 * Returns null if the UUID is null or no matching entity is found.
	 * Use this in custom create/update methods to look up relations sent as UUIDs from the frontend.
	 */
	protected function resolveByUuid(string $classname, ?string $uuid): ?object
	{
		if ( $uuid === null )
		{
			return null;
		}

		return $this->em->getRepository($classname)->findOneBy(['uuid' => $uuid]);
	}



	/**
	 * Authorization gate used by all the handle* and getEntity* helpers (and by
	 * your own controllers via {@see can()}). The default delegates to Symfony's
	 * voter system, so {@see AuthorizationContext::$action} is a role/attribute
	 * (e.g. 'ROLE_ADMIN', 'IS_AUTHENTICATED_FULLY').
	 *
	 * Override this in a base controller to plug in a completely different
	 * permission system. The {@see AuthorizationContext} carries everything the
	 * decision might need — action token, subject + its class, the operation,
	 * the current user and request — so an override can ignore Symfony roles
	 * entirely and decide however it likes. Read only the fields you need; new
	 * ones may be added later without changing this signature.
	 */
	protected function isAuthorized(AuthorizationContext $context): bool
	{
		return $this->isGranted($context->action, $context->subject);
	}



	/**
	 * Assembles an {@see AuthorizationContext} (auto-filling the current user and
	 * request) and runs it through {@see isAuthorized()}. This is the single entry
	 * point every authorization check should go through — both the built-in
	 * handlers and your own controller actions — so a custom isAuthorized()
	 * override intercepts them all.
	 *
	 * @param string $action Permission token (a role for the default implementation).
	 * @param object|null $subject The entity the action concerns, when loaded.
	 * @param string|null $subjectClass FQCN of the entity the action concerns, when known.
	 * @param string|null $operation The CRUD operation ('search'|'fetch'|'create'|'update'|'bulk'|'delete').
	 */
	protected function can(string $action, ?object $subject = null, ?string $subjectClass = null, ?string $operation = null): bool
	{
		return $this->isAuthorized(new AuthorizationContext(
			action      : $action,
			subjectClass: $subjectClass,
			subject     : $subject,
			operation   : $operation,
			user        : $this->getUser(),
			request     : $this->requestStack->getCurrentRequest(),
		));
	}



	/**
	 * Retrieves an entity by its class name and UUID, ensuring the user has the required authorization level.
	 * @param string $classname The fully qualified class name of the entity to retrieve.
	 * @param string $uuid The UUID of the entity to retrieve.
	 * @param string $authLevel The required authorization level to access the entity. Defaults to 'ROLE_ADMIN'.
	 * @return object|null The entity object if found, or null if no matching entity is found.
	 * @throws Exception If the user does not have the necessary authorization.
	 */
	protected function getEntity(string $classname, string $uuid, string $authLevel = 'ROLE_ADMIN'): ?object
	{
		// Loaded before the auth check so the entity is available as the
		// authorization subject (for resource-level decisions).
		$entity = $this->em->getRepository($classname)->findOneBy(['uuid' => $uuid]);

		if ( !$this->can($authLevel, $entity, $classname, 'fetch') )
		{
			throw new AccessDeniedException();
		}

		return $entity;
	}



	/**
	 * Retrieves a repository for the specified class name and ensures it extends ApiRepository.
	 * @param string $classname Fully qualified class name of the entity for which the repository is retrieved.
	 * @return ApiRepository The repository instance for the specified class.
	 * @throws InvalidArgumentException If the repository does not extend ApiRepository.
	 */
	protected function repository(string $classname): ApiRepository
	{
		$repo = $this->em->getRepository($classname);

		if ( !$repo instanceof ApiRepository )
		{
			throw new InvalidArgumentException("Repository for $classname does not extend ApiRepository.");
		}

		return $repo;
	}



	/**
	 * Retrieves a list of entities based on search parameters and access level.
	 * @param ApiRepository $repository The repository to query entities from.
	 * @param ApiSearchParams $searchParams Parameters for search, including term, filters, offset, limit, and sorting options.
	 * @param string $authLevel Required authorization level for access. Defaults to 'ROLE_ADMIN'.
	 * @return array An array containing the list of items and the total count.
	 * @throws Exception If the user does not have the required access level.
	 */
	protected function getEntityList(ApiRepository $repository, ApiSearchParams $searchParams, string $authLevel = 'ROLE_ADMIN'): array
	{
		if ( !$this->can($authLevel, null, $repository->getClassName(), 'search') )
		{
			throw new AccessDeniedException();
		}

		$search = $repository->search(
			$searchParams->term,
			$searchParams->filters,
			$searchParams->offset,
			$searchParams->limit,
			$searchParams->sort
		);

		return ['items' => $search[ 'items' ], 'count' => $search[ 'count' ]];
	}



	/**
	 * Retrieves a random list of entities based on the provided search parameters and authorization level.
	 * @param ApiRepository $repository Repository instance used to perform the search.
	 * @param ApiSearchParams $searchParams Object containing search criteria such as term, filters, and limit.
	 * @param string $authLevel Authorization level required to access this functionality. Defaults to 'ROLE_ADMIN'.
	 * @return array An associative array containing the list of entities ('items') and the total count ('count').
	 * @throws Exception If the current user does not have the required authorization level.
	 */
	protected function getRandomEntityList(ApiRepository $repository, ApiSearchParams $searchParams, string $authLevel = 'ROLE_ADMIN'): array
	{
		if ( !$this->can($authLevel, null, $repository->getClassName(), 'search') )
		{
			throw new AccessDeniedException();
		}

		$search = $repository->searchRandom($searchParams->term, $searchParams->filters, $searchParams->limit);

		return ['items' => $search[ 'items' ], 'count' => $search[ 'count' ]];
	}
}
