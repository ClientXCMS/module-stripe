<?php

namespace App\Stripe;

use App\Account\User;
use App\Auth\Database\UserTable;
use App\Shop\Entity\Product;
use App\Shop\Entity\Transaction;
use App\Shop\Entity\TransactionItem;
use App\Shop\Payment\AbstractPaymentManager;
use App\Shop\Services\SubscriptionService;
use App\Shop\Services\TransactionService;
use App\Stripe\Api\Entity\StripeUser;
use App\Stripe\Api\Stripe;
use ClientX\Auth;
use ClientX\Database\NoRecordException;
use ClientX\Payment\PaymentManagerInterface;
use ClientX\Renderer\RendererInterface;
use ClientX\Router;
use Psr\Http\Message\ServerRequestInterface as Request;
use Stripe\Event;

class StripePaymentManager extends AbstractPaymentManager implements PaymentManagerInterface
{
    /**
     * @var Stripe
     */
    private Stripe $stripe;
    private UserTable $table;
    private RendererInterface $renderer;
    private SubscriptionService $subscriptionService;

    public function __construct(
        Router             $router,
        Auth               $auth,
        TransactionService $service,
        Stripe             $stripe,
        UserTable          $table,
        RendererInterface  $renderer,
        SubscriptionService $subscriptionService
    )
    {
        parent::__construct($router, $auth, $service);
        $this->stripe = $stripe;
        $this->table = $table;
        $this->renderer = $renderer;
        $this->subscriptionService = $subscriptionService;
    }

    public function process(Transaction $transaction, Request $request, User $user)
    {


        $user = $this->getUser();
        if ($user === null) {
            return;
        }

        $links =  $this->getRedirectsLinks($request, $transaction);

        if ($this->checkIfTransactionCanSubscribe($transaction)) {
            return (new StripeSubscriber($this->stripe, $this->table, $this->renderer, $this->subscriptionService))->getLink($user, $transaction, $links);
        }

        $items = collect($transaction->getItems())->filter(function ($item) {
            return $item->price() > 0;
        })->map(function (TransactionItem $item, $i) use ($transaction) {
            $discount = 0;
            $next = $transaction->getItems()[$i + 1] ?? null;
            if ($next != null) {
                if ($next->price() < 0) {
                    $discount = $next->price();
                }
            }
            return
                [
                    'price_data' =>
                        [
                            'currency' => $transaction->getCurrency(),
                            'unit_amount' => ($item->priceWithTax() + $discount) * 100,
                            'product_data' => ["name" => $item->getName()]
                        ],
                    'quantity' => $item->getQuantity(),
                ];
        })->toArray();

        $user = $this->createStripeUser($this->auth->getUser());
        $session = $this->stripe->createPaymentSession($user, $items,$links, $transaction);
        $params = ['session' => $session, 'key' => $this->stripe->getPublicKey()];
        return $this->renderer->render("@stripe_admin/autoredirect", $params);
    }

    public function refund(array $items): bool
    {
        return false;
    }


