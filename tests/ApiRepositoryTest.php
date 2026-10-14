<?php

namespace pmaerksch\Kohlrapi\Tests;

use Doctrine\DBAL\Exception\DriverException;
use pmaerksch\Kohlrapi\ApiInvalidSearchException;
use pmaerksch\Kohlrapi\ApiSearchParams;
use pmaerksch\Kohlrapi\Tests\Fixtures\Item;
use pmaerksch\Kohlrapi\Tests\Fixtures\ItemRepository;
use pmaerksch\Kohlrapi\Tests\Fixtures\SqlStateMiddleware;
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



	private static function filter(mixed $matchMode, mixed $value, string $operator = 'and'): array
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
			'bool mode'   => [['name' => self::filter(true, 'err')], ['cherry']],
			'int mode'    => [['name' => self::filter(0, 'err')], ['cherry']],
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



	public function testSearchRandomSamplesEveryMatchEventually(): void
	{
		for ( $i = 0; $i < 45; $i++ )
		{
			$this->createItem("extra$i", 'carol');
		}

		$seen = [];

		// P(an item is never picked in 200 draws of 5 out of 50) = 0.9^200 ≈ 7e-10
		for ( $run = 0; $run < 200; $run++ )
		{
			foreach ( $this->repo->searchRandom('', [], 5)['items'] as $item )
			{
				$seen[ $item->getName() ] = true;
			}
		}

		$this->assertCount(50, $seen);
	}



	public function testSearchRandomWithGroupedBaseConditions(): void
	{
		$this->repo->baseConditions = fn($qb) => $qb->groupBy('i.id')->having('MAX(i.qty) >= 4');

		$names = array_map(fn(Item $i) => $i->getName(), $this->repo->searchRandom('', [], 10)['items']);
		sort($names);

		$this->assertSame(['50% off', 'a_b'], $names);
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



	public static function oversizedRequests(): array
	{
		$constraints = array_fill(0, 51, ['value' => 'a', 'matchMode' => 'contains']);

		return [
			'offset'      => [['offset' => 100_001]],
			'term'        => [['term' => str_repeat('ä', 256)]],
			'constraints' => [['filters' => ['name' => ['constraints' => $constraints]]]],
			'spread'      => [['filters' => ['name' => ['constraints' => array_slice($constraints, 0, 30)], 'owner' => ['constraints' => array_slice($constraints, 0, 21)]]]],
			'in values'   => [['filters' => ['name' => self::filter('in', array_map('strval', range(1, 1001)))]]],
		];
	}



	#[DataProvider('oversizedRequests')]
	public function testOversizedSearchIs400(array $body): void
	{
		$response = $this->controller()->handleSearch(Item::class, $this->request(json_encode($body)));

		$this->assertSame(400, $response->getStatusCode());
		$this->assertSame('api.errors.invalidSearch', $this->body($response)['key']);
	}



	public function testLimitsAcceptRequestsAtTheBoundary(): void
	{
		$constraints = array_fill(0, 50, ['value' => 'a', 'matchMode' => 'contains']);
		$body        = ['offset' => 100_000, 'term' => str_repeat('ä', 255), 'filters' => ['name' => ['operator' => 'or', 'constraints' => $constraints]]];

		$this->assertSame(200, $this->controller()->handleSearch(Item::class, $this->request(json_encode($body)))->getStatusCode());
		$this->assertSame([], $this->repo->search('', ['name' => self::filter('in', array_map('strval', range(1, 1000)))], 0, 10, null)['items']);
	}



	public function testSearchRandomChecksTerm(): void
	{
		$this->expectException(ApiInvalidSearchException::class);
		$this->repo->searchRandom(str_repeat('x', 256), [], 1);
	}



	public function testLimitsCanBeDisabled(): void
	{
		$repo = new class($this->registry, Item::class) extends ItemRepository
		{
			protected function getMaxOffset(): ?int
			{
				return null;
			}

			protected function getMaxTermLength(): ?int
			{
				return null;
			}
		};

		$this->assertSame([], $repo->search(str_repeat('x', 300), [], 200_000, 10, null)['items']);
	}



	public static function invalidInputStates(): array
	{
		return [
			'invalid text representation' => ['22P02'],
			'invalid datetime format'     => ['22007'],
			'operator does not exist'     => ['42883'],
		];
	}



	#[DataProvider('invalidInputStates')]
	public function testFilterValueRejectedByDatabaseIs400(string $sqlState): void
	{
		$body     = json_encode(['filters' => ['qty' => self::filter('equals', SqlStateMiddleware::marker($sqlState))]]);
		$response = $this->controller()->handleSearch(Item::class, $this->request($body));

		$this->assertSame(400, $response->getStatusCode());
		$this->assertSame('api.errors.invalidSearch', $this->body($response)['key']);
	}



	public function testSearchRandomRejectsInvalidInput(): void
	{
		$this->expectException(ApiInvalidSearchException::class);
		$this->repo->searchRandom('', ['qty' => self::filter('equals', SqlStateMiddleware::marker('22P02'))], 2);
	}



	public function testOtherDatabaseErrorsAreRethrown(): void
	{
		$this->expectException(DriverException::class);
		$this->repo->search('', ['qty' => self::filter('equals', SqlStateMiddleware::marker('08006'))], 0, 10, null);
	}



	public function testRandomEntityListViaController(): void
	{
		$result = $this->controller()->getRandomEntityList($this->repo, ApiSearchParams::fromInternal(limit: 3));

		$this->assertCount(3, $result['items']);
	}
}
