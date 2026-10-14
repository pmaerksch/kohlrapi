<?php

namespace pmaerksch\Kohlrapi\Tests\Fixtures;

use Closure;
use pmaerksch\Kohlrapi\ApiController;
use pmaerksch\Kohlrapi\ApiRepository;
use pmaerksch\Kohlrapi\ApiSearchParams;
use pmaerksch\Kohlrapi\AuthorizationContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes the protected handlers and makes the hooks configurable per test.
 * Redeclaring the handlers as public also checks that subclasses may widen them.
 */
class TestController extends ApiController
{
	/** Returned by getDenormalizationContext(). */
	public array $context = [self::WRITE_ALL_FIELDS => true];

	/** Overrides reauthorizesWrites() when set; null keeps the library default. */
	public ?bool $reauth = null;

	/** Decides isAuthorized(); null allows everything. */
	public ?Closure $authorizer = null;

	/** Every AuthorizationContext passed to isAuthorized(), in order. */
	public array $authCalls = [];



	protected function getDenormalizationContext(string $classname, string $operation): array
	{
		return $this->context;
	}



	protected function reauthorizesWrites(): bool
	{
		return $this->reauth ?? parent::reauthorizesWrites();
	}



	protected function isAuthorized(AuthorizationContext $context): bool
	{
		$this->authCalls[] = $context;

		return $this->authorizer === null || ($this->authorizer)($context);
	}



	public function handleCreate(string $classname, Request $request, string $authLevel = 'ROLE_ADMIN'): JsonResponse
	{
		return parent::handleCreate($classname, $request, $authLevel);
	}



	public function handleUpdate(string $classname, Request $request, string $authLevel = 'ROLE_ADMIN', ?string $uuid = null): JsonResponse
	{
		return parent::handleUpdate($classname, $request, $authLevel, $uuid);
	}



	public function handleBulk(string $classname, Request $request, string $authLevel = 'ROLE_ADMIN', ?int $maxItems = 1000): JsonResponse
	{
		return parent::handleBulk($classname, $request, $authLevel, $maxItems);
	}



	public function handleBulkDelete(string $classname, Request $request, string $authLevel = 'ROLE_ADMIN', ?int $maxItems = 1000): Response
	{
		return parent::handleBulkDelete($classname, $request, $authLevel, $maxItems);
	}



	public function handleFetch(string $classname, string $uuid, string $authLevel = 'ROLE_ADMIN', array $serializerGroups = []): JsonResponse
	{
		return parent::handleFetch($classname, $uuid, $authLevel, $serializerGroups);
	}



	public function handleSearch(string $classname, Request $request, string $authLevel = 'ROLE_ADMIN', array $serializerGroups = []): JsonResponse
	{
		return parent::handleSearch($classname, $request, $authLevel, $serializerGroups);
	}



	public function getRandomEntityList(ApiRepository $repository, ApiSearchParams $searchParams, string $authLevel = 'ROLE_ADMIN'): array
	{
		return parent::getRandomEntityList($repository, $searchParams, $authLevel);
	}
}
