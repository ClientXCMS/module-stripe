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
        $items = collect($transaction->getItems())->map(function (TransactionItem $item) use ($transaction) {
            return [
                [
                    'price_data' =>
                    [
                        'currency' => $transaction->getCurrency(),
                        'unit_amount' => $item->priceWithTax() * 100,
                        'product_data' => ["name" => $item->getName()]
                    ],
                    'quantity' => $item->getQuantity(),
                ]
            ];
        })->toArray();

        $user = $this->createStripeUser($this->auth->getUser());
        $session = $this->stripe->createPaymentSession($user, $items, $this->getRedirectsLinks($request, $transaction), $transaction);
        $params = ['session' => $session, 'key' => $this->stripe->getPublicKey()];
        return $this->renderer->render("@stripe_admin/autoredirect", $params);
    }

    public function refund(array $items): bool
    {
        // TODO : Implemente
        return false;
    }


    private function createStripeUser(StripeUser $stripeUser)
    {
        try {
            /** @var StripeUser */
            $user = $this->table->findBy('stripe_id', $stripeUser->getStripeId() ?? "");
            $stripeUser->setStripeId($user->getStripeId());
        } catch (NoRecordException $e) {
            $this->stripe->createCustomer($stripeUser);
            $this->table->update($stripeUser->getId(), [
                'stripe_id' => $stripeUser->getStripeId()
            ]);
        }
        return $stripeUser;
    }
    public function execute(Transaction $transaction, Request $request, User $user)
    {

    }

    public function test(Transaction $transaction, Request $request, User $user){
        $params = $request->getServerParams();
        $signature = $params["HTTP_STRIPE_SIGNATURE"];
        $webhook = $this->stripe->getWebhook($signature);
        if ($webhook->type === 'checkout.session.completed') {
            $object = $webhook->data->object;
            $id = $object->id;
            $transaction->setTransactionId($id);
            $this->service->updateTransactionId($transaction);

            if ($object->payment_status !== "paid") {
                $transaction->setState($transaction::REFUSED);
                $transaction->setReason("Stripe error");
                $this->service->changeState($transaction);
                $this->service->setReason($transaction);
            } else {
    
                if ($this->service->isOrder($transaction)) {
                    $this->service->confirmOrder($transaction, $user->getId());
                }
                $transaction->setState($transaction::COMPLETED);
                $this->service->changeState($transaction);
                return $transaction;
            }
        } else if ($webhook->type === 'payment_intent.succeeded') {
            
            $object = $webhook->data->object;
            $id = $webhook->id;
            $transaction->setTransactionId($id);
            $this->service->updateTransactionId($transaction);
            return $transaction;

        }
    }

    public function confirm(Request $request)
    {
        $params = $request->getServerParams();
        $signature = $params["HTTP_STRIPE_SIGNATURE"];
        $webhook = $this->stripe->getWebhook($signature);
        if ($webhook->type === 'payment_intent.succeeded') {
            $transaction = $this->service->getLastTransaction();
            
            $object = $webhook->data->object;
            $id = $object->id;
            $transaction->setTransactionId($id);
            $this->service->updateTransactionId($transaction);
        }
    }
    
    public function getWebhook(string $signature)
    {
        return $this->stripe->getWebhook($signature);
    }
}
