<?php

namespace pmaerksch\Kohlrapi\Tests;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Exception;
use pmaerksch\Kohlrapi\Tests\Fixtures\Item;

/**
 * Malformed UUIDs: SQLite simply finds no row; PostgreSQL's uuid type raises
 * SQLSTATE 22P02, simulated here with an entity manager whose lookups throw.
 */
class UuidLookupTest extends KohlrapiTestCase
{
	/**
	 * An entity manager whose repository lookups fail with the given SQLSTATE.
	 */
	private function failingEm(string $sqlState): EntityManagerInterface
	{
		return new class($this->em, $sqlState) extends EntityManagerDecorator
		{
			public function __construct(EntityManagerInterface $em, private string $sqlState)
			{
				parent::__construct($em);
			}

			public function getRepository(string $className): EntityRepository
			{
				$error = new DriverException(new class($this->sqlState) extends Exception implements DriverExceptionInterface
				{
					public function __construct(private string $state)
					{
						parent::__construct('simulated driver error');
					}

					public function getSQLState(): string
					{
						return $this->state;
					}
				}, null);

				return new class($this->wrapped, $this->wrapped->getClassMetadata($className), $error) extends EntityRepository
				{
					public function __construct(EntityManagerInterface $em, $metadata, private DriverException $error)
					{
						parent::__construct($em, $metadata);
					}

					public function findOneBy(array $criteria, ?array $orderBy = null): ?object
					{
						throw $this->error;
					}

					public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
					{
						throw $this->error;
					}
				};
			}
		};
	}



	public function testMalformedUuidIs404OnSqlite(): void
	{
		$this->assertSame(404, $this->controller()->handleFetch(Item::class, 'not-a-uuid')->getStatusCode());
	}



	public function testPostgresInvalidTextRepresentationIs404(): void
	{
		$controller = $this->controller($this->failingEm('22P02'));

		$this->assertSame(404, $controller->handleFetch(Item::class, 'not-a-uuid')->getStatusCode());
		$this->assertSame(404, $controller->handleUpdate(Item::class, $this->request('{"name":"x"}', ['uuid' => 'not-a-uuid']))->getStatusCode());
		$this->assertSame(404, $controller->handleBulk(Item::class, $this->request('[{"uuid":"not-a-uuid","name":"x"}]'))->getStatusCode());
		$this->assertSame(404, $controller->handleBulkDelete(Item::class, $this->request('["not-a-uuid"]'))->getStatusCode());
	}



	public function testOtherDriverErrorsAreRethrown(): void
	{
		$this->expectException(DriverException::class);
		$this->controller($this->failingEm('08006'))->handleFetch(Item::class, 'x');
	}
}
