<?php

namespace Modules\Sale\Mail;

use App\CoreFacturalo\Helpers\Storage\StorageDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class ContractEmail extends Mailable
{
    use Queueable, SerializesModels;
    use StorageDocument;

    public $company;
    public $contract;

    public function __construct($company, $contract)
    {
        $this->company = $company;
        $this->contract = $contract;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $pdf = $this->getStorage($this->contract->filename, 'contract');
        return $this->subject('Envio de contrato')
                    ->from(\App\Support\MailBrand::address(), \App\Support\MailBrand::name('Contrato'))
                    ->when(\App\Support\MailBrand::replyTo(), function ($mail, $reply) { $mail->replyTo($reply); })
                    ->view('sale::contracts.templates.email')
                    ->attachData($pdf, $this->contract->filename.'.pdf');
    }
}