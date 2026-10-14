<?php

namespace pmaerksch\Kohlrapi\Tests;

use LogicException;
use pmaerksch\Kohlrapi\ApiController;
use pmaerksch\Kohlrapi\AuthorizationContext;
use pmaerksch\Kohlrapi\Tests\Fixtures\Item;
use pmaerksch\Kohlrapi\Tests\Fixtures\SqlStateMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

class WriteHandlersTest extends KohlrapiTestCase
{
	public function testCreatePersistsAndReturnsUuid(): void
	{
		$response = $this->controller()->handleCreate(Item::class, $this->request('{"name":"a","qty":3}'));

		$this->assertSame(201, $response->getStatusCode());
		$item = $this->em->getRepository(Item::class)->findOneBy(['uuid' => $this->body($response)['uuid']]);
		$this->assertSame('a', $item?->getName());
		$this->assertSame(3, $item->getQty());
	}



	public function testCreateIgnoresClientUuid(): void
	{
		$response = $this->controller()->handleCreate(Item::class, $this->request('{"name":"a","uuid":"00000000-0000-0000-0000-000000000000"}'));

		$this->assertNotSame('00000000-0000-0000-0000-000000000000', $this->body($response)['uuid']);
	}



	public function testCreateWithEmptyBodyIs400(): void
	{
		$response = $this->controller()->handleCreate(Item::class, $this->request(''));

		$this->assertSame(400, $response->getStatusCode());
		$this->assertSame('api.errors.invalidBody', $this->body($response)['key']);
	}



	public function testMalformedJsonIs400(): void
	{
		$response = $this->controller()->handleCreate(Item::class, $this->request('{bad json'));

		$this->assertSame(400, $response->getStatusCode());
		$this->assertSame('api.errors.invalidJson', $this->body($response)['key']);
	}



	public function testWrongValueTypeIs400(): void
	{
		$response = $this->controller()->handleCreate(Item::class, $this->request('{"name":"a","qty":"lots"}'));

		$this->assertSame(400, $response->getStatusCode());
		$this->assertSame('api.errors.invalidBody', $this->body($response)['key']);
	}



	public function testInvalidEntityIs422WithViolations(): void
	{
		$response = $this->controller()->handleCreate(Item::class, $this->request('{"name":""}'));

		$this->assertSame(422, $response->getStatusCode());
		$this->assertArrayHasKey('name', $this->body($response)['violations']);
		$this->assertSame(0, $this->em->getRepository(Item::class)->count([]));
	}



	public function testUpdateAppliesPayload(): void
	{
		$item     = $this->createItem('old');
		$response = $this->controller()->handleUpdate(Item::class, $this->request('{"name":"new"}', ['uuid' => $item->getUuid()]));

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('new', $this->reload($item)?->getName());
	}



	public function testUpdateWithExplicitUuid(): void
	{
		$item     = $this->createItem('old');
		$response = $this->controller()->handleUpdate(Item::class, $this->request('{"name":"new"}', ['id' => $item->getUuid()]), 'ROLE_ADMIN', $item->getUuid());

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('new', $this->reload($item)?->getName());
	}



	public function testUpdateOfUnknownUuidIs404(): void
	{
		$response = $this->controller()->handleUpdate(Item::class, $this->request('{"name":"x"}', ['uuid' => 'a3c1b2d4-0000-4000-8000-000000000000']));

		$this->assertSame(404, $response->getStatusCode());
	}



	public function testRejectedUpdateIsDiscarded(): void
	{
		$item       = $this->createItem('keep');
		$controller = $this->controller();
		$response   = $controller->handleUpdate(Item::class, $this->request('{"name":""}', ['uuid' => $item->getUuid()]));
		$this->em->flush(); // a later flush in the same request must not persist the rejected change

		$this->assertSame(422, $response->getStatusCode());
		$this->assertSame('keep', $this->reload($item)?->getName());
	}



	// --- Writable fields (S1) ---

