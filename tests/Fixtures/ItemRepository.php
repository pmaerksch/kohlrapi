<?php

namespace pmaerksch\Kohlrapi\Tests\Fixtures;

use Doctrine\ORM\QueryBuilder;
use pmaerksch\Kohlrapi\ApiRepository;

class ItemRepository extends ApiRepository
{
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
