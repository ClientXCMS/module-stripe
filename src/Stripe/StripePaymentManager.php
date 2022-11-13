<?php

namespace App\Stripe;

use App\Account\User;
use App\Auth\Database\UserTable;
use App\Shop\Entity\Transaction;
use App\Shop\Entity\TransactionItem;
use App\Shop\Payment\AbstractPaymentManager;
use App\Shop\Services\TransactionService;
use App\Stripe\Api\Entity\StripeUser;
use App\Stripe\Api\Stripe;
use ClientX\Auth;
use ClientX\Database\NoRecordException;
use ClientX\Payment\PaymentManagerInterface;
use ClientX\Renderer\RendererInterface;
use ClientX\Router;
use Stripe\Checkout\Session;
use Psr\Http\Message\ServerRequestInterface as Request;

class StripePaymentManager extends AbstractPaymentManager implements PaymentManagerInterface
{
    /**
     * @var Stripe
     */
    private Stripe $stripe;
    private UserTable $table;
    private RendererInterface $renderer;

    public function __construct(
        Router $router,
        Auth $auth,
        TransactionService $service,
        Stripe $stripe,
        UserTable $table,
        RendererInterface $renderer
    ) {
        parent::__construct($router, $auth, $service);
        $this->stripe   = $stripe;
        $this->table    = $table;
        $this->renderer = $renderer;
    }

    public function process(Transaction $transaction, Request $request, User $user)
    {
        $user = $this->getUser();
        if ($user === null) {
            return;
        }
        
        $items = collect($transaction->getItems())->filter(function($item) { return $item->price() > 0;})->map(function (TransactionItem $item, $i) use ($transaction) {
            $discount = 0;
            $next = $transaction->getItems()[$i+1] ?? null;
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
                        'unit_amount' => number_format($item->priceWithTax() + $discount, 2) * 100,
                        'product_data' => ["name" => $item->getName()]
                    ],
                    'quantity' => $item->getQuantity(),
                ];
        })->toArray();
		//dd($items);
        
        $user = $this->createStripeUser($this->auth->getUser());
        $session = $this->stripe->createPaymentSession($user, $items, $this->getRedirectsLinks($request, $transaction), $transaction);
        if ($session instanceof Session){
            $params = ['session' => $session, 'key' => $this->stripe->getPublicKey()];
            return $this->renderer->render("@stripe_admin/autoredirect", $params);
        } else {
            $this->table->update($session->getId(), [
                'stripe_id' => json_encode($session->getStripeId(true))
            ]);
            $params = ['session' => $session, 'key' => $this->stripe->getPublicKey()];
            return $this->renderer->render("@stripe_admin/autoredirect", $params);
        }
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
    public function execute(Transaction $transaction, Request $request, User $user)
    {
    }

    /**
     * @throws \Exception
     */
    public function confirm(Request $request)
    {
        $params = $request->getServerParams();
        $signature = $params["HTTP_STRIPE_SIGNATURE"];
        $webhook = $this->stripe->getWebhook($signature);
        if ($webhook->type != 'checkout.session.completed') {
            return null;
        }
        if (isset($webhook->data->object)) {
            $object = $webhook->data->object;
            $id = $object->metadata->transaction;
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
                foreach ($transaction->getItems() as $item){
                    $this->service->delivre($item);
                }
                $this->service->changeState($transaction);
                return $transaction;
            }
        }
    }

    
    public function getWebhook(string $signature)
    {
        return $this->stripe->getWebhook($signature);
    }
}