	public function testGroupsRestrictWritableFields(): void
	{
		$controller          = $this->controller();
		$controller->context = ['groups' => ['write']];
		$response            = $controller->handleCreate(Item::class, $this->request('{"name":"a","owner":"mallory"}'));

		$item = $this->em->getRepository(Item::class)->findOneBy(['uuid' => $this->body($response)['uuid']]);
		$this->assertSame('a', $item?->getName());
		$this->assertSame('alice', $item->getOwner());
	}



	public function testAttributesRestrictWritableFields(): void
	{
		$controller          = $this->controller();
		$controller->context = ['attributes' => ['name']];
		$response            = $controller->handleCreate(Item::class, $this->request('{"name":"a","owner":"mallory","qty":5}'));

		$item = $this->em->getRepository(Item::class)->findOneBy(['uuid' => $this->body($response)['uuid']]);
		$this->assertSame('alice', $item?->getOwner());
		$this->assertSame(0, $item->getQty());
	}



	public function testOwnIgnoredAttributesAreMergedWithUuid(): void
	{
		$item                = $this->createItem('keep');
		$controller          = $this->controller();
		$controller->context = ['groups' => ['write'], 'ignored_attributes' => ['name']];
		$controller->handleUpdate(Item::class, $this->request('{"name":"x","qty":2,"uuid":"00000000-0000-0000-0000-000000000000"}', ['uuid' => $item->getUuid()]));

		$reloaded = $this->reload($item);
		$this->assertSame('keep', $reloaded?->getName());
		$this->assertSame(2, $reloaded->getQty());
	}



	public function testWriteAllFieldsAllowsEveryField(): void
	{
		$response = $this->controller()->handleCreate(Item::class, $this->request('{"name":"a","owner":"bob"}'));

		$item = $this->em->getRepository(Item::class)->findOneBy(['uuid' => $this->body($response)['uuid']]);
		$this->assertSame('bob', $item?->getOwner());
	}



	public static function unrestrictedContexts(): array
	{
		return [
			'empty'                 => [[]],
			'empty groups'          => [['groups' => []]],
			'wildcard group'        => [['groups' => ['*']]],
			'wildcard group string' => [['groups' => '*']],
			'null attributes'       => [['attributes' => null]],
			'write-all not true'    => [[ApiController::WRITE_ALL_FIELDS => 1]],
		];
	}



	#[DataProvider('unrestrictedContexts')]
	public function testUnrestrictedContextThrows(array $context): void
	{
		$controller          = $this->controller();
		$controller->context = $context;

		$this->expectException(LogicException::class);
		$controller->handleCreate(Item::class, $this->request('{"name":"a"}'));
	}



	public function testUnrestrictedUpdateAndBulkThrowWithoutWriting(): void
	{
		$item                = $this->createItem('keep');
		$controller          = $this->controller();
		$controller->context = [];

		foreach ( [
			fn() => $controller->handleUpdate(Item::class, $this->request('{"name":"x"}', ['uuid' => $item->getUuid()])),
			fn() => $controller->handleBulk(Item::class, $this->request('[{"name":"x"}]')),
		] as $call )
		{
			try
			{
				$call();
				$this->fail('Expected a LogicException');
			}
			catch ( LogicException )
			{
			}
		}

		$this->assertSame('keep', $this->reload($item)?->getName());
		$this->assertSame(1, $this->em->getRepository(Item::class)->count([]));
	}



	// --- Authorization ---

	public function testDeniedCreateIs401ForAnonymous(): void
	{
		$controller             = $this->controller();
		$controller->authorizer = fn() => false;
		$response               = $controller->handleCreate(Item::class, $this->request('{"name":"a"}'));

		$this->assertSame(401, $response->getStatusCode());
		$this->assertSame('api.errors.accessDenied', $this->body($response)['key']);
	}



