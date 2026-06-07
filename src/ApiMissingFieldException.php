<?php

namespace pmaerksch\Kohlrapi;

use RuntimeException;

class ApiMissingFieldException extends RuntimeException
{
	public function __construct( private readonly string $field )
	{
		parent::__construct("Missing or invalid field: $field");
	}



	public function getField(): string
	{
		return $this->field;
	}
}
