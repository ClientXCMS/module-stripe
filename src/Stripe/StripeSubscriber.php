<?php

namespace App\Stripe;

use App\Auth\Database\UserTable;
use App\Auth\User;
use App\Shop\Entity\IncomeSubscriptionDetails;
use App\Shop\Entity\Product;
use App\Shop\Entity\Recurring;
use App\Shop\Entity\Subscription;
use App\Shop\Entity\SubscriptionDetails;
use App\Shop\Entity\Transaction;
use App\Shop\Entity\TransactionItem;
use App\Shop\Payment\EntitySubscriberInterface;
use App\Shop\Payment\SubscriberEntityInterface;
use App\Shop\Services\SubscriptionService;
use App\Stripe\Api\Entity\StripeUser;
use App\Stripe\Api\Stripe;
use Carbon\Carbon;
use ClientX\Database\NoRecordException;
use ClientX\Renderer\RendererInterface;
use ClientX\Response\RedirectBackResponse;
use ClientX\Response\RedirectResponse;
use ClientX\Router;
use Psr\Http\Message\ResponseInterface;
use Stripe\PaymentMethod;
use function ClientX\request;

class StripeSubscriber implements \App\Shop\Payment\SubscribeInterface, EntitySubscriberInterface
{

    private Stripe $stripe;
    private UserTable $table;
    private RendererInterface $renderer;
    /**
     * @var \App\Shop\Services\SubscriptionService
     */
    private SubscriptionService $subscriptionService;
    private Router $router;

    public function __construct(
        Stripe $stripe,
        UserTable $table,
        RendererInterface $renderer,
        Router $router,
        SubscriptionService $subscriptionService
    ) {
        $this->stripe   = $stripe;
        $this->table    = $table;
        $this->renderer = $renderer;
        $this->subscriptionService = $subscriptionService;
        $this->router = $router;
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

        $items = [[
            [
                'price_data' =>
                    [
                        'currency' => $transaction->getCurrency(),
                        'recurring' => [
                            'interval_count' => $months,
                            'interval'  => 'month'
                        ],
                        'unit_amount' => number_format($product->getPrice(Recurring::from(json_decode($product->getData(), true)['_recurring'])->getName()) + $discounts, 2) * 100,
                        'product_data' => ["name" => $product->getName()]
                    ],
                'quantity' => 1,
            ]
        ]];

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
        $details = new SubscriptionDetails();
        $details->setId($id);
        $customer = $this->stripe->getStripe()->customers->retrieve($subscription->customer);
        if (isset($customer->invoice_settings->default_payment_method)) {
            $defaultPaymentMethodId = $customer->invoice_settings->default_payment_method;
            $paymentMethods = $customer->payment_methods;
            $paymentMethods = $this->stripe->getStripe()->paymentMethods->all(['customer' => $subscription->customer]);
            foreach ($paymentMethods as $paymentMethod) {
                if ($paymentMethod->id === $defaultPaymentMethodId) {
                    $details->setLast4($paymentMethod->card->last4);
                }
            }
        }
        $details->setType('stripe');
        $details->setToken($token);
        $details->setState($subscription->status == 'trialing' ? 'active' : $subscription->status);
        $details->setPrice($subscription->items->data[0]->plan->amount_decimal / 100);
        $details->setStartDate((new \DateTime())->setTimestamp($subscription->created));
        $details->setNextRenewal((new \DateTime())->setTimestamp($subscription->current_period_end));
        $details->setEmailPayer($customer->email);
        return $details;
    }

