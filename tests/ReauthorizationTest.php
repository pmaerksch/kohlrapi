<?php

namespace pmaerksch\Kohlrapi\Tests;

use pmaerksch\Kohlrapi\AuthorizationContext;
use pmaerksch\Kohlrapi\Tests\Fixtures\Item;
use pmaerksch\Kohlrapi\Tests\Fixtures\TestController;

/**
 * reauthorizesWrites(): the written state is authorized again before flush.
 */
class ReauthorizationTest extends KohlrapiTestCase
{
	/**
	 * A controller whose ".after" checks only allow entities owned by alice.
	 */
	private function ownerChecked(?bool $reauth = true): TestController
	{
		$controller             = $this->controller();
		$controller->reauth     = $reauth;
		$controller->authorizer = fn(AuthorizationContext $c) => !str_ends_with((string)$c->operation, '.after') || $c->subject->getOwner() === 'alice';

		return $controller;
	}



	public function testOnByDefault(): void
	{
		$item     = $this->createItem('a');
		$response = $this->ownerChecked(null)->handleUpdate(Item::class, $this->request('{"owner":"bob"}', ['uuid' => $item->getUuid()]));

		$this->assertSame(401, $response->getStatusCode());
		$this->assertSame('alice', $this->reload($item)?->getOwner());
	}



	public function testOffAllowsMovingAnEntity(): void
	{
		$item     = $this->createItem('a');
		$response = $this->ownerChecked(false)->handleUpdate(Item::class, $this->request('{"owner":"bob"}', ['uuid' => $item->getUuid()]));

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('bob', $this->reload($item)?->getOwner());
	}



	public function testUpdateAfterDeniesAndDiscards(): void
	{
		$item     = $this->createItem('a');
		$response = $this->ownerChecked()->handleUpdate(Item::class, $this->request('{"owner":"bob","name":"changed"}', ['uuid' => $item->getUuid()]));
		$this->em->flush();

		$this->assertSame(401, $response->getStatusCode());
		$reloaded = $this->reload($item);
		$this->assertSame('alice', $reloaded?->getOwner());
		$this->assertSame('a', $reloaded->getName());
	}



	public function testCreateAfterDenies(): void
	{
		$response = $this->ownerChecked()->handleCreate(Item::class, $this->request('{"name":"d","owner":"bob"}'));

		$this->assertSame(401, $response->getStatusCode());
		$this->assertSame(0, $this->em->getRepository(Item::class)->count([]));
	}



	public function testBulkDenialDiscardsEverything(): void
	{
		$item     = $this->createItem('a');
		$response = $this->ownerChecked()->handleBulk(Item::class, $this->request(json_encode([
			['uuid' => $item->getUuid(), 'name' => 'changed'],
			['name' => 'new', 'owner' => 'bob'],
		])));
		$this->em->flush();

		$this->assertSame(401, $response->getStatusCode());
		$this->assertSame('a', $this->reload($item)?->getName());
		$this->assertSame(1, $this->em->getRepository(Item::class)->count([]));
	}



	public function testBulkAllowedWhenStateStaysPermitted(): void
	{
		$item     = $this->createItem('a');
		$response = $this->ownerChecked()->handleBulk(Item::class, $this->request(json_encode([
			['uuid' => $item->getUuid(), 'name' => 'changed'],
			['name' => 'new'],
		])));

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('changed', $this->reload($item)?->getName());
	}



	public function testAfterCheckReceivesResultingEntity(): void
	{
		$item       = $this->createItem('a');
		$controller = $this->ownerChecked();
		$controller->handleUpdate(Item::class, $this->request('{"name":"b"}', ['uuid' => $item->getUuid()]));

		$after = array_values(array_filter($controller->authCalls, fn(AuthorizationContext $c) => $c->operation === 'update.after'));
		$this->assertCount(1, $after);
		$this->assertSame('b', $after[0]->subject->getName());
	}
}
