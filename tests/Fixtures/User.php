<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Fixtures;

use BillKit\Laravel\Billable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $billkit_customer_id
 * @property string      $name
 * @property string      $email
 */
class User extends Model
{
    use Billable;

    protected $table = 'users';

    /** @var list<string> */
    protected $guarded = [];
}
