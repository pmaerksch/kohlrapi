<?php

namespace pmaerksch\Kohlrapi;

use JsonException;
use Symfony\Component\HttpFoundation\Request;

readonly class ApiSearchParams
{
	public function __construct(
		public int     $offset  = 0,
		public int     $limit   = 25,
		public string  $term    = '',
		public array   $filters = [],
		public ?array  $sort    = null,
	) {}



	/**
	 * @throws JsonException
	 */
	public static function fromRequest(Request $request): self
	{
		$data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

		return self::fromArray($data);
	}



	public static function fromArray(array $data): self
	{
		$limit = (int)($data['limit'] ?? 25);

		return new self(
			offset:  max(0, (int)($data['offset'] ?? 0)),
			limit:   ($limit > 0 && $limit <= 500) ? $limit : 25,
			term:    (string)($data['term'] ?? ''),
			filters: $data['filters'] ?? [],
			sort:    $data['sort'] ?? null,
		);
	}



	public static function fromInternal(
		int    $limit   = PHP_INT_MAX,
		array  $filters = [],
		?array $sort    = null,
		int    $offset  = 0,
		string $term    = '',
	): self
	{
		return new self(
			offset:  $offset,
			limit:   $limit,
			term:    $term,
			filters: $filters,
			sort:    $sort,
		);
	}
}
