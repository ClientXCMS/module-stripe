<?php
namespace App\Stripe\Database;

use ClientX\Database\Table;

class StripeTable extends Table
{

    protected $table = "stripe_transactions";

    public function createTransaction($webhook)
    {
        return $this->insert([
            'payment_id' => $webhook->id,
            'user_id' => $webhook->metadata->user,
            'payer_email' => $webhook->customer_email ?? '',
            'total' => $webhook->amount_total,
            'subtotal' => $webhook->amount_subtotal,
            'tax' => $webhook->total_details->amount_tax,
        ]);
    }
}
