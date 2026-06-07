<?php

namespace pmaerksch\Kohlrapi;

/**
 * Implemented by entities that serialize themselves to an array, rather than
 * relying on the Symfony serializer + serializer groups. When an entity returned
 * through {@see ApiController::singleResponse()} / {@see ApiController::listResponse()}
 * implements this, its getDataAsArray() output is used verbatim; otherwise the
 * serializer is applied. This lets read endpoints emit rich, relation-aware and
 * computed payloads without annotation gymnastics.
 */
interface ArraySerializable
{
	public function getDataAsArray(): array;
}
