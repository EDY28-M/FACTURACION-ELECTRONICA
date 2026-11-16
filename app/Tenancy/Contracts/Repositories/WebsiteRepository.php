<?php

namespace Hyn\Tenancy\Contracts\Repositories;

use Hyn\Tenancy\Contracts\Website;
use Illuminate\Database\Eloquent\Builder;

interface WebsiteRepository
{
    public function findByUuid(string $uuid);
    public function findById($id);
    public function create(Website &$website): Website;
    public function update(Website &$website): Website;
    public function delete(Website &$website, $hard = false): Website;
    public function query(): Builder;
}
