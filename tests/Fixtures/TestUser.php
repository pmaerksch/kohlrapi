<?php

namespace pmaerksch\Kohlrapi\Tests\Fixtures;

use pmaerksch\Kohlrapi\ErrorDetailAware;
use Symfony\Component\Security\Core\User\UserInterface;

class TestUser implements UserInterface, ErrorDetailAware
{
	public function __construct(
		private readonly string $name = 'alice',
		private readonly bool   $details = false,
	)
	{
	}



	public function getRoles(): array
	{
		return ['ROLE_USER'];
	}



	public function getUserIdentifier(): string
	{
		return $this->name;
	}



	public function eraseCredentials(): void
	{
	}



	public function showsErrorDetails(): bool
	{
		return $this->details;
	}
}
