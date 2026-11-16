<?php

namespace Hyn\Tenancy\Abstracts;

use Hyn\Tenancy\Traits\UsesSystemConnection;
use Illuminate\Database\Eloquent\Model;

abstract class SystemModel extends Model
{
    use UsesSystemConnection;
}
