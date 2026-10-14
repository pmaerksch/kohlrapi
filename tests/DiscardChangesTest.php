<?php

namespace pmaerksch\Kohlrapi\Tests;

use pmaerksch\Kohlrapi\ApiController;
use pmaerksch\Kohlrapi\Tests\Fixtures\Item;
use pmaerksch\Kohlrapi\Tests\Fixtures\Tag;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Rejected writes must not be persisted by a later flush in the same request -
 * including changes to collections (N4).
 */
class DiscardChangesTest extends KohlrapiTestCase
{
	private function itemWithTags(): Item
	{
		$item = new Item();
		$item->setName('a');
		$item->addTag('one');
		$item->addTag('two');
		$this->em->persist($item);
		$this->em->flush();
		$this->em->clear();

		return $this->em->getRepository(Item::class)->findOneBy(['uuid' => $item->getUuid()]);
	}



	public static function collectionChanges(): array
	{
		return [
			'added'   => [fn(Item $i) => $i->addTag('three')],
			'removed' => [fn(Item $i) => $i->removeTag($i->getTags()->first())],
			'both'    => [function (Item $i)
			{
				$i->removeTag($i->getTags()->first());
				$i->addTag('three');
			}],
		];
	}



	#[DataProvider('collectionChanges')]
	public function testCollectionChangesAreDiscarded(callable $change): void
	{
		$item = $this->itemWithTags();
		$change($item);
		$item->setName('changed');

		$controller = $this->controller();
		\Closure::bind(fn() => $this->discardChanges([$item]), $controller, ApiController::class)();
		$this->em->flush();

		$this->assertSame('a', $item->getName());
		$this->assertEqualsCanonicalizing(['one', 'two'], $item->getTags()->map(fn(Tag $t) => $t->getLabel())->toArray());

		$this->em->clear();
		$labels = array_map(fn(Tag $t) => $t->getLabel(), $this->em->getRepository(Tag::class)->findAll());
		$this->assertEqualsCanonicalizing(['one', 'two'], $labels);
	}



	public function testRejectedUpdateWithCollectionPayloadWritesNothing(): void
	{
		$item     = $this->itemWithTags();
		$response = $this->controller()->handleUpdate(Item::class, $this->request('{"name":"","tags":["x"]}', ['uuid' => $item->getUuid()]));
		$this->em->flush();

		$this->assertSame(422, $response->getStatusCode());
		$this->em->clear();
		$labels = array_map(fn(Tag $t) => $t->getLabel(), $this->em->getRepository(Tag::class)->findAll());
		$this->assertEqualsCanonicalizing(['one', 'two'], $labels, 'tags in DB: ' . json_encode($labels));
	}



	public function testRejectedBulkWithCollectionPayloadWritesNothing(): void
	{
		$item     = $this->itemWithTags();
		$response = $this->controller()->handleBulk(Item::class, $this->request(json_encode([
			['uuid' => $item->getUuid(), 'tags' => ['x']],
			['name' => ''],
		])));
		$this->em->flush();

		$this->assertSame(422, $response->getStatusCode());
		$this->em->clear();
		$this->assertCount(2, $this->em->getRepository(Tag::class)->findAll());
	}
}
