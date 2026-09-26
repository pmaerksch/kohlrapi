<?php

namespace pmaerksch\Kohlrapi;

use InvalidArgumentException;

/**
 * Utility class for handling JSON data, providing methods for formatting,
 * validating, and extracting required or optional keys.
 */
class JsonHelper
{

	private array $data;



	/**
	 * @throws InvalidArgumentException
	 */
	public function __construct(mixed $json = null)
	{
		$this->data = [];

		if ( $json !== null )
		{
			$this->format($json);
		}
	}



	/**
	 * Parses the provided JSON input and assigns the resulting array to the internal data property.
	 * Throws an exception if the input cannot be decoded into an array.
	 * @param mixed $json The JSON string or an array to format. If a string is provided, it will be decoded into an array.
	 * @return static The current instance with the internal data property updated.
	 * @throws InvalidArgumentException If the input is not a valid JSON string or cannot be converted into an array.
	 */
	public function format(mixed $json): static
	{
		$data = is_string($json) ? json_decode($json, true) : $json;

		if ( !is_array($data) )
		{
			throw new InvalidArgumentException('Invalid JSON Format', 1);
		}

		$this->data = $data;
		return $this;
	}



	/**
	 * Retrieves the value associated with the specified key from the data array.
	 * If the key does not exist, an exception is thrown. If the key exists but its value
	 * is empty and empty values are not allowed, an exception is also thrown.
	 * @param string $key The key to retrieve from the data array.
	 * @param bool $allowEmpty Whether to allow empty values for the specified key. Defaults to false.
	 * @return mixed The value associated with the specified key.
	 * @throws ApiMissingFieldException If the key does not exist or if its value is empty and empty values are not allowed.
	 *                                  Being an ApiMissingFieldException, it can be turned into a 422 via
	 *                                  {@see ApiController::missingFieldResponse()}.
	 */
	public function require(string $key, bool $allowEmpty = false): mixed
	{
		if ( !array_key_exists($key, $this->data) )
		{
			throw new ApiMissingFieldException($key);
		}

		$value = $this->data[ $key ];

		// Note: a strict check, not empty() — empty("0") is true, but "0" is a valid value.
		if ( !$allowEmpty && ($value === null || $value === '' || $value === []) )
		{
			throw new ApiMissingFieldException($key);
		}

		return $value;
	}



	/**
	 * Retrieves the value associated with the specified key from the data array if it exists,
	 * otherwise returns the provided default value.
	 * @param string $key The key to look for in the data array.
	 * @param mixed $default The default value to return if the key does not exist in the data array.
	 * @return mixed The value associated with the key, or the default value if the key is not found.
	 */
	public function optional(string $key, mixed $default = null): mixed
	{
		return array_key_exists($key, $this->data) ? $this->data[ $key ] : $default;
	}
}
