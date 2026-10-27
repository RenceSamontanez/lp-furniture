<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\OrderStages;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Your LP Furniture order LP-XXXX is now <stage>": stage text, the admin's message, a Track My Order link and the photo. */
class OrderStageMail extends Mailable
{
    public string $stageLabel;
    public string $stageText;

    public function __construct(
        public Order $order,
        public string $stage,
        public ?string $adminMessage,
        public ?string $photoPath,
        public string $kind = 'stage',
    ) {
        $this->stageLabel = OrderStages::label($stage, OrderStages::isPickup($order));
        $this->stageText = OrderStages::customerCopy($order, $stage);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your LP Furniture order ' . $this->order->order_number . ' is now ' . $this->stageLabel);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.order-stage', with: [
            'trackUrl' => route('orders.show', $this->order->id),
            'photoUrl' => $this->photoPath ? asset('storage/' . ltrim($this->photoPath, '/')) : null,
        ]);
    }
}
