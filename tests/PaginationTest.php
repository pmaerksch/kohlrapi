<?php

namespace pmaerksch\Kohlrapi\Tests;

use Doctrine\ORM\QueryBuilder;
use pmaerksch\Kohlrapi\Tests\Fixtures\Item;
use pmaerksch\Kohlrapi\Tests\Fixtures\ItemRepository;

/**
 * search() must paginate entities, not SQL rows, and count correctly when
 * subclasses join, fetch-join, group or filter on aggregates.
 */
class PaginationTest extends KohlrapiTestCase
{
	private ItemRepository $repo;



	protected function setUp(): void
	{
		parent::setUp();

		// apple: 1 tag, banana: 2, cherry: 3, date: 0
		foreach ( ['apple' => 1, 'banana' => 2, 'cherry' => 3, 'date' => 0] as $name => $tags )
		{
			$item = new Item();
			$item->setName($name);

			for ( $i = 0; $i < $tags; $i++ )
			{
				$item->addTag("$name-$i");
			}

			$this->em->persist($item);
		}

		$this->em->flush();
		$this->em->clear();

		$repo = $this->em->getRepository(Item::class);
		$this->assertInstanceOf(ItemRepository::class, $repo);
		$this->repo = $repo;
	}



	/**
	 * @return string[]
	 */
	private function names(array $items): array
	{
		return array_map(fn(Item $i) => $i->getName(), $items);
	}



	public function testFetchJoinedCollectionPaginatesEntities(): void
	{
		$this->repo->baseConditions = fn(QueryBuilder $qb) => $qb->leftJoin('i.tags', 't')->addSelect('t');

		$page = $this->repo->search('', [], 0, 2, ['field' => 'name', 'order' => 'DESC']);

		$this->assertSame(['date', 'cherry'], $this->names($page['items']));
		$this->assertCount(3, $page['items'][1]->getTags());
		$this->assertSame(4, $page['count']);
	}



	public function testJoinedFilterCountsEntitiesOnce(): void
	{
		$this->repo->baseConditions = fn(QueryBuilder $qb) => $qb->innerJoin('i.tags', 't');

		$page = $this->repo->search('', [], 0, 10, ['field' => 'name', 'order' => 'ASC']);

		$this->assertSame(['apple', 'banana', 'cherry'], $this->names($page['items']));
		$this->assertSame(3, $page['count']);
	}



	public function testHavingOnAggregateIsCounted(): void
	{
		$this->repo->baseConditions = fn(QueryBuilder $qb) => $qb->leftJoin('i.tags', 't')->groupBy('i.id')->having('COUNT(t.id) >= 2');

		$page = $this->repo->search('', [], 0, 1, ['field' => 'name', 'order' => 'ASC']);

		$this->assertSame(['banana'], $this->names($page['items']));
		$this->assertSame(2, $page['count']);
	}



	public function testCustomSortOnAggregate(): void
	{
		$this->repo->customSort = function (QueryBuilder $qb, array $sort): bool
		{
			if ( $sort['field'] !== 'tagCount' )
			{
				return false;
			}

			$qb->leftJoin('i.tags', 'tc')->addSelect('COUNT(tc.id) AS HIDDEN tagCount')->groupBy('i.id')->orderBy('tagCount', 'DESC');

			return true;
		};

		$page = $this->repo->search('', [], 1, 2, ['field' => 'tagCount', 'order' => 'DESC']);

		$this->assertSame(['banana', 'apple'], $this->names($page['items']));
		$this->assertSame(4, $page['count']);
	}
}
