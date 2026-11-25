<?php

namespace Modules\Order\Mail;

use App\CoreFacturalo\Helpers\Storage\StorageDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class DispatchEmail extends Mailable
{
    use Queueable, SerializesModels;
    use StorageDocument;

    public $dispatch;

    public function __construct($dispatch)
    {
        $this->dispatch = $dispatch;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $pdf = $this->getStorage($this->dispatch->filename, 'pdf');
        return $this->subject('Envio de guía')
                    ->from(\App\Support\MailBrand::address(), \App\Support\MailBrand::name('Guía'))
                    ->when(\App\Support\MailBrand::replyTo(), function ($mail, $reply) { $mail->replyTo($reply); })
                    ->view('order::dispatches.templates.email')
                    ->attachData($pdf, $this->dispatch->filename.'.pdf');
    }
}