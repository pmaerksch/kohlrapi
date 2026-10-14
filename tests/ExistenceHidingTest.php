<?php

namespace pmaerksch\Kohlrapi\Tests;

use pmaerksch\Kohlrapi\AuthorizationContext;
use pmaerksch\Kohlrapi\Tests\Fixtures\Item;
use pmaerksch\Kohlrapi\Tests\Fixtures\TestController;
use pmaerksch\Kohlrapi\Tests\Fixtures\TestUser;

/**
 * A forbidden entity must be indistinguishable from a missing one (S3).
 */
class ExistenceHidingTest extends KohlrapiTestCase
{
	private const MISSING = 'a3c1b2d4-0000-4000-8000-000000000000';



	/**
	 * Entities owned by bob are forbidden; the class-level check (no subject) answers $classLevel.
	 */
	private function controllerFor(bool $classLevel): TestController
	{
		$controller             = $this->controller(null, new TestUser());
		$controller->authorizer = fn(AuthorizationContext $c) => $c->subject === null ? $classLevel : $c->subject->getOwner() !== 'bob';

		return $controller;
	}



	public function testFetchAnswersForbiddenLikeMissing(): void
	{
		$hidden = $this->createItem('secret', 'bob');

		foreach ( [true => 404, false => 403] as $classLevel => $expected )
		{
			$controller = $this->controllerFor((bool)$classLevel);

			$this->assertSame($expected, $controller->handleFetch(Item::class, $hidden->getUuid())->getStatusCode());
			$this->assertSame($expected, $controller->handleFetch(Item::class, self::MISSING)->getStatusCode());
		}
	}



	public function testUpdateAnswersForbiddenLikeMissing(): void
	{
		$hidden = $this->createItem('secret', 'bob');

		foreach ( ['{"name":"x"}', ''] as $body )
		{
			foreach ( [true, false] as $classLevel )
			{
				$controller = $this->controllerFor($classLevel);
				$forbidden  = $controller->handleUpdate(Item::class, $this->request($body, ['uuid' => $hidden->getUuid()]));
				$missing    = $controller->handleUpdate(Item::class, $this->request($body, ['uuid' => self::MISSING]));

				$this->assertSame($missing->getStatusCode(), $forbidden->getStatusCode());
				$this->assertSame($this->body($missing)['key'], $this->body($forbidden)['key']);
			}
		}

		$this->assertSame('secret', $this->reload($hidden)?->getName());
	}



	public function testBulkAnswersForbiddenLikeMissing(): void
	{
		$hidden     = $this->createItem('secret', 'bob');
		$controller = $this->controllerFor(true);

		foreach ( [$hidden->getUuid(), self::MISSING] as $uuid )
		{
			$this->assertSame(404, $controller->handleBulk(Item::class, $this->request(json_encode([['uuid' => $uuid, 'name' => 'x']])))->getStatusCode());
			$this->assertSame(404, $controller->handleBulkDelete(Item::class, $this->request(json_encode([$uuid])))->getStatusCode());
		}

		$this->assertSame('secret', $this->reload($hidden)?->getName());
	}



	public function testAnonymousStillGets401(): void
	{
		$hidden                 = $this->createItem('secret', 'bob');
		$controller             = $this->controller();
		$controller->authorizer = fn(AuthorizationContext $c) => false;

		$this->assertSame(401, $controller->handleFetch(Item::class, $hidden->getUuid())->getStatusCode());
		$this->assertSame(401, $controller->handleFetch(Item::class, self::MISSING)->getStatusCode());
	}
}
