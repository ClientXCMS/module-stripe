<?php
namespace App\Stripe\Api;

use App\Stripe\Api\Entity\StripeUser;
use Psr\Log\LoggerInterface;
use Stripe\BalanceTransaction;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Invoice;
use Stripe\PaymentIntent;
use Stripe\Stripe as StripeStripe;
use Stripe\StripeClient;
use Stripe\Webhook;

class Stripe {
    /**
     * @var StripeClient
     */
    private $stripe;

    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var string
     */
    private $publicKey;

    /**
     * @var string
     */
    private $privateKey;

    /**
     * @var string
     */
    private $endpointkey;

    const STRIPE_VERSION = "2020-08-27";

    public function __construct($privateKey, $publicKey,$endpointkey, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->setPrivateKey($privateKey);
        $this->setPublicKey($publicKey);
        $this->setEndpointKey($endpointkey);
        $this->setStripeVersion();

        $this->stripe = new StripeClient($privateKey);
    }

    /**
     * Crée un customer stripe et sauvegarde l'id dans l'utilisateur.
     */
    public function createCustomer(StripeUser $user): Customer
    {
        if ($user->getStripeId()) {
            return $user;
        }
        $client = $this->stripe->customers->create([
            'metadata' => [
                'user_id' => (string) $user->getId(),
            ],
            'email' => $user->getEmail(),
            'name' => $user->getName(),
        ]);
        $user->setStripeId($client->id);

        return $client;
    }

    public function getCustomer(string $customerId): Customer
    {
        return $this->stripe->customers->retrieve($customerId);
    }

    public function getInvoice(string $invoice): Invoice
    {
        return $this->stripe->invoices->retrieve($invoice);
    }

    public function getPaymentIntent(string $id): PaymentIntent
    {
        return $this->stripe->paymentIntents->retrieve($id);
    }

    public function createPaymentSession(StripeUser $user, $items, array $urls, int $invoice): Session
    {
        $session = $this->stripe->checkout->sessions->create([
            //'customer_email' => $user->getEmail(),
            'cancel_url' => $urls[1],
            'success_url' => $urls[0],
            'mode' => 'payment',
            'payment_method_types' => [
                'card',
            ],
            
            'metadata' => [
                'invoice' => $invoice,
                'user' => $user->getId()
            ],
            'customer' => $user->getStripeId(),
            'line_items' => $items,
        ]);

        return $session;
    }

    public function getCheckoutSessionFromIntent(string $paymentIntent): Session
    {
        /** @var Session[] $sessions */
        $sessions = $this->stripe->checkout->sessions->all(['payment_intent' => $paymentIntent])->data;

        return $sessions[0];
    }

    public function getTransaction(string $id): BalanceTransaction
    {
        return $this->stripe->balanceTransactions->retrieve($id);
    }

    public function getLogger():LoggerInterface
    {
        return $this->logger;
    }

    public function getPublicKey():string
    {
        return $this->publicKey;
    }

    public function getWebhook(string $signature)
    {
        $payload = @file_get_contents('php://input');
        try {
            $webhook = Webhook::constructEvent($payload, $signature, $this->endpointkey);
        } catch(\UnexpectedValueException | SignatureVerificationException $e) {
            $this->logger->error($e->getMessage());
            throw new \Exception($e->getMessage());


        }
        return $webhook;
    }

    
    private function setEndpointKey($endpointkey)
    {
        
        if ($endpointkey === null) {
            $this->logger->error("La clée webhook stripe est nulle.");
            throw new \Exception("Erreur interne.");
        }
        $this->endpointkey = $endpointkey;
    }

    private function setPublicKey($publicKey)
    {
        
        if ($publicKey === null) {
            $this->logger->error("La clée publique stripe est nulle.");
            throw new \Exception("Erreur interne.");
        }
        $this->publicKey = $publicKey;
    }

    private function setPrivateKey($privateKey)
    {
        if ($privateKey === null) {
            $this->logger->error("La clée privée stripe est nulle.");
            throw new \Exception("Erreur interne.");
        }
        $this->privateKey = $privateKey;
        \Stripe\Stripe::setApiKey($privateKey);
        return $this;
    }

    protected function setStripeVersion()
    {
        StripeStripe::setApiVersion(self::STRIPE_VERSION);
    }

}