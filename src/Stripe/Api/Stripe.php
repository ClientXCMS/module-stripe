<?php
namespace App\Stripe\Api;

use App\Shop\Entity\Transaction;
use App\Stripe\Api\Entity\StripeUser;
use Exception;
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

class Stripe
{
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

    public function __construct($privateKey, $publicKey, $endpointkey, LoggerInterface $logger)
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
        $client = $this->stripe->customers->create([
            'metadata' => [
                'user_id' => (string) $user->getId(),
            ],
            'email' => $user->getEmail(),
            'name' => $user->getName(),
        ]);
        $user->updateStripeId($client->id);

        return $client;
    }

    public function getCustomer(string $customerId): Customer
    {
        return $this->stripe->customers->retrieve($customerId);
    }


    public function checkConnection()
    {
        $this->stripe->customers->all(['limit' => 1]);
    }

    public function getInvoice(string $invoice): Invoice
    {
        return $this->stripe->invoices->retrieve($invoice);
    }

    public function getPaymentIntent(string $id): PaymentIntent
    {
        return $this->stripe->paymentIntents->retrieve($id);
    }

    public function createPaymentSession(StripeUser $user, $items, array $urls, Transaction $transaction): Session
    {
        try {

            $session = $this->stripe->checkout->sessions->create([
                //'customer_email' => $user->getEmail(),
                'cancel_url' => $urls['cancel'],
                'success_url' => $urls['return'],
                'mode' => 'payment',
                'payment_method_types' => [
                    'card',
                ],
                
                'metadata' => [
                    'transaction' => $transaction->getId(),
                    'user' => $user->getId()
                ],
                'customer' => $user->getStripeId(),
                'line_items' => $items,
            ]);
    
            return $session;
        } catch (Exception $e){
            dd($e->getMessage());
        }
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
            $webhook = Webhook::constructEvent($payload, $signature, $this->endpointkey, 0);
        } catch (\UnexpectedValueException | SignatureVerificationException $e) {
            $this->logger->error($e->getMessage());
            throw new \Exception($e->getMessage());
        }
        return $webhook;
    }

    
    private function setEndpointKey($endpointkey)
    {
        
        if ($endpointkey === null) {
            $this->logger->error("Endpoint key is null. Please add env STRIPE_ENDPOINT with your key");
            throw new \Exception("Internal error");
        }
        $this->endpointkey = $endpointkey;
    }

    private function setPublicKey($publicKey)
    {
        
        if ($publicKey === null) {
            $this->logger->error("public key is null. Please add env STRIPE_PUBLIC with your key.");
            throw new \Exception("Internal error");
        }
        $this->publicKey = $publicKey;
    }

    private function setPrivateKey($privateKey)
    {
        if ($privateKey === null) {
            $this->logger->error("private key is null. Please add env STRIPE_PRIVATE with your key.");
            throw new \Exception("Internal error");
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
