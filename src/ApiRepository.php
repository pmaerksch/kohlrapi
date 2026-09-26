<?php

/**
 * ApiRepository — Abstract base repository providing generic search, filter, and sort logic
 * compatible with PrimeVue / Vuetify DataTable payloads.
 *
 * @author  Philip Märksch
 *
 * Subclasses must implement:
 *   - getAlias()          — DQL alias for the root entity (e.g. 'v', 'u')
 *   - getEntityFieldMap() — maps frontend filter/sort keys to DQL expressions
 *   - applyGlobalSearch() — full-text WHERE clause for the 'term' parameter
 *
 * Subclasses may override:
 *   - applyBaseConditions() — mandatory query constraints (e.g. visibility, soft-delete)
 *                             called at the start of every search/searchRandom query;
 *                             use Security injection in the subclass to bypass for admins
 *   - applyCustomSort()     — handle aggregate or JOIN-based sort cases
 *   - getDefaultSort()      — change the fallback sort field and order
 *   - getMaxLimit()         — enforce a per-entity result cap
 *
 * Expected filter payload (PrimeVue format):
 * {
 *   "fieldKey": {
 *     "operator": "and" | "or",
 *     "constraints": [{ "value": "...", "matchMode": "contains" }]
 *   }
 * }
 *
 * Supported matchModes: contains, notContains, startsWith, endsWith,
 *                       equals, notEquals, lt, lte, gt, gte, in
 *
 * Expected sort payload:
 * { "field": "fieldKey", "order": "ASC" | "DESC" }
 */

namespace pmaerksch\Kohlrapi;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
abstract class ApiRepository extends ServiceEntityRepository
{
	/**
	 * DQL alias for the root entity (e.g. 'c' for Customer, 's' for Station).
	 */
	abstract protected function getAlias(): string;

	/**
	 * Maps frontend field keys (filter keys and sort fields) to DQL column expressions.
	 * Example: ['name' => 'c.name', 'address.country' => 'c.country']
	 * Do not include 'id' or 'uuid' — those are added automatically.
	 */
	abstract protected function getEntityFieldMap(): array;

	/**
	 * Returns the full field map, including the base fields (id, uuid) from ApiEntity.
	 */
	final protected function getFieldMap(): array
	{
		$alias = $this->getAlias();

		return array_merge([
			'id'   => "{$alias}.id",
			'uuid' => "{$alias}.uuid",
		], $this->getEntityFieldMap());
	}

	/**
	 * Applies a global keyword search (the "term" from the search payload).
	 * Typically a WHERE clause across multiple text columns.
	 */
	abstract protected function applyGlobalSearch(QueryBuilder $qb, string $term): void;

	/**
	 * Default sort applied when no valid sort is provided.
	 * Override to change the per-entity default.
	 */
	protected function getDefaultSort(): array
	{
		return ['field' => 'id', 'order' => 'DESC'];
	}

	/**
	 * Handle special sort cases (e.g. aggregate sorts requiring a JOIN).
	 * Return true if the sort was handled, false to fall through to the generic logic.
	 */
	protected function applyCustomSort(QueryBuilder $qb, array $sort): bool
	{
		return false;
	}

	/**
	 * Applies mandatory base conditions to every query (e.g. visibility, soft-delete).
	 * Override in subclasses to enforce per-entity constraints.
	 */
	protected function applyBaseConditions(QueryBuilder $qb): void {}

	/**
	 * Maximum number of results this repository will return per search request.
	 * Return null to use the caller-supplied limit without an additional cap.
	 * Override in subclasses to enforce a per-entity ceiling.
	 */
	protected function getMaxLimit(): ?int
	{
		return null;
	}



	/**
	 * Executes a search query with the specified parameters to retrieve a set of paginated results and their total count.
	 * @param string $term The search term to apply to the query.
	 * @param array $filters An array of filters to apply to the query.
	 * @param int $offset The starting position of the results to retrieve.
	 * @param int $limit The maximum number of results to retrieve.
	 * @param ?array $sort An optional array defining the sorting criteria.
	 * @return array Returns an associative array containing the paginated results under the 'items' key and the total count under the 'count' key.
	 */
	public function search(string $term, array $filters, int $offset, int $limit, ?array $sort): array
	{
		$maxLimit = $this->getMaxLimit();
		if ( $maxLimit !== null )
		{
			$limit = min($limit, $maxLimit);
		}

		$qb = $this->createQueryBuilder($this->getAlias());

		$this->applyBaseConditions($qb);
		$this->applyGlobalSearch($qb, $term);
		$this->applyFilters($qb, $filters);
		$this->applySort($qb, $sort);

		// Paginated items
		$itemsQb = clone $qb;
		$items   = $itemsQb
			->setFirstResult($offset)
			->setMaxResults($limit)
			->getQuery()
			->getResult();

		// Count (strip ORDER BY and GROUP BY to avoid issues)
		$alias   = $this->getAlias();
		$countQb = clone $qb;
		$count   = (int)$countQb
			->resetDQLPart('orderBy')
			->resetDQLPart('groupBy')
			->select("COUNT(DISTINCT {$alias}.id)")
			->getQuery()
			->getSingleScalarResult();

		return ['items' => $items, 'count' => $count];
	}



