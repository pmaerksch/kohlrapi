<?php

namespace pmaerksch\Kohlrapi\Tests\Fixtures;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Exception;

/**
 * Simulates database errors SQLite never raises: a statement fails with SQLSTATE
 * XXXXX when one of its bound values is the string "__sqlstate:XXXXX__" — e.g.
 * PostgreSQL's 22P02 for a malformed uuid or a non-numeric value on an int column.
 * The failure goes through DBAL's normal exception conversion.
 */
class SqlStateMiddleware implements Middleware
{
	public static function marker(string $sqlState): string
	{
		return "__sqlstate:{$sqlState}__";
	}



	public function wrap(Driver $driver): Driver
	{
		return new class($driver) extends AbstractDriverMiddleware
		{
			public function connect(array $params): Connection
			{
				return new class(parent::connect($params)) extends AbstractConnectionMiddleware
				{
					public function prepare(string $sql): Statement
					{
						return new class(parent::prepare($sql)) extends AbstractStatementMiddleware
						{
							private ?string $sqlState = null;

							public function bindValue(int|string $param, mixed $value, ParameterType $type): void
							{
								if ( is_string($value) && preg_match('/^__sqlstate:(\w{5})__$/', $value, $m) )
								{
									$this->sqlState = $m[1];
								}

								parent::bindValue($param, $value, $type);
							}

							public function execute(): Result
							{
								if ( $this->sqlState !== null )
								{
									throw new class($this->sqlState) extends Exception implements DriverException
									{
										public function __construct(private string $state)
										{
											parent::__construct("simulated SQLSTATE[$state]");
										}

										public function getSQLState(): string
										{
											return $this->state;
										}
									};
								}

								return parent::execute();
							}
						};
					}
				};
			}
		};
	}
}
