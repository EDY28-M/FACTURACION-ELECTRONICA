<?php

namespace Modules\Order\Mail;

use App\CoreFacturalo\Helpers\Storage\StorageDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class OrderFormEmail extends Mailable
{
    use Queueable, SerializesModels;
    use StorageDocument;

    public $company;
    public $order_form;

    public function __construct($company, $order_form)
    {
        $this->company = $company;
        $this->order_form = $order_form;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $pdf = $this->getStorage($this->order_form->filename, 'order_form');
        return $this->subject('Envio de orden de pedido')
                    ->from(\App\Support\MailBrand::address(), \App\Support\MailBrand::name('Orden de pedido'))
                    ->when(\App\Support\MailBrand::replyTo(), function ($mail, $reply) { $mail->replyTo($reply); })
                    ->view('order::order_forms.templates.email')
                    ->attachData($pdf, $this->order_form->filename.'.pdf');
    }
}