	public function testUpdateIsAuthorizedWithEntityAsSubject(): void
	{
		$item       = $this->createItem('a');
		$controller = $this->controller();
		$controller->handleUpdate(Item::class, $this->request('{"name":"b"}', ['uuid' => $item->getUuid()]), 'ROLE_EDITOR');

		$first = $controller->authCalls[0];
		$this->assertSame('ROLE_EDITOR', $first->action);
		$this->assertSame('update', $first->operation);
		$this->assertSame($item->getUuid(), $first->subject?->getUuid());
		$this->assertSame(Item::class, $first->subjectClass);
	}



	public function testWriteHandlersAreProtected(): void
	{
		foreach ( ['handleCreate', 'handleUpdate', 'handleBulk', 'handleBulkDelete'] as $method )
		{
			$this->assertTrue((new ReflectionMethod(ApiController::class, $method))->isProtected(), $method);
		}
	}



	// --- Bulk ---

	public function testBulkCreatesAndUpdatesInOrder(): void
	{
		$item     = $this->createItem('old');
		$response = $this->controller()->handleBulk(Item::class, $this->request(json_encode([
			['name' => 'new'],
			['uuid' => $item->getUuid(), 'name' => 'changed'],
		])));

		$this->assertSame(200, $response->getStatusCode());
		$uuids = $this->body($response)['uuids'];
		$this->assertCount(2, $uuids);
		$this->assertSame($item->getUuid(), $uuids[1]);
		$this->assertSame('changed', $this->reload($item)?->getName());
		$this->assertSame(2, $this->em->getRepository(Item::class)->count([]));
	}



	public function testBulkIsAllOrNothingOnValidationErrors(): void
	{
		$item     = $this->createItem('old');
		$response = $this->controller()->handleBulk(Item::class, $this->request(json_encode([
			['uuid' => $item->getUuid(), 'name' => 'changed'],
			['name' => ''],
		])));
		$this->em->flush();

		$this->assertSame(422, $response->getStatusCode());
		$this->assertArrayHasKey('1.name', $this->body($response)['violations']);
		$this->assertSame('old', $this->reload($item)?->getName());
		$this->assertSame(1, $this->em->getRepository(Item::class)->count([]));
	}



	public function testBulkAcceptsWhatSingleCreateAccepts(): void
	{
		// 1e400 decodes to INF, which json_encode() can't re-encode; an ignored extra
		// attribute must not break a bulk item when the same payload creates fine alone.
		$payload = '{"name":"a","ignored":1e400}';

		$this->assertSame(201, $this->controller()->handleCreate(Item::class, $this->request($payload))->getStatusCode());
		$this->assertSame(200, $this->controller()->handleBulk(Item::class, $this->request("[$payload]"))->getStatusCode());
	}



	public function testBulkWrongValueTypeIs400(): void
	{
		$response = $this->controller()->handleBulk(Item::class, $this->request('[{"name":"a","qty":"lots"}]'));

		$this->assertSame(400, $response->getStatusCode());
	}



	public function testBulkRejectsNonListBodies(): void
	{
		foreach ( ['{"name":"a"}', '["a"]', '[{"uuid":5}]', 'nope'] as $body )
		{
			$this->assertSame(400, $this->controller()->handleBulk(Item::class, $this->request($body))->getStatusCode(), $body);
		}
	}



	public function testBulkItemLimit(): void
	{
		$response = $this->controller()->handleBulk(Item::class, $this->request('[{"name":"a"},{"name":"b"}]'), 'ROLE_ADMIN', 1);

		$this->assertSame(413, $response->getStatusCode());
		$this->assertSame('api.errors.tooManyItems', $this->body($response)['key']);
	}



	public function testBulkLoadsEntitiesWithOneQuery(): void
	{
		$payload = [];

		for ( $i = 0; $i < 20; $i++ )
		{
			$payload[] = ['uuid' => $this->createItem("item$i")->getUuid(), 'name' => "renamed$i"];
		}

		$this->em->clear();
		SqlStateMiddleware::$executed = [];
		$response = $this->controller()->handleBulk(Item::class, $this->request(json_encode($payload)));
		$selects  = array_filter(SqlStateMiddleware::$executed, fn(string $sql) => str_starts_with($sql, 'SELECT'));

		$this->assertSame(200, $response->getStatusCode());
		$this->assertCount(1, $selects);
	}



