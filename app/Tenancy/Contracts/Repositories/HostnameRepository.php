<?php

namespace Hyn\Tenancy\Contracts\Repositories;

use Hyn\Tenancy\Contracts\Hostname;
use Hyn\Tenancy\Contracts\Website;
use Illuminate\Database\Eloquent\Builder;

interface HostnameRepository
{
    public function findByHostname(string $hostname);
    public function getDefault();
    public function create(Hostname &$hostname): Hostname;
    public function update(Hostname &$hostname): Hostname;
    public function delete(Hostname &$hostname, $hard = false): Hostname;
    public function attach(Hostname &$hostname, Website &$website): Hostname;
    public function detach(Hostname &$hostname): Hostname;
    public function query(): Builder;
}
