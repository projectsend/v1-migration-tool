<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Stands in for the host's User model, which this package cannot
 * reference. Only used to satisfy the `auth` middleware in route tests —
 * nothing here asserts anything about accounts.
 */
final class FakeUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