	public function testBulkDeleteLoadsEntitiesWithOneQuery(): void
	{
		$uuids = [];

		for ( $i = 0; $i < 20; $i++ )
		{
			$uuids[] = $this->createItem("item$i")->getUuid();
		}

		$this->em->clear();
		SqlStateMiddleware::$executed = [];
		$response = $this->controller()->handleBulkDelete(Item::class, $this->request(json_encode($uuids)));
		// Only the entity lookups: the cascade delete of each item's tags adds its own SELECTs.
		$selects  = array_filter(SqlStateMiddleware::$executed, fn(string $sql) => str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM Item'));

		$this->assertSame(204, $response->getStatusCode());
		$this->assertCount(1, $selects);
		$this->assertSame(0, $this->em->getRepository(Item::class)->count([]));
	}



	public function testBulkMatchesUuidsCaseInsensitively(): void
	{
		$item = $this->createItem('a');
		$this->em->getConnection()->executeStatement('UPDATE item SET uuid = UPPER(uuid)');
		$this->em->clear();

		$response = $this->controller()->handleBulk(Item::class, $this->request(json_encode([['uuid' => strtoupper((string)$item->getUuid()), 'name' => 'b']])));

		$this->assertSame(200, $response->getStatusCode());
	}



	public function testBulkWithOneMalformedUuidStillFindsTheOthers(): void
	{
		$item     = $this->createItem('a');
		$response = $this->controller()->handleBulk(Item::class, $this->request(json_encode([
			['uuid' => $item->getUuid(), 'name' => 'b'],
			['uuid' => SqlStateMiddleware::marker('22P02'), 'name' => 'c'],
		])));

		// the valid uuid is still resolved; the malformed one is reported as not found
		$this->assertSame(404, $response->getStatusCode());
		$this->assertSame(SqlStateMiddleware::marker('22P02'), $this->body($response)['uuid']);
	}



	public function testBulkDeleteRemovesAll(): void
	{
		$a        = $this->createItem('a');
		$b        = $this->createItem('b');
		$response = $this->controller()->handleBulkDelete(Item::class, $this->request(json_encode([$a->getUuid(), $b->getUuid()])));

		$this->assertSame(204, $response->getStatusCode());
		$this->assertSame(0, $this->em->getRepository(Item::class)->count([]));
	}



	public function testBulkDeleteIsAllOrNothing(): void
	{
		$a        = $this->createItem('a');
		$response = $this->controller()->handleBulkDelete(Item::class, $this->request(json_encode([$a->getUuid(), 'a3c1b2d4-0000-4000-8000-000000000000'])));
		$this->em->flush();

		$this->assertSame(404, $response->getStatusCode());
		$this->assertSame(1, $this->em->getRepository(Item::class)->count([]));
	}



	public function testBulkDeleteOperations(): void
	{
		$a          = $this->createItem('a');
		$controller = $this->controller();
		$controller->handleBulkDelete(Item::class, $this->request(json_encode([$a->getUuid()])));

		$this->assertSame('bulkDelete', $controller->authCalls[0]->operation);
		$this->assertNull($controller->authCalls[0]->subject);
		$this->assertSame('delete', $controller->authCalls[1]->operation);
		$this->assertSame($a->getUuid(), $controller->authCalls[1]->subject?->getUuid());
	}



	public function testBulkDeleteAuthorizesEachEntity(): void
	{
		$a                      = $this->createItem('a');
		$b                      = $this->createItem('b', 'bob');
		$controller             = $this->controller();
		$controller->authorizer = fn(AuthorizationContext $c) => $c->subject === null || $c->subject->getOwner() === 'alice';
		$response               = $controller->handleBulkDelete(Item::class, $this->request(json_encode([$a->getUuid(), $b->getUuid()])));
		$this->em->flush();

		$this->assertGreaterThanOrEqual(400, $response->getStatusCode());
		$this->assertSame(2, $this->em->getRepository(Item::class)->count([]));
	}
}
