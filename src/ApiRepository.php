<?php

/**
 * ApiRepository — Abstract base repository providing generic search, filter, and sort logic.
 * Filters use the format PrimeVue DataTables send.
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
 *   - getMaxOffset(), getMaxTermLength(), getMaxConstraints(), getMaxInValues()
 *                           — request size limits (exceeding one throws ApiInvalidSearchException)
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
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * @template T of object
 * @extends ServiceEntityRepository<T>
 */
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
	 * @return array<string, string>
	 */
	abstract protected function getEntityFieldMap(): array;

	/**
	 * Returns the full field map, including the base fields (id, uuid) from ApiEntity.
	 * @return array<string, string>
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
	 * @return array{field: string, order: string}
	 */
	protected function getDefaultSort(): array
	{
		return ['field' => 'id', 'order' => 'DESC'];
	}

	/**
	 * Handle special sort cases (e.g. aggregate sorts requiring a JOIN).
	 * Return true if the sort was handled, false to fall through to the generic logic.
	 * @param array<string, mixed> $sort
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
	 * Largest pagination offset accepted; null for no limit. Deep offsets make the
	 * database scan and discard every row before them.
	 */
	protected function getMaxOffset(): ?int
	{
		return 100_000;
	}

	/**
	 * Longest global search term accepted, in characters; null for no limit.
	 */
	protected function getMaxTermLength(): ?int
	{
		return 255;
	}

	/**
	 * Most filter constraints accepted per request, across all fields; null for no limit.
	 */
	protected function getMaxConstraints(): ?int
	{
		return 50;
	}

	/**
	 * Most values accepted in one 'in' constraint; null for no limit. Each value is
	 * bound as its own query parameter, and databases cap their number.
	 */
	protected function getMaxInValues(): ?int
	{
		return 1000;
	}



	/**
	 * Executes a search query with the specified parameters to retrieve a set of paginated results and their total count.
	 * @param string $term The search term to apply to the query.
	 * @param array<mixed> $filters An array of filters to apply to the query.
	 * @param int $offset The starting position of the results to retrieve.
	 * @param int $limit The maximum number of results to retrieve.
	 * @param array<string, mixed>|null $sort An optional array defining the sorting criteria.
	 * @throws ApiInvalidSearchException If the request exceeds one of the repository's limits, or a filter value doesn't fit its field.
	 * @return array{items: T[], count: int} Returns an associative array containing the paginated results under the 'items' key and the total count under the 'count' key.
	 */
	public function search(string $term, array $filters, int $offset, int $limit, ?array $sort): array
	{
		$maxOffset = $this->getMaxOffset();
		if ( $maxOffset !== null && $offset > $maxOffset )
		{
			throw new ApiInvalidSearchException("Offset exceeds the maximum of $maxOffset");
		}

		$this->checkTerm($term);

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

		$query = $qb->getQuery()
			->setFirstResult($offset)
			->setMaxResults($limit);

		// Doctrine's Paginator limits entities, not SQL rows: with a joined collection a
		// plain LIMIT would cut pages short and hydrate partial collections. Its count
		// also copes with GROUP BY / HAVING. The extra id query that fetch-joins need is
		// only paid when the query actually joins something.
		$paginator = new Paginator($query, fetchJoinCollection: count($qb->getDQLPart('join')) > 0);

		$items = $this->rejectInvalidInput(fn() => iterator_to_array($paginator, false));
		$count = $this->rejectInvalidInput(fn() => count($paginator));

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
	 * @param array<mixed> $filters Filter payload (same format as search()).
	 * @param int $limit Maximum number of results to return.
	 * @return array{items: T[], count: int}
	 * @throws ApiInvalidSearchException If the request exceeds one of the repository's limits, or a filter value doesn't fit its field.
	 */
	public function searchRandom(string $term, array $filters, int $limit): array
	{
		$this->checkTerm($term);

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

		$ids = $this->rejectInvalidInput(fn() => $qb
			->select("DISTINCT {$alias}.id")
			->resetDQLPart('orderBy')
			->getQuery()
			->getSingleColumnResult());

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
	 * @param array<string, mixed>|null $sort An optional array containing sorting criteria.
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
	 * @param array<mixed> $filters Associative array of filter configurations.
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
	 * @throws ApiInvalidSearchException If the filters exceed getMaxConstraints() or getMaxInValues().
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

				// switch compares loosely, so a non-string matchMode (e.g. true) would hit the
				// first case; only strings select a mode, anything else means 'contains'.
				$value     = $constraint['value'] ?? null;
				$matchMode = is_string($constraint['matchMode'] ?? null) ? $constraint['matchMode'] : 'contains';

				if ( $value === null || $value === '' )
				{
					continue;
				}

				$param = 'fp_' . $paramIndex;

				// Only 'in' takes a list; every other mode compares against a single scalar.
				if ( $matchMode === 'in' )
				{
					if ( !is_array($value) || count($value) === 0 || count(array_filter($value, 'is_scalar')) !== count($value) )
					{
						continue;
					}

					$maxIn = $this->getMaxInValues();
					if ( $maxIn !== null && count($value) > $maxIn )
					{
						throw new ApiInvalidSearchException("An 'in' filter accepts at most $maxIn values");
					}

					$this->checkConstraintCount($paramIndex + 1);

					$paramIndex++;
					$expressions[] = $qb->expr()->in($column, ":$param");
					$qb->setParameter($param, array_values($value));
					continue;
				}

				if ( !is_scalar($value) )
				{
					continue;
				}

				$this->checkConstraintCount($paramIndex + 1);
				$paramIndex++;

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
	 * Runs a search query, turning database errors caused by the client's input into
	 * ApiInvalidSearchException: a filter value that doesn't fit its column (SQLSTATE
	 * class 22, e.g. PostgreSQL's 22P02 for "abc" on an integer column) or a match mode
	 * the column type has no operator for (42883, e.g. LIKE on an integer). MySQL and
	 * SQLite coerce such values instead of failing, so this only ever fires on databases
	 * that check them. Every other database error is rethrown unchanged.
	 *
	 * @template R
	 * @param callable(): R $query
	 * @return R
	 * @throws ApiInvalidSearchException
	 */
	private function rejectInvalidInput(callable $query): mixed
	{
		try
		{
			return $query();
		}
		catch ( DriverException $e )
		{
			$state = (string)$e->getSQLState();

			if ( str_starts_with($state, '22') || $state === '42883' )
			{
				throw new ApiInvalidSearchException('A filter value does not match the type of its field', 0, $e);
			}

			throw $e;
		}
	}



	/**
	 * @throws ApiInvalidSearchException If the term exceeds getMaxTermLength().
	 */
	private function checkTerm(string $term): void
	{
		$maxLength = $this->getMaxTermLength();
		if ( $maxLength !== null && mb_strlen($term) > $maxLength )
		{
			throw new ApiInvalidSearchException("The search term exceeds the maximum length of $maxLength characters");
		}
	}



	/**
	 * @throws ApiInvalidSearchException If $count exceeds getMaxConstraints().
	 */
	private function checkConstraintCount(int $count): void
	{
		$max = $this->getMaxConstraints();
		if ( $max !== null && $count > $max )
		{
			throw new ApiInvalidSearchException("A search accepts at most $max filter constraints");
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
