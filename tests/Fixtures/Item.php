<?php

namespace pmaerksch\Kohlrapi\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use pmaerksch\Kohlrapi\ApiEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ItemRepository::class)]
class Item extends ApiEntity
{
	#[ORM\Column]
	#[Groups(['write'])]
	#[Assert\NotBlank]
	private string $name = '';

	#[ORM\Column]
	private string $owner = 'alice';

	#[ORM\Column]
	#[Groups(['write'])]
	private int $qty = 0;



	public function getName(): string
	{
		return $this->name;
	}



	public function setName(string $name): void
	{
		$this->name = $name;
	}



	public function getOwner(): string
	{
		return $this->owner;
	}



	public function setOwner(string $owner): void
	{
		$this->owner = $owner;
	}



	public function getQty(): int
	{
		return $this->qty;
	}



	public function setQty(int $qty): void
	{
		$this->qty = $qty;
	}
}