	/**
	 * Returns a random subset of results matching the given term and filters.
	 *
	 * DQL has no portable RAND(), so the matching ids are fetched (cheap: a single
	 * integer column), sampled in PHP, and the chosen entities loaded with one IN query.
	 * Works on every database Doctrine supports, without a custom DQL function.
	 *
	 * @param string $term Global search term.
	 * @param array $filters Filter payload (same format as search()).
	 * @param int $limit Maximum number of results to return.
	 * @param bool $distinct Deprecated, no effect: results are always distinct entities.
	 * @return array { items, count }
	 */
	public function searchRandom(string $term, array $filters, int $limit, bool $distinct = false): array
	{
		$maxLimit = $this->getMaxLimit();
		if ( $maxLimit !== null )
		{
			$limit = min($limit, $maxLimit);
		}

		if ( $limit <= 0 )
		{
			return ['items' => [], 'count' => 0];
		}

		$alias = $this->getAlias();
		$qb    = $this->createQueryBuilder($alias);

		$this->applyBaseConditions($qb);
		$this->applyGlobalSearch($qb, $term);
		$this->applyFilters($qb, $filters);

		$ids = $qb
			->select("DISTINCT {$alias}.id")
			->resetDQLPart('orderBy')
			->getQuery()
			->getSingleColumnResult();

		if ( count($ids) === 0 )
		{
			return ['items' => [], 'count' => 0];
		}

		shuffle($ids);
		$ids = array_slice($ids, 0, $limit);

		$items = $this->createQueryBuilder($alias)
			->where("{$alias}.id IN (:ids)")
			->setParameter('ids', $ids)
			->getQuery()
			->getResult();

		// The IN query returns rows in database order — restore the random order.
		shuffle($items);

		return ['items' => $items, 'count' => count($items)];
	}



	public function totalCount(): int
	{
		$alias = $this->getAlias();

		return (int)$this->createQueryBuilder($alias)
			->select("COUNT({$alias}.id)")
			->getQuery()
			->getSingleScalarResult();
	}



	/**
	 * Applies sorting to the provided QueryBuilder instance based on the passed sort criteria.
	 * The method determines the appropriate sorting column and order based on the provided
	 * sort array. If no valid sort criteria is given, a default sort is applied. Custom sorting
	 * logic may be implemented by subclasses as needed.
	 * - The mapping between fields and database columns is resolved using the `getFieldMap()` method.
	 * - The alias for the root entity is fetched via the `getAlias()` method.
	 * - A default sort field and order is retrieved using the `getDefaultSort()` method.
	 * - Custom sorting cases are handled by overriding `applyCustomSort()` in subclasses.
	 * @param QueryBuilder $qb The query builder instance to which sorting will be applied.
	 * @param array|null $sort An optional array containing sorting criteria.
	 *                           Expected keys are 'field' (string) and 'order' (string, 'ASC' or 'DESC').
	 * @return void
	 */
	protected function applySort(QueryBuilder $qb, ?array $sort): void
	{
		$map     = $this->getFieldMap();
		$alias   = $this->getAlias();
		$default = $this->getDefaultSort();

		$defaultColumn = $map[$default['field']] ?? "{$alias}.id";

		if ( !$sort || empty($sort['field']) || !is_string($sort['field']) )
		{
			$qb->orderBy($defaultColumn, $default['order']);
			return;
		}

		// Let the subclass handle special cases (e.g. aggregate sorts)
		if ( $this->applyCustomSort($qb, $sort) )
		{
			return;
		}

		if ( !isset($map[$sort['field']]) )
		{
			$qb->orderBy($defaultColumn, $default['order']);
			return;
		}

		$order = is_string($sort['order'] ?? null) && strtoupper($sort['order']) === 'DESC' ? 'DESC' : 'ASC';
		$qb->orderBy($map[$sort['field']], $order);
	}