    public function fetchLastTransactionId(string $token, string $last): ?string
    {
        $data = $this->stripe->getInvoices($token)->lines->data;
        if ($data == null){
            return null;
        }
        if ($last == current($data)->id){
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

    public function getDetailsLink(string $token): string
    {
        return "https://dashboard.stripe.com/test/subscriptions/$token";
    }

    public function estimatedIncomeSubscription(int $months = 0): IncomeSubscriptionDetails
    {

        $income = (new IncomeSubscriptionDetails())->setType($this->type());
        $subscriptions = $this->subscriptionService->getSubscriptionForType('stripe');
        $currentMonth = Carbon::now()->addMonths($months)->format('m');
        foreach ($subscriptions as $subscription){
            $details = $this->stripe->getSubscription($subscription->token);
            if ($details->status == 'active' && Carbon::createFromTimestamp($details->current_period_end)->format('m') == $currentMonth){
                $income->addAmount($details->items->data[0]->plan->amount_decimal / 100);
            }

            $income->addNextRenewal($subscription->token,Carbon::createFromTimestamp($details->current_period_end)->toDate());
            $income->addInterval($subscription->token,$details->items->data[0]->plan->interval_count);
        }
        return $income;
    }

    public function addSubscriptionToEntity(SubscriberEntityInterface $entitySubscriber, Transaction $transaction)
    {


        $user = $this->createStripeUser($transaction->getUser());
        $cards = $this->stripe->getStripe()->paymentMethods->all(['customer' => $user->getStripeId(), 'type' => 'card']);
        if (!empty($cards)){
            $this->createSubscription($entitySubscriber, $transaction);
            return new RedirectBackResponse(request());
        }

        $billingPortalSession = \Stripe\BillingPortal\Session::create([
            'customer' => $user->getStripeId(),
            'return_url' => $this->router->generateURIAbsolute('stripe.portail', ['id' => $entitySubscriber->getId()]), // URL de retour après la gestion de l'abonnement
        ]);

        $billingPortalUrl = $billingPortalSession->url;
        return new RedirectResponse($billingPortalUrl);
    }

    public function createSubscription(SubscriberEntityInterface $subscriber, Transaction $transaction)
    {
        if ($this->subscriptionService->entityHasSubscription($subscriber)){
           return true;
        }
        $user = $this->createStripeUser($transaction->getUser());
        $items = [[
            [
                'price_data' =>
                    [
                        'currency' => $transaction->getCurrency(),
                        'recurring' => [
                            'interval_count' => $subscriber->toRecurring()->getMonths(),
                            'interval'  => 'month'
                        ],
                        'unit_amount' => number_format($subscriber->getSubscriptionPrice(), 2) * 100,
                        'product' => $this->getStripeProductFromEntity($subscriber)
                    ],
                'quantity' => 1,
            ]
        ]];
        $data = [
            'customer' => $user->getStripeId(),
            'items' => $items,
            'trial_end' => $subscriber->getNextExpiration()->format('U'),
            'metadata' => [
                'transaction' => $transaction->getId(),
                'user' => $user->getId()
            ],
        ];

        $object = $this->stripe->getStripe()->subscriptions->create($data);

        if ($object->status == "trialing") {
            $paymentId = $this->stripe->getInvoices($object->id)->data[0]->id;
            $id = $this->subscriptionService->addSubscription($user, $transaction, $object->id, 'stripe', $paymentId);
            $this->subscriptionService->addSubscriptionToEntity($subscriber, (new Subscription())->setId($id));
        }
        return true;
    }

    public function updateRecurring(SubscriberEntityInterface $entitySubscriber, Subscription $subscription, Recurring $recurring)
    {
        // TODO: Implement updateRecurring() method.
    }

    public function updatePrice(SubscriberEntityInterface $entitySubscriber, Subscription $subscription, float $price)
    {
        // TODO: Implement updatePrice() method.
    }

    private function getStripeProductFromEntity(SubscriberEntityInterface $entitySubscriber)
    {
        try {
            $products = \Stripe\Product::all(["limit" => 100]);
            foreach ($products as $product) {
                if (isset($product->metadata->entity,$product->metadata->type ) && $product->metadata->entity === $entitySubscriber->getId()  && $product->metadata->type === $entitySubscriber->getTable()) {
                    return $product->id;
                }
            }

            $product = \Stripe\Product::create([
                'name' => $entitySubscriber->getName(),
                'type' => 'service',
                'metadata' => ['entity' => $entitySubscriber->getId(), 'type' => $entitySubscriber->getTable()],
            ]);
            return $product->id; // Aucun produit trouvé avec les métadonnées spécifiées
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Gérer les erreurs d'API Stripe
            echo "Une erreur s'est produite : " . $e->getMessage();
            return false;
        }
    }

    public function redirectSubcription(SubscriberEntityInterface $entitySubscriber, Transaction $transaction): ResponseInterface
    {

        $user = $this->createStripeUser($transaction->getUser());
        $billingPortalSession = \Stripe\BillingPortal\Session::create([
            'customer' => $user->getStripeId(),
            'return_url' => $this->router->generateURIAbsolute('shop.services.renew', ['id' => $entitySubscriber->getId()]), // URL de retour après la gestion de l'abonnement
        ]);
        $billingPortalUrl = $billingPortalSession->url;
        return new RedirectResponse($billingPortalUrl);
    }
}
