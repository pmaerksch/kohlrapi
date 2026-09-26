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
	 * @throws JsonException If the body is not valid JSON, or valid JSON that isn't an object (e.g. `null`, `5`).
	 */
	public static function fromRequest(Request $request): self
	{
		$data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

		if ( !is_array($data) )
		{
			throw new JsonException('Expected a JSON object as search payload');
		}

		return self::fromArray($data);
	}



	public static function fromArray(array $data): self
	{
		// Client input: coerce every field to its declared type rather than letting a
		// malformed payload (e.g. "filters": "abc") surface later as a TypeError / 500.
		$limit   = is_numeric($data['limit'] ?? null) ? (int)$data['limit'] : 25;
		$offset  = is_numeric($data['offset'] ?? null) ? (int)$data['offset'] : 0;
		$term    = $data['term'] ?? '';
		$filters = $data['filters'] ?? [];
		$sort    = $data['sort'] ?? null;

		return new self(
			offset:  max(0, $offset),
			limit:   ($limit > 0 && $limit <= 500) ? $limit : 25,
			term:    is_scalar($term) ? (string)$term : '',
			filters: is_array($filters) ? $filters : [],
			sort:    is_array($sort) ? $sort : null,
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
