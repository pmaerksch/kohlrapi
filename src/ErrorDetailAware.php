<?php

namespace pmaerksch\ApiCaptain;

/**
 * Implemented by the user entity to declare whether it may receive detailed API
 * error messages. When the authenticated user does not implement this, or
 * returns false, {@see ApiController::errorResponse()} omits the (potentially
 * sensitive) detail and sends only the error key.
 */
interface ErrorDetailAware
{
	public function showsErrorDetails(): bool;
}
