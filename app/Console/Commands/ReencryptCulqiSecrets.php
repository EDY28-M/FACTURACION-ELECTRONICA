<?php

namespace App\Console\Commands;

use App\Casts\EncryptedSecret;
use App\Models\System\Configuration;
use App\Models\Tenant\ConfigurationEcommerce;
use Hyn\Tenancy\Environment;
use Hyn\Tenancy\Models\Website;
use Illuminate\Console\Command;

/**
 * Encrypts legacy plain-text Culqi private keys (system + every tenant). Idempotent.
 */
class ReencryptCulqiSecrets extends Command
{
    protected $signature = 'secrets:reencrypt-culqi';

    protected $description = 'Encrypt legacy plain-text Culqi private keys at rest (system and all tenants)';

    public function handle()
    {
        $count = $this->reencrypt(Configuration::first());

        foreach (Website::query()->get() as $website) {
            app(Environment::class)->tenant($website);
            $count += $this->reencrypt(ConfigurationEcommerce::first());
        }

        $this->info("Re-encrypted {$count} key(s)");

        return 0;
    }

    private function reencrypt($config): int
    {
        $raw = $config ? ($config->getAttributes()['token_private_culqui'] ?? null) : null;
        if ($raw === null || $raw === '' || EncryptedSecret::looksEncrypted($raw)) {
            return 0;
        }

        $config->token_private_culqui = $raw;
        $config->save();

        return 1;
    }
}
