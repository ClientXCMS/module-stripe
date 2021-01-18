<?php
namespace App\Stripe\Actions;

use App\Shop\Database\InvoiceTable;
use \App\Stripe\Api\Stripe;
use App\Stripe\Database\StripeTable;
use Psr\Http\Message\ServerRequestInterface;

class StripeApiAction {

    /**
     * @var Stripe
     */
    private $stripe;

    /**
     * @var StripeTable
     */
    private $table;

    /**
     * @var InvoiceTable
     */
    private $invoiceTable;

    public function __construct(Stripe $stripe, StripeTable $table, InvoiceTable $invoiceTable)
    {
        $this->stripe = $stripe;
        $this->table  = $table;
        $this->invoiceTable = $invoiceTable;
    }

    public function __invoke(ServerRequestInterface $request)
    {
        $signature = $request->getServerParams()["HTTP_STRIPE_SIGNATURE"];
        $webhook = $this->stripe->getWebhook($signature);
        if ($webhook->type === 'checkout.session'){
            $object = $webhook->data->object;
            $id = $object->metadata->invoice;
            if ($object->payment_status !== "paid"){
                $this->invoiceTable->updateStatus(0, $id);
            }
            $this->invoiceTable->update($id, [
                'paymentId' => $object->id
            ]);
            $this->table->createTransaction($webhook);
            var_dump($webhook);
        }

        die();
    }
}