<?php

namespace pmaerksch\Kohlrapi;

use InvalidArgumentException;

/**
 * Thrown by {@see ApiRepository::search()} / {@see ApiRepository::searchRandom()} when a
 * search request is rejected: it exceeds one of the repository's limits, or a filter
 * value doesn't fit the column it is compared with. {@see ApiController::handleSearch()}
 * answers it with 400 api.errors.invalidSearch; callers of getEntityList() /
 * getRandomEntityList() should do the same.
 */
class ApiInvalidSearchException extends InvalidArgumentException
{
}
