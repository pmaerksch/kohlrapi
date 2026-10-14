<?php

namespace pmaerksch\Kohlrapi\Tests;

use InvalidArgumentException;
use pmaerksch\Kohlrapi\ApiMissingFieldException;
use pmaerksch\Kohlrapi\ApiSearchParams;
use pmaerksch\Kohlrapi\JsonHelper;
use pmaerksch\Kohlrapi\Tests\Fixtures\Item;
use pmaerksch\Kohlrapi\Tests\Fixtures\TestUser;

/**
 * ApiSearchParams, JsonHelper, ApiEntity and the error-detail gate.
 */
class ValueObjectsTest extends KohlrapiTestCase
{
	public function testSearchParamsCoerceMalformedInput(): void
	{
		$params = ApiSearchParams::fromArray(['limit' => 'abc', 'offset' => -5, 'term' => ['x'], 'filters' => 'abc', 'sort' => 'name']);

		$this->assertSame(25, $params->limit);
		$this->assertSame(0, $params->offset);
		$this->assertSame('', $params->term);
		$this->assertSame([], $params->filters);
		$this->assertNull($params->sort);
	}



	public function testSearchParamsCapLimit(): void
	{
		$this->assertSame(25, ApiSearchParams::fromArray(['limit' => 501])->limit);
		$this->assertSame(500, ApiSearchParams::fromArray(['limit' => 500])->limit);
	}



	public function testJsonHelperRequire(): void
	{
		$json = new JsonHelper('{"a":"0","b":null,"c":""}');

		$this->assertSame('0', $json->require('a'));
		$this->assertSame('', $json->require('c', true));
		$this->assertSame('d', $json->optional('missing', 'd'));

		foreach ( ['b', 'c', 'missing'] as $key )
		{
			try
			{
				$json->require($key);
				$this->fail("require('$key') should throw");
			}
			catch ( ApiMissingFieldException $e )
			{
				$this->assertSame($key, $e->getField());
			}
		}
	}



	public function testJsonHelperRejectsNonObjects(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new JsonHelper('5');
	}



	public function testEntityGetsUuidOnConstruction(): void
	{
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)(new Item())->getUuid());
		$this->assertSame('given', (new Item('given'))->getUuid());
	}



	public function testErrorDetailOnlyForPermittedUsers(): void
	{
		$plain   = $this->controller(null, new TestUser('alice', false))->handleCreate(Item::class, $this->request('{bad'));
		$allowed = $this->controller(null, new TestUser('dev', true))->handleCreate(Item::class, $this->request('{bad'));

		$this->assertNull($this->body($plain)['error']);
		$this->assertIsString($this->body($allowed)['error']);
	}



	public function testStructuredDetailsReachEveryUser(): void
	{
		$controller = $this->controller(null, new TestUser('alice', false));

		$missing = $this->body((fn() => $this->missingFieldResponse(new ApiMissingFieldException('name')))->call($controller));
		$this->assertNull($missing['error']);
		$this->assertSame('name', $missing['field']);

		$tooMany = $this->body($controller->handleBulk(Item::class, $this->request('[{"name":"a"},{"name":"b"}]'), 'ROLE_ADMIN', 1));
		$this->assertSame(1, $tooMany['maxItems']);

		$uuid     = 'a3c1b2d4-0000-4000-8000-000000000000';
		$notFound = $this->body($controller->handleBulkDelete(Item::class, $this->request(json_encode([$uuid]))));
		$this->assertSame($uuid, $notFound['uuid']);
	}



	public function testNotFoundResponse(): void
	{
		$response = (fn() => $this->notFoundResponse())->call($this->controller());

		$this->assertSame(404, $response->getStatusCode());
		$this->assertSame('api.errors.recordNotFound', $this->body($response)['key']);
	}



	public function testDetailsCannotOverrideErrorOrKey(): void
	{
		$controller = $this->controller();
		$body       = $this->body((fn() => $this->errorResponse('secret', 400, null, 'k', ['error' => 'x', 'key' => 'y', 'extra' => 1]))->call($controller));

		$this->assertNull($body['error']);
		$this->assertSame('k', $body['key']);
		$this->assertSame(1, $body['extra']);
	}



	public function testClientErrorsAreLoggedAsInfoServerErrorsAsError(): void
	{
		$controller = $this->controller();
		$controller->handleCreate(Item::class, $this->request('{bad'));
		(fn() => $this->errorResponse('boom', 500, new \RuntimeException('boom'), 'k'))->call($controller);

		$this->assertSame('info', $this->logs[0][0]);
		$this->assertSame(400, $this->logs[0][2]['status']);
		$this->assertSame('error', $this->logs[1][0]);
		$this->assertInstanceOf(\RuntimeException::class, $this->logs[1][2]['exception']);
	}



	public function testDeniedIs403ForAuthenticatedUsers(): void
	{
		$controller             = $this->controller(null, new TestUser());
		$controller->authorizer = fn() => false;

		$this->assertSame(403, $controller->handleCreate(Item::class, $this->request('{"name":"a"}'))->getStatusCode());
	}
}
