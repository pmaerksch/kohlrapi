<?php

namespace pmaerksch\Kohlrapi\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Tag
{
	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	private ?int $id = null;



	public function __construct(
		#[ORM\ManyToOne(inversedBy: 'tags')]
		#[ORM\JoinColumn(nullable: false)]
		private Item $item,
		#[ORM\Column]
		private string $label,
	)
	{
	}



	public function getId(): ?int
	{
		return $this->id;
	}



	public function getItem(): Item
	{
		return $this->item;
	}



	public function getLabel(): string
	{
		return $this->label;
	}
}
