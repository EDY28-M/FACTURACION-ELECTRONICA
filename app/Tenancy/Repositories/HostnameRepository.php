<?php

namespace Hyn\Tenancy\Repositories;

use Hyn\Tenancy\Contracts\Hostname;
use Hyn\Tenancy\Contracts\Repositories\HostnameRepository as Contract;
use Hyn\Tenancy\Contracts\Website;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Eloquent\Builder;

class HostnameRepository implements Contract
{
    protected $hostname;
    protected $cache;

    public function __construct(Hostname $hostname, CacheFactory $cache)
    {
        $this->hostname = $hostname;
        $this->cache = $cache;
    }

    public function findByHostname(string $hostname)
    {
        return $this->hostname->newQuery()->where('fqdn', $hostname)->first();
    }

    public function getDefault()
    {
        if ($default = config('tenancy.hostname.default')) {
            return $this->hostname->newQuery()->where('fqdn', $default)->first();
        }
        return null;
    }

    public function create(Hostname &$hostname): Hostname
    {
        if ($hostname->exists) {
            return $this->update($hostname);
        }

        $hostname->save();
        $this->cache->forget("tenancy.hostname.{$hostname->fqdn}");

        return $hostname;
    }

    public function update(Hostname &$hostname): Hostname
    {
        if (!$hostname->exists) {
            return $this->create($hostname);
        }

        $hostname->save();
        $this->cache->forget("tenancy.hostname.{$hostname->fqdn}");

        return $hostname;
    }

    public function delete(Hostname &$hostname, $hard = false): Hostname
    {
        $fqdn = $hostname->fqdn;
        if ($hard) {
            $hostname->forceDelete();
        } else {
            $hostname->delete();
        }
        $this->cache->forget("tenancy.hostname.{$fqdn}");

        return $hostname;
    }

    public function attach(Hostname &$hostname, Website &$website): Hostname
    {
        $website->hostnames()->save($hostname);
        $website->load('hostnames');

        $this->cache->forget("tenancy.hostname.{$hostname->fqdn}");
        $this->cache->forget("tenancy.website.{$website->uuid}");

        return $hostname;
    }

    public function detach(Hostname &$hostname): Hostname
    {
        if ($hostname->website) {
            $this->cache->forget("tenancy.website.{$hostname->website->uuid}");
        }
        $hostname->website()->dissociate();
        $hostname->save();
        $this->cache->forget("tenancy.hostname.{$hostname->fqdn}");

        return $hostname;
    }

    public function query(): Builder
    {
        return $this->hostname->newQuery();
    }
}
