<?php

namespace pmaerksch\Kohlrapi\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use pmaerksch\Kohlrapi\Tests\Fixtures\Item;
use pmaerksch\Kohlrapi\Tests\Fixtures\TestController;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;

/**
 * Wires the library against real dependencies: Doctrine ORM on in-memory SQLite,
 * the Symfony serializer + validator, and a token storage for getUser().
 */
abstract class KohlrapiTestCase extends TestCase
{
	protected EntityManagerInterface $em;

	/** Log records written by the controllers: [level, message, context]. */
	protected array $logs = [];



	protected function setUp(): void
	{
		$config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/Fixtures'], true);

		if ( PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects') )
		{
			$config->enableNativeLazyObjects(true);
		}

		$registry = null;
		$config->setRepositoryFactory(new class($registry) implements RepositoryFactory
		{
			private array $repositories = [];

			public function __construct(private ?AbstractManagerRegistry &$registry)
			{
			}

			public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
			{
				$class = $entityManager->getClassMetadata($entityName);
				$repo  = $class->customRepositoryClassName;

				return $this->repositories[ $entityName ] ??= $repo !== null
					? new $repo($this->registry, $entityName)
					: new EntityRepository($entityManager, $class);
			}
		});

		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
		$this->em   = new EntityManager($connection, $config);
		$registry   = $this->registryFor($this->em);

		(new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
	}



	/**
	 * A controller wired to the test entity manager (or $em, e.g. a decorator).
	 */
	protected function controller(?EntityManagerInterface $em = null, ?UserInterface $user = null): TestController
	{
		$metadata   = new ClassMetadataFactory(new AttributeLoader());
		$serializer = new Serializer([new ObjectNormalizer($metadata, null, null, new ReflectionExtractor())], [new JsonEncoder()]);
		$validator  = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
		$logs       = &$this->logs;
		$logger     = new class($logs) extends AbstractLogger
		{
			public function __construct(private array &$logs)
			{
			}

			public function log($level, \Stringable|string $message, array $context = []): void
			{
				$this->logs[] = [$level, (string)$message, $context];
			}
		};

		$kernel = new class('prod', false) extends Kernel
		{
			public function registerBundles(): iterable
			{
				return [];
			}

			public function registerContainerConfiguration(LoaderInterface $loader): void
			{
			}
		};

		$tokens = new TokenStorage();

		if ( $user !== null )
		{
			$tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
		}

		$container = new Container();
		$container->set('security.token_storage', $tokens);

		$controller = new TestController($em ?? $this->em, $serializer, $kernel, $logger, $validator, new RequestStack());
		$controller->setContainer($container);

		return $controller;
	}



	protected function request(string $body, array $attributes = []): Request
	{
		$request = Request::create('/', 'POST', [], [], [], [], $body);
		$request->attributes->add($attributes);

		return $request;
	}



	protected function body(Response $response): array
	{
		return json_decode((string)$response->getContent(), true) ?? [];
	}



	protected function createItem(string $name, string $owner = 'alice', int $qty = 0): Item
	{
		$item = new Item();
		$item->setName($name);
		$item->setOwner($owner);
		$item->setQty($qty);

		$this->em->persist($item);
		$this->em->flush();

		return $item;
	}



	/**
	 * Reloads an item from the database, bypassing the identity map.
	 */
	protected function reload(Item $item): ?Item
	{
		$uuid = $item->getUuid();
		$this->em->clear();

		return $this->em->getRepository(Item::class)->findOneBy(['uuid' => $uuid]);
	}



	private function registryFor(EntityManagerInterface $em): AbstractManagerRegistry
	{
		return new class($em) extends AbstractManagerRegistry
		{
			public function __construct(private EntityManagerInterface $em)
			{
				parent::__construct('test', ['default' => 'conn'], ['default' => 'em'], 'default', 'default', \Doctrine\Persistence\Proxy::class);
			}

			protected function getService(string $name): object
			{
				return $name === 'em' ? $this->em : $this->em->getConnection();
			}

			protected function resetService(string $name): void
			{
			}

			public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
			{
				return $this->em->getRepository($persistentObject);
			}
		};
	}
}