	/**
	 * Applies a set of filters to a QueryBuilder instance.
	 * @param QueryBuilder $qb Instance of QueryBuilder to modify with the specified filters.
	 * @param array $filters Associative array of filter configurations.
	 *                              Each filter should have a key that maps to a database column
	 *                              and a configuration array with the following optional keys:
	 *                              - 'constraints' (array): A list of filtering constraints, where
	 *                                each constraint may contain:
	 *                                - 'value' (mixed): The value to filter by.
	 *                                - 'matchMode' (string): The type of filtering to apply
	 *                                  ('contains', 'startsWith', 'endsWith', 'notContains', 'equals',
	 *                                  'notEquals', 'lt', 'lte', 'gt', 'gte', 'in').
	 *                              - 'operator' (string): Logical operator to combine constraints
	 *                                ('and', 'or'). Defaults to 'and'.
	 * @return void
	 */
	protected function applyFilters(QueryBuilder $qb, array $filters): void
	{
		$map        = $this->getFieldMap();
		$paramIndex = 0;

		foreach ( $filters as $key => $filterConfig )
		{
			if ( !isset($map[$key]) || !is_array($filterConfig) )
			{
				continue;
			}

			$column      = $map[$key];
			$constraints = $filterConfig['constraints'] ?? [];
			$operator    = $filterConfig['operator'] ?? 'and';
			$useOr       = is_string($operator) && strtolower($operator) === 'or';
			$expressions = [];

			if ( !is_array($constraints) )
			{
				continue;
			}

			foreach ( $constraints as $constraint )
			{
				if ( !is_array($constraint) )
				{
					continue;
				}

				$value     = $constraint['value'] ?? null;
				$matchMode = $constraint['matchMode'] ?? 'contains';

				if ( $value === null || $value === '' )
				{
					continue;
				}

				// Only 'in' takes a list; every other mode compares against a single scalar.
				$isValid = $matchMode === 'in'
					? is_array($value) && count($value) > 0 && count(array_filter($value, 'is_scalar')) === count($value)
					: is_scalar($value);

				if ( !$isValid )
				{
					continue;
				}

				$param = 'fp_' . $paramIndex++;

				switch ( $matchMode )
				{
					case 'startsWith':
						$expressions[] = $this->likeExpression($column, $param);
						$qb->setParameter($param, $this->escapeLike($value) . '%');
						break;

					case 'endsWith':
						$expressions[] = $this->likeExpression($column, $param);
						$qb->setParameter($param, '%' . $this->escapeLike($value));
						break;

					case 'notContains':
						$expressions[] = $this->likeExpression($column, $param, true);
						$qb->setParameter($param, '%' . $this->escapeLike($value) . '%');
						break;

					case 'equals':
						$expressions[] = $qb->expr()->eq($column, ":$param");
						$qb->setParameter($param, $value);
						break;

					case 'notEquals':
						$expressions[] = $qb->expr()->neq($column, ":$param");
						$qb->setParameter($param, $value);
						break;

					case 'lt':
						$expressions[] = $qb->expr()->lt($column, ":$param");
						$qb->setParameter($param, $value);
						break;

					case 'lte':
						$expressions[] = $qb->expr()->lte($column, ":$param");
						$qb->setParameter($param, $value);
						break;

					case 'gt':
						$expressions[] = $qb->expr()->gt($column, ":$param");
						$qb->setParameter($param, $value);
						break;

					case 'gte':
						$expressions[] = $qb->expr()->gte($column, ":$param");
						$qb->setParameter($param, $value);
						break;

					case 'in':
						$expressions[] = $qb->expr()->in($column, ":$param");
						$qb->setParameter($param, array_values($value));
						break;

					case 'contains':
					default:
						$expressions[] = $this->likeExpression($column, $param);
						$qb->setParameter($param, '%' . $this->escapeLike($value) . '%');
						break;
				}
			}

			if ( empty($expressions) )
			{
				continue;
			}

			if ( count($expressions) === 1 )
			{
				$qb->andWhere($expressions[0]);
			}
			elseif ( $useOr )
			{
				$qb->andWhere($qb->expr()->orX(...$expressions));
			}
			else
			{
				$qb->andWhere($qb->expr()->andX(...$expressions));
			}
		}
	}



	/**
	 * Escape character used in LIKE patterns. Not a backslash, because backslashes
	 * inside SQL string literals are treated differently across databases (MySQL vs. others).
	 */
	private const LIKE_ESCAPE = '!';



	/**
	 * Builds a `column [NOT] LIKE :param ESCAPE '!'` DQL expression.
	 */
	private function likeExpression(string $column, string $param, bool $negate = false): string
	{
		$not = $negate ? 'NOT ' : '';

		return "{$column} {$not}LIKE :{$param} ESCAPE '" . self::LIKE_ESCAPE . "'";
	}



	/**
	 * Escapes LIKE wildcards in user input, so a search for "50%" or "a_b" matches
	 * those characters literally instead of treating them as wildcards.
	 */
	private function escapeLike(string|int|float|bool $value): string
	{
		$e = self::LIKE_ESCAPE;

		return str_replace([$e, '%', '_'], [$e . $e, $e . '%', $e . '_'], (string)$value);
	}
}
