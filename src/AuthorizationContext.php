<?php

namespace pmaerksch\Kohlrapi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Immutable bundle of everything an authorization decision might need, passed to
 * {@see ApiController::isAuthorized()}. It is assembled by {@see ApiController::can()}
 * (which fills in the current user and request automatically), so callers never
 * build it by hand and overrides only ever read it.
 *
 * New fields may be added in future versions without breaking existing
 * isAuthorized() overrides, since the controller constructs the object and
 * implementations merely consume the fields they care about.
 */
final class AuthorizationContext
{
	/**
	 * @param string $action The requested permission token (a Symfony role/attribute for the default implementation).
	 * @param string|null $subjectClass FQCN of the entity the action concerns, when known (set even when no instance exists yet, e.g. create/search).
	 * @param object|null $subject The entity instance the action concerns, when one has been loaded (fetch/update).
	 * @param string|null $operation The CRUD operation: 'search' | 'fetch' | 'create' | 'update' | 'bulk' | 'delete'.
	 * @param object|null $user The authenticated user (Symfony UserInterface), or null when unauthenticated.
	 * @param Request|null $request The current HTTP request, when one is available.
	 */
	public function __construct(
		public readonly string   $action,
		public readonly ?string  $subjectClass = null,
		public readonly ?object  $subject = null,
		public readonly ?string  $operation = null,
		public readonly ?object  $user = null,
		public readonly ?Request $request = null,
	)
	{
	}
}
