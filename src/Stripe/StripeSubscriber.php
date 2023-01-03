<?php

namespace App\Stripe;

use App\Auth\Database\UserTable;
use App\Auth\User;
use App\Shop\Entity\Product;
use App\Shop\Entity\Recurring;
use App\Shop\Entity\SubscriptionDetails;
use App\Shop\Entity\Transaction;
use App\Shop\Entity\TransactionItem;
use App\Stripe\Api\Entity\StripeUser;
use App\Stripe\Api\Stripe;
use ClientX\Database\NoRecordException;
use ClientX\Renderer\RendererInterface;
use DateTimeInterface;

class StripeSubscriber implements \App\Shop\Payment\SubscribeInterface
{

    private Stripe $stripe;
    private UserTable $table;
    private RendererInterface $renderer;

    public function __construct(
        Stripe $stripe,
        UserTable $table,
        RendererInterface $renderer
    ) {
        $this->stripe   = $stripe;
        $this->table    = $table;
        $this->renderer = $renderer;
    }
    public function getLink(User $user, Transaction $transaction, array $links)
    {

        $items = $transaction->getItems();
        $discounts = collect($items)->filter(function ($item) {
            return $item->price() < 0;
        })->reduce(function ($i, TransactionItem $item) {
            return $i + $item->price();
        }, 0);
        $product = collect($items)->filter(function (TransactionItem $transactionItem) {
            return $transactionItem->getOrderable() instanceof Product && $transactionItem->getOrderable()->getPaymentType() == 'recurring';
        })->first();
        $months = Recurring::from(json_decode($product->getData(), true)['_recurring'])->getMonths();

        $items = collect($transaction->getItems())->map(function (TransactionItem $item) use ($transaction, $months, $discounts) {
            return [
                [
                    'price_data' =>
                        [
                            'currency' => $transaction->getCurrency(),
                            'recurring' => [
                                'interval_count' => $months,
                                'interval'  => 'month'
                            ],
                            'unit_amount' => number_format($item->priceWithTax() + $discounts, 2) * 100,
                            'product_data' => ["name" => $item->getName()]
                        ],
                    'quantity' => $item->getQuantity(),
                ]
            ];
        })->toArray();

        $user = $this->createStripeUser($user);
        $session = $this->stripe->createPaymentSession($user, $items, $links, $transaction, 'subscription');
        $params = ['session' => $session, 'key' => $this->stripe->getPublicKey()];
        return $this->renderer->render("@stripe_admin/autoredirect", $params);
    }

    public function getDetails(string $token)
    {
        return $this->stripe->getSubscription($token);
    }

    public function cancel(string $token)
    {

        return $this->stripe->cancelSubscription($token);
    }

    public function reactive(string $token)
    {
        return null;
    }

    public function type(): string
    {
        return 'stripe';
    }

    public function formatSubscription(int $id, string $token, $subscription): SubscriptionDetails
    {
        $invoices = $this->stripe->getInvoices($token)->data;
        $invoiceid = end($invoices)->id;
        $invoice = $this->stripe->getInvoice($invoiceid);
        $payment = $this->stripe->getPaymentIntent($invoice->payment_intent);
        $details = new SubscriptionDetails();
        $details->setId($id);

        $details->setLast4($payment->charges->data[0]->payment_method_details->card->last4);
        $details->setType('stripe');
        $details->setToken($token);
        $details->setState($subscription->status);
        $details->setPrice($subscription->items->data[0]->plan->amount_decimal / 100);
        $details->setStartDate((new \DateTime())->setTimestamp($subscription->created));
        $details->setNextRenewal((new \DateTime())->setTimestamp($subscription->current_period_end));
        $details->setEmailPayer($invoice->customer_email);
        return $details;
    }

    public function fetchLastTransactionId(string $token, string $last): ?string
    {

        if ($last == current($this->stripe->getInvoices($token)->lines->data)->id){
            return null;
        }
        return current($this->stripe->getInvoices($token)->lines->data)->id;
    }



    private function createStripeUser(StripeUser $stripeUser): StripeUser
    {
        try {
            /** @var StripeUser */
            $user = $this->table->findBy('stripe_id', json_encode($stripeUser->getStripeId(true)) ?? "");
            $stripeUser->updateStripeId($user->getStripeId());
            if ($stripeUser->getStripeId() == null){
                $this->stripe->createCustomer($stripeUser);
                $this->table->update($stripeUser->getId(), [
                    'stripe_id' => json_encode($stripeUser->getStripeId(true))
                ]);
            }
        } catch (NoRecordException $e) {
            $this->stripe->createCustomer($stripeUser);
            $this->table->update($stripeUser->getId(), [
                'stripe_id' => json_encode($stripeUser->getStripeId(true))
            ]);
        }
        return $stripeUser;
    }
}