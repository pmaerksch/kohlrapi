<?php

/**
 * ApiEntity — Abstract base entity for all persisted domain objects.
 * @author  Philip Märksch
 * Provides a common foundation for all entities:
 *   - Auto-generated integer ID (internal, never exposed)
 *   - UUID v4 generated on construction (exposed via 'uuid' serializer group)
 *   - updateFromEntity() — copies all properties except UUID from another instance
 */

namespace pmaerksch\Kohlrapi;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Represents an entity with an auto-generated ID and universally unique identifier (UUID).
 */
#[ORM\MappedSuperclass]
class ApiEntity
{
	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	protected ?int $id = null;

	#[Groups(['uuid'])]
	#[ORM\Column(type: Types::GUID)]
	protected ?string $uuid = null;



	public function __construct(string|null $uuid = null)
	{
		if ( $uuid === null )
		{
			$this->uuid = Uuid::v4()->toRfc4122();
		}
		else
		{
			$this->uuid = $uuid;
		}
	}



	public function updateFromEntity(ApiEntity $updated): static
	{
		foreach ( get_object_vars($updated) as $key => $value )
		{
			if ( $key !== 'uuid' )
			{
				$this->$key = $value;
			}
		}

		return $this;
	}



	public function getId(): ?int
	{
		return $this->id;
	}



	public function getUuid(): ?string
	{
		return $this->uuid;
	}



	public function setUuid(string $uuid): static
	{
		$this->uuid = $uuid;
		return $this;
	}
}