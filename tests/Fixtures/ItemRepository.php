<?php

namespace pmaerksch\Kohlrapi\Tests\Fixtures;

use Closure;
use Doctrine\ORM\QueryBuilder;
use pmaerksch\Kohlrapi\ApiRepository;

class ItemRepository extends ApiRepository
{
	/** Optional extra base conditions / joins per test: fn(QueryBuilder): void */
	public ?Closure $baseConditions = null;

	/** Optional custom sort per test: fn(QueryBuilder, array): bool */
	public ?Closure $customSort = null;



	protected function applyBaseConditions(QueryBuilder $qb): void
	{
		if ( $this->baseConditions !== null )
		{
			($this->baseConditions)($qb);
		}
	}



	protected function applyCustomSort(QueryBuilder $qb, array $sort): bool
	{
		return $this->customSort !== null && ($this->customSort)($qb, $sort);
	}



	protected function getAlias(): string
	{
		return 'i';
	}



	protected function getEntityFieldMap(): array
	{
		return [
			'name'  => 'i.name',
			'owner' => 'i.owner',
			'qty'   => 'i.qty',
		];
	}



	protected function applyGlobalSearch(QueryBuilder $qb, string $term): void
	{
		if ( $term === '' )
		{
			return;
		}

		$qb->andWhere('i.name LIKE :term')->setParameter('term', '%' . $term . '%');
	}
}
