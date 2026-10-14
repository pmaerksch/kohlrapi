<?php

namespace pmaerksch\Kohlrapi\Tests;

use pmaerksch\Kohlrapi\ApiSearchParams;
use pmaerksch\Kohlrapi\Tests\Fixtures\Item;
use pmaerksch\Kohlrapi\Tests\Fixtures\ItemRepository;
use PHPUnit\Framework\Attributes\DataProvider;

class ApiRepositoryTest extends KohlrapiTestCase
{
	private ItemRepository $repo;



	protected function setUp(): void
	{
		parent::setUp();

		foreach ( [['apple', 'alice', 1], ['banana', 'bob', 2], ['cherry', 'alice', 3], ['50% off', 'bob', 4], ['a_b', 'alice', 5]] as [$name, $owner, $qty] )
		{
			$this->createItem($name, $owner, $qty);
		}

		$repo = $this->em->getRepository(Item::class);
		$this->assertInstanceOf(ItemRepository::class, $repo);
		$this->repo = $repo;
	}



	/**
	 * @return string[] Names of the matching items, sorted by qty.
	 */
	private function names(array $filters, string $term = ''): array
	{
		$result = $this->repo->search($term, $filters, 0, 100, ['field' => 'qty', 'order' => 'ASC']);

		return array_map(fn(Item $i) => $i->getName(), $result['items']);
	}



	private static function filter(string $matchMode, mixed $value, string $operator = 'and'): array
	{
		return ['operator' => $operator, 'constraints' => [['value' => $value, 'matchMode' => $matchMode]]];
	}



	public static function matchModes(): array
	{
		return [
			'contains'    => [['name' => self::filter('contains', 'an')], ['banana']],
			'notContains' => [['name' => self::filter('notContains', 'a')], ['cherry', '50% off']],
			'startsWith'  => [['name' => self::filter('startsWith', 'ch')], ['cherry']],
			'endsWith'    => [['name' => self::filter('endsWith', 'e')], ['apple']],
			'equals'      => [['owner' => self::filter('equals', 'bob')], ['banana', '50% off']],
			'notEquals'   => [['owner' => self::filter('notEquals', 'bob')], ['apple', 'cherry', 'a_b']],
			'lt'          => [['qty' => self::filter('lt', 2)], ['apple']],
			'lte'         => [['qty' => self::filter('lte', 2)], ['apple', 'banana']],
			'gt'          => [['qty' => self::filter('gt', 4)], ['a_b']],
			'gte'         => [['qty' => self::filter('gte', 4)], ['50% off', 'a_b']],
			'in'          => [['name' => self::filter('in', ['apple', 'cherry'])], ['apple', 'cherry']],
			'default'     => [['name' => self::filter('unknownMode', 'err')], ['cherry']],
		];
	}



	#[DataProvider('matchModes')]
	public function testMatchModes(array $filters, array $expected): void
	{
		$this->assertSame($expected, $this->names($filters));
	}



	public function testLikeWildcardsMatchLiterally(): void
	{
		$this->assertSame(['50% off'], $this->names(['name' => self::filter('contains', '%')]));
		$this->assertSame(['a_b'], $this->names(['name' => self::filter('contains', '_')]));
	}



	public function testOrOperatorCombinesConstraints(): void
	{
		$filters = ['name' => ['operator' => 'or', 'constraints' => [
			['value' => 'apple', 'matchMode' => 'equals'],
			['value' => 'cherry', 'matchMode' => 'equals'],
		]]];

		$this->assertSame(['apple', 'cherry'], $this->names($filters));
	}



	public function testUnknownFieldsAndMalformedConstraintsAreIgnored(): void
	{
		$filters = [
			'password' => self::filter('equals', 'x'),
			'name'     => ['constraints' => ['nope', ['value' => ['array'], 'matchMode' => 'equals'], ['value' => '']]],
			'owner'    => 'not-an-array',
		];

		$this->assertCount(5, $this->names($filters));
	}



	public function testGlobalSearchTerm(): void
	{
		$this->assertSame(['banana'], $this->names([], 'nan'));
	}



	public function testPaginationAndTotalCount(): void
	{
		$result = $this->repo->search('', [], 1, 2, ['field' => 'qty', 'order' => 'ASC']);

		$this->assertSame(['banana', 'cherry'], array_map(fn(Item $i) => $i->getName(), $result['items']));
		$this->assertSame(5, $result['count']);
	}



	public function testSortFallsBackToDefaultForUnknownFields(): void
	{
		$result = $this->repo->search('', [], 0, 1, ['field' => 'password', 'order' => 'ASC']);

		// default sort: id DESC
		$this->assertSame('a_b', $result['items'][0]->getName());
	}



	public function testSortDescending(): void
	{
		$result = $this->repo->search('', [], 0, 1, ['field' => 'name', 'order' => 'desc']);

		$this->assertSame('cherry', $result['items'][0]->getName());
	}



	public function testSearchRandomReturnsDistinctMatchingItems(): void
	{
		$result = $this->repo->searchRandom('', ['owner' => self::filter('equals', 'alice')], 2);

		$this->assertCount(2, $result['items']);
		$names = array_map(fn(Item $i) => $i->getName(), $result['items']);
		$this->assertCount(2, array_unique($names));
		$this->assertEmpty(array_diff($names, ['apple', 'cherry', 'a_b']));
	}



	public function testSearchRandomWithLimitAboveMatches(): void
	{
		$this->assertCount(5, $this->repo->searchRandom('', [], 50)['items']);
		$this->assertSame([], $this->repo->searchRandom('', [], 0)['items']);
	}



	public function testHandleSearchReturnsPageAndFilteredTotal(): void
	{
		$body     = json_encode(['limit' => 1, 'filters' => ['owner' => self::filter('equals', 'alice')]]);
		$response = $this->controller()->handleSearch(Item::class, $this->request($body));
		$data     = $this->body($response);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame(1, $data['count']);
		$this->assertSame(3, $data['maxCount']);
		$this->assertArrayHasKey('uuid', $data['items'][0]);
	}



	public function testHandleSearchRejectsInvalidJson(): void
	{
		foreach ( ['{bad', 'null', '5'] as $body )
		{
			$this->assertSame(400, $this->controller()->handleSearch(Item::class, $this->request($body))->getStatusCode(), $body);
		}
	}



	public function testRandomEntityListViaController(): void
	{
		$result = $this->controller()->getRandomEntityList($this->repo, ApiSearchParams::fromInternal(limit: 3));

		$this->assertCount(3, $result['items']);
	}
}
