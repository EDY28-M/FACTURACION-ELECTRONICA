<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant\Task;
use Carbon\Carbon;
use Artisan;
use Hyn\Tenancy\Environment;
use Hyn\Tenancy\Models\Website;
use Throwable;

class TenantCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:run';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute the scheduled tasks of the tenants';
    
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle() {
        foreach (Website::query()->with('hostnames')->get() as $website) {
            app(Environment::class)->tenant($website);
            $host = optional($website->hostnames->first())->fqdn ?: (string) $website->id;
            $this->line("Tenant: {$host}");

            $tasks = Task::where('execution_time', Carbon::now()->format('H:i').':00')->get();

            foreach ($tasks as $task) {
                $class = (string) $task->class;

                if (!class_exists($class)
                    || !is_subclass_of($class, Command::class)
                    || $class === self::class
                    || strpos($class, 'App\\Console\\Commands\\') !== 0) {
                    $task->output = 'Comando no permitido.';
                    $task->save();
                    continue;
                }

                try {
                    $name = app($class)->getName();
                    if (!$name) {
                        throw new \RuntimeException('El comando no tiene nombre.');
                    }

                    Artisan::call($name);
                    $task->output = Artisan::output();
                } catch (Throwable $e) {
                    $task->output = $e->getMessage();
                }

                $task->save();
            }
        }

        return 0;
    }
}
