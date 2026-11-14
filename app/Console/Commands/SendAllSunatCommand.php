<?php

namespace App\Console\Commands;

use App\CoreFacturalo\Helpers\Sunat\SendDeadline;
use Carbon\Carbon;
use Facades\App\Http\Controllers\Tenant\DocumentController;
use Illuminate\Console\Command;
use App\Traits\CommandTrait;
use App\Models\Tenant\{
    Configuration,
    Document
};

class SendAllSunatCommand extends Command
{
    use CommandTrait;
    
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'online:send-all';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all pending documents to be sent to the Sunat';
    
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
        if (Configuration::firstOrFail()->cron) {
            if ($this->isOffline()) {
                $this->info('Offline service is enabled');
                
                return;
            }
            
            // RS 003-2023/SUNAT: expired facturas/linked notes can no longer be sent; leave them out.
            $limit = Carbon::now(SendDeadline::TIMEZONE)->startOfDay()->subDays(SendDeadline::DAYS_AFTER_ISSUE)->format('Y-m-d');

            $pending = Document::query()
                ->where('group_id', '01')
                ->where('send_server', 0)
                ->whereIn('state_type_id', ['01','03'])
                // ->orWhere('sunat_shipping_status', '!=', '')
                ->where('success_sunat_shipping_status', false);

            $isExpiredInvoice = function ($query) use ($limit) {
                $query->whereIn('document_type_id', ['01', '07', '08'])
                    ->where('series', 'like', 'F%')
                    ->where('date_of_issue', '<', $limit);
            };

            $skipped = (clone $pending)->where($isExpiredInvoice)->count();
            if ($skipped > 0) {
                $message = "online:send-all skipped {$skipped} expired factura(s)/linked note(s) (RS 003-2023: send deadline passed)";
                $this->warn($message);
                \Log::warning($message);
            }

            $documents = $pending->whereNot($isExpiredInvoice)->get();

            foreach ($documents as $document) {
                try {
                    $response = DocumentController::send($document->id);
                    
                    $document->sunat_shipping_status = json_encode($response);
                    $document->success_sunat_shipping_status = true;
                    $document->save();
                }
                catch (\Exception $e) {

                    $document->success_sunat_shipping_status = false;

                    $document->sunat_shipping_status = json_encode([
                        'sucess' => false,
                        'message' => $e->getMessage(),
                        'payload' => $e
                    ]);
                    
                    $document->save();
                }
            }
        }
        else {
            $this->info('The crontab is disabled');
        }
        
        $this->info('The command is finished');
    }
}
