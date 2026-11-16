<?php

namespace App\Console\Commands;

use App\Models\System\Plan;
use App\Models\System\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent bootstrap of a fresh system database: first administrator and base plan.
 * It never touches existing data, so it is safe to run on every deployment.
 */
class SystemInstall extends Command
{
    protected $signature = 'system:install
                            {--email= : Administrator e-mail (only used when no system user exists)}
                            {--password= : Administrator password (prefer the ADMIN_PASSWORD environment variable: argv is visible in process lists)}
                            {--name=Administrador : Administrator display name}';

    protected $description = 'Create the first system administrator and the base plan when they do not exist yet';

    public function handle()
    {
        $db = DB::connection('system');
        $messages = [];

        $db->transaction(function () use ($db, &$messages) {
            if ($db->table('users')->count() === 0) {
                $email = $this->option('email');
                $password = getenv('ADMIN_PASSWORD') ?: $this->option('password');
                if (!$email || !$password) {
                    throw new \RuntimeException('--email and ADMIN_PASSWORD (or --password) are required to create the first administrator.');
                }
                User::create([
                    'name' => $this->option('name'),
                    'email' => $email,
                    'password' => bcrypt($password),
                ]);
                $messages[] = 'ADMIN_CREATED';
            } else {
                $messages[] = 'ADMIN_EXISTS';
            }

            if ($db->table('plan_documents')->count() === 0) {
                $db->table('plan_documents')->insert([
                    ['id' => 1, 'description' => 'Facturas, boletas, notas de débito y crédito, resúmenes y anulaciones'],
                    ['id' => 2, 'description' => 'Guias de remisión'],
                    ['id' => 3, 'description' => 'Retenciones'],
                    ['id' => 4, 'description' => 'Percepciones'],
                ]);
                $messages[] = 'PLAN_DOCUMENTS_CREATED';
            }

            if ($db->table('plans')->count() === 0) {
                Plan::create([
                    'name' => 'Ilimitado',
                    'pricing' => 99,
                    'limit_users' => 0,
                    'limit_documents' => 0,
                    'plan_documents' => [1, 2, 3, 4],
                    'locked' => true,
                ]);
                $messages[] = 'PLAN_CREATED';
            }
        });

        foreach ($messages as $message) {
            $this->line($message);
        }

        return 0;
    }
}
