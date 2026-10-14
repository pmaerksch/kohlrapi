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



	public function testDeniedIs403ForAuthenticatedUsers(): void
	{
		$controller             = $this->controller(null, new TestUser());
		$controller->authorizer = fn() => false;

		$this->assertSame(403, $controller->handleCreate(Item::class, $this->request('{"name":"a"}'))->getStatusCode());
	}
}