    private function createStripeUser(StripeUser $stripeUser): StripeUser
    {
        try {
            /** @var StripeUser */
            $user = $this->table->findBy('stripe_id', json_encode($stripeUser->getStripeId(true)) ?? "");
            $stripeUser->updateStripeId($user->getStripeId());
            if ($stripeUser->getStripeId() == null) {
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

    public function execute(Transaction $transaction, Request $request, User $user)
    {
    }


    public function confirm(Request $request)
    {
        $params = $request->getServerParams();
        $signature = $params["HTTP_STRIPE_SIGNATURE"];
        $webhook = $this->stripe->getWebhook($signature);
        /*if (!in_array($webhook->type, ['checkout.session.completed', 'customer.subscription.created', 'checkout.session.async_payment_failed', 'customer.created', 'customer.deleted', 'customer.subscription.deleted'])) {
            return null;
        }*/

        if (isset($webhook->data->object)) {

            if ($webhook->type === 'checkout.session.async_payment_failed') {
                return $this->refusePayment($webhook);
            }

            if ($webhook->type === 'customer.created') {
                return $this->createCustomer($webhook);
            }

            if ($webhook->type === 'customer.deleted') {
                return $this->deleteCustomer($webhook);
            }
            if ($webhook->type === 'checkout.session.completed') {
                return $this->completePayment($webhook);
            }
            
            if ($webhook->type == 'customer.subscription.updated') {
                return $this->updatedSubscription($webhook);
            }
            
            if ($webhook->type == 'customer.subscription.deleted') {
                return $this->deleteSubscription($webhook);
            }
        }
        return null;
    }

    private function completePayment(\Stripe\Event $webhook)
    {
        $object = $webhook->data->object;
        $id = $object->metadata->transaction ?? 0;
        if ($id == 0) {
            return null;
        }
        $transaction = $this->service->findTransaction($id);
        $id = $object->id;
        $transaction->setTransactionId($id);
        $this->service->updateTransactionId($transaction);
        if ($object->payment_status !== "paid") {
            $transaction->setState($transaction::REFUSED);
            $transaction->setReason("Stripe error");
            $this->service->changeState($transaction);
            $this->service->setReason($transaction);

            return $transaction;
        } else {
            $transaction->setState($transaction::COMPLETED);
            $this->service->complete($transaction);

            foreach ($transaction->getItems() as $item) {
                $this->service->delivre($item);
            }
            $this->service->changeState($transaction);
            return $transaction;
        }
    }

    
    private function updateSubscription(Event $webhook)
    {
        $object = $webhook->data->object;
        $id = $object->metadata->transaction ?? 0;
        if ($id == 0) {
            return null;
        }
        $transaction = $this->service->findTransaction($id);
        $id = $object->id;
        $transaction->setTransactionId($id);
        $user = (new User())->setId($object->metadata->user);
        if ($object->status == "active") {
            $this->subscriptionService->addSubscription($user, $transaction->getItems()[0], $object->id, 'stripe');
            $this->service->updateTransactionId($transaction);
            $transaction->setState($transaction::COMPLETED);
            $this->service->complete($transaction);

            foreach ($transaction->getItems() as $item) {
                $this->service->delivre($item);
            }
        } else {
            $this->service->updateTransactionId($transaction);
            $transaction->setState($transaction::REFUSED);
        }
            
        $this->service->changeState($transaction);
        return $transaction;
    }

    
    private function deleteSubscription(Event $webhook)
    {

        $object = $webhook->data->object;
        $id = $object->metadata->transaction ?? 0;
        $transaction = $this->service->findTransaction($id);
        $id = $object->id;
        $this->subscriptionService->cancel($id);
        return $transaction;
    }
    public function getWebhook(string $signature): Event
    {
        return $this->stripe->getWebhook($signature);
    }

    private function checkIfTransactionCanSubscribe(Transaction $transaction): bool
    {
        if (!array_key_exists(SubscriptionService::KEY_SUBSCRIBE, \ClientX\request()->getParsedBody())) {
            return false;
        }
        return collect($transaction->getItems())->filter(function (TransactionItem $transactionItem) {
                return $transactionItem->getOrderable() instanceof Product && $transactionItem->getOrderable()->getPaymentType() == 'recurring';
            })->count() == 1;
    }

    private function refusePayment(Event $webhook)
    {

        $object = $webhook->data->object;
        $id = $object->metadata->transaction ?? 0;
        if ($id == 0) {
            return null;
        }
        $transaction = $this->service->findTransaction($id);
        $id = $object->id;
        $transaction->setTransactionId($id);
        $transaction->setState($transaction::REFUSED);
        $transaction->setReason("Stripe error");
        $this->service->changeState($transaction);
        $this->service->setReason($transaction);
    }

    private function createCustomer(Event $webhook)
    {
        $email = $webhook->data->object->email;
        try {
            $user = $this->table->findBy("email", $email);
            $user->updateStripeId($webhook->data->object->id);
            $this->table->update($user->getId(), [
                'stripe_id' => json_encode($user->getStripeId(true))
            ]);
            return $user;
        } catch (NoRecordException $e){
            return null;
        }
    }


    private function deleteCustomer(Event $webhook)
    {
        $email = $webhook->data->object->email;
        try {
            $user = $this->table->findBy("email", $email);
            $user->updateStripeId(null);

            $this->table->update($user->getId(), [
                'stripe_id' => json_encode($user->getStripeId(true))
            ]);
            return $user;

        } catch (NoRecordException $e){
            return null;
        }
    }
}
