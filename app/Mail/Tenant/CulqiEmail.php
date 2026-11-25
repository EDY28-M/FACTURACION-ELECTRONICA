<?php

namespace App\Mail\Tenant;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class CulqiEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $document;

    public function __construct($document)
    {
        $this->document = $document;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        // Cash orders are not paid yet: they get an order-received message, not a payment receipt.
        $type = $this->document->mail_type ?? 'card';
        if ($type === 'cash' || $type === 'owner') {
            $subject = $type === 'owner'
                ? 'Nuevo pedido en la tienda virtual '.($this->document->order_number ?? '')
                : 'Recibimos su pedido '.($this->document->order_number ?? '');

            return $this->subject(trim($subject))
                        ->from(\App\Support\MailBrand::address(), \App\Support\MailBrand::name('Tienda virtual'))
                    ->when(\App\Support\MailBrand::replyTo(), function ($mail, $reply) { $mail->replyTo($reply); })
                        ->view('tenant.templates.email.store_order');
        }

        return $this->subject('Constancia - Nuevo pago desde Ecommerce')
                    ->from(\App\Support\MailBrand::address(), \App\Support\MailBrand::name('Constancia de Pago'))
                    ->when(\App\Support\MailBrand::replyTo(), function ($mail, $reply) { $mail->replyTo($reply); })
                    ->view('tenant.templates.email.culqui_payment');
    }
}
