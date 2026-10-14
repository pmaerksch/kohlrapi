<?php

/**
 * ApiEntity — Abstract base entity for all persisted domain objects.
 * @author  Philip Märksch
 * Provides a common foundation for all entities:
 *   - Auto-generated integer ID (internal, never exposed)
 *   - UUID v4 generated on construction (exposed via 'uuid' serializer group)
 *   - updateFromEntity() — (deprecated) copies public/protected properties except id/uuid from another instance
 *   - parseJson() — wraps an incoming client payload in a JsonHelper for use in updateFromJson()
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



	/**
	 * Copies the properties of $updated onto this instance, except the identifiers
	 * (id, uuid) — overwriting the id of a managed entity would corrupt Doctrine's
	 * identity map (a freshly deserialized $updated has id === null).
	 *
	 * Only public and protected properties are copied: get_object_vars() runs in the
	 * scope of ApiEntity and can't see a subclass's private properties, so those are
	 * silently skipped. Copying them instead would overwrite real data with the defaults
	 * of a freshly deserialized object (e.g. a null createdAt), so this isn't "fixed".
	 *
	 * @deprecated since 1.15, will be removed in 2.0. Set the fields explicitly, e.g. in
	 *             an updateFromJson() via {@see parseJson()}, or deserialize with
	 *             AbstractNormalizer::OBJECT_TO_POPULATE onto the managed entity.
	 */
	public function updateFromEntity(ApiEntity $updated): static
	{
		trigger_deprecation('pmaerksch/kohlrapi', '1.15', 'ApiEntity::updateFromEntity() is deprecated and skips private properties; set the fields explicitly instead.');

		foreach ( get_object_vars($updated) as $key => $value )
		{
			if ( $key !== 'id' && $key !== 'uuid' )
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



	/**
	 * Wraps a client payload (JSON string or already-decoded array) for use in
	 * updateFromJson() implementations, via {@see JsonHelper::require()} and
	 * {@see JsonHelper::optional()}.
	 *
	 * @throws \InvalidArgumentException if $json is not valid JSON / not an array
	 */
	protected function parseJson(mixed $json): JsonHelper
	{
		return new JsonHelper($json);
	}
}