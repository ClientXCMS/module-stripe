<?php
namespace App\Stripe;

use App\Account\User;
use App\Auth\Database\UserTable;
use App\Basket\Basket;
use App\Basket\BasketRow;
use App\Shop\Entity\Product;
use App\Stripe\Api\Entity\StripeUser;
use App\Stripe\Api\Stripe;
use ClientX\Database\NoRecordException;
use ClientX\Helpers\RequestHelper;
use ClientX\Payment\PaymentManagerInterface;
use ClientX\Renderer\RendererInterface;
use ClientX\Router;
use ClientX\Session\SessionInterface;
use Psr\Http\Message\ServerRequestInterface;

class StripePaymentManager implements PaymentManagerInterface {
    /**
     * @var Stripe
     */
    private $stripe;

    
    /**
     * @var string
     */
    private $currency = "EUR";

    private $isBasket;

    private $router;
    /**
     * @var Product
     */
    private $product;

    /**
     * @var BasketRow[]
     */
    private $products;

    /**
     * @var UserTable
     */
    private $table;
    /**
     * @var StripeUser
     */
    private $user;

    private $links;

    /**
     * @var RendererInterface
     */
    private $renderer;

    /**
     * @var SessionInterface
     */
    private $session;

    const SESSION_NAME = "stripe_invoice_id";

    public function __construct(Stripe $stripe, SessionInterface $session, Router $router, User $user, UserTable $table, RendererInterface $renderer)
    {
        $this->stripe = $stripe;
        $this->session = $session;
        $this->renderer = $renderer;
        $this->router = $router;
        $this->user   = $table->find($user->getId());
        $this->table  = $table;
    }

    public function process(ServerRequestInterface $request, $product = null, bool $isBasket = false, ?Basket $basket = null)
    {
        $this->request = $request;
        $this->isBasket = $isBasket;
        $this->basket = $basket;
        if (!$isBasket) {
            $this->product = $product;
        } else {
            $this->products = $product;
        }
        $user = $this->createStripeUser($this->user);
        $invoice = $request->getAttribute('invoice_id', null);
        $this->session->set(self::SESSION_NAME, $invoice);
        $session = $this->stripe->createPaymentSession($user, $this->makeItems(), $this->getRedirectUrls(), $invoice);
        return $this->renderer->render("@stripe_admin/autoredirect", ['session' => $session, 'key' => $this->stripe->getPublicKey()]);
    }


    private function createStripeUser(StripeUser $stripeUser)
    {
        try {
            $user = $this->table->findBy('stripe_id', $stripeUser->getStripeId()?? "");
            if ($user->getStripeId() === null || $user->getStripeId() === "") {
                throw new \ClientX\Database\NoRecordException();
            }
        } catch (NoRecordException $e){
            $stripeUser->setStripeId($this->stripe->createCustomer($stripeUser)->id);
            $this->table->update($stripeUser->getId(), [
                'stripe_id' => $stripeUser->getStripeId()
            ]);
        }
        return $stripeUser;
    }
    private function getUri(string $link)
    {
        if (!$this->getRedirectsLinks()) {
            throw $this->stripe->getLogger()->error('the redirects links is missing for generate uri');
        }
        if (!isset($this->getRedirectsLinks()[$link])) {
            throw $this->stripe->getLogger()->error(sprintf('The link array does not contain the key %s', $link));
        }
        return $this->getRedirectsLinks()[$link] ?? null;
    }
    private function getRedirectUrls():array
    {
        return [$this->getUri('return'), $this->getUri('cancel')];
    }

    private function getRedirectsLinks(?ServerRequestInterface $request = null):array
    {
        if ($this->links) {
            return $this->links;
        }
        $request = $request ?? $this->request;
        $domain = RequestHelper::getDomain($request);
        $isRenewal = false;
        if ($request) {
            $isRenewal = strpos($request->getUri()->getPath(), 'services') ==! false;
        }
        $id = $request->getAttribute('id');
        $type = $request->getParsedBody()['type'];
        $prefix = ($this->isBasket) ? 'basket' : 'shop';
        $prefix = ($isRenewal) ? 'shop.services.renew' : $prefix;
        $cancel = $domain . $this->router->generateURI("$prefix.cancel", compact('type', 'id'));
        $return = $domain . $this->router->generateURI("$prefix.return", compact('type', 'id'));
        $this->links = compact('return', 'cancel');
        return compact('return', 'cancel');
    }

    public function execute(ServerRequestInterface $request, $product = null, bool $isBasket = false, ?Basket $basket = null)
    {
        //$this->createStripeUser($this->user);
        if ($this->session->get(self::SESSION_NAME) == null){
            throw new \Exception('the invoice id is missing');
        }
        $invoiceId = $this->session->get(self::SESSION_NAME);
        return ['id' => $invoiceId, 'payment_id' => null];

    }

    private function makeItems():array{
        $items = [];
        if ($this->isBasket) {
            foreach ($this->products as $row) {
                $product = $row->getProduct();
                $amount = $product->getPrice();
                $name = $product->getName();
                $quantity = $row->getQuantity();
            }
        } else {
            $product = $this->product;
            $amount = $product->getPrice();
            $name = $product->getName();
            $quantity = 1;
        }

        $items[] = ['price_data' =>
                    [
                        'currency' => $this->currency,
                        'unit_amount' => $amount * 100,
                        'product_data' => ["name" => $name]
                    ],
                    'quantity' => $quantity,
                ];
                return $items;
    }

}