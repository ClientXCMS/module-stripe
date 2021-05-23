<?php
namespace App\Stripe\Actions;

use App\Auth\Database\UserTable;
use App\Shop\Entity\Transaction;
use App\Shop\Services\TransactionService;
use App\Stripe\StripePaymentManager;
use ClientX\Actions\Action;
use Psr\Http\Message\ServerRequestInterface;

class StripeApiAction extends Action
{
    private UserTable $user;
    private StripePaymentManager $manager;
    private TransactionService $transaction;
    public function __construct(StripePaymentManager $manager, TransactionService $transaction, UserTable $user)
    {
        $this->manager = $manager;
        $this->transaction = $transaction;
        $this->user = $user;
    }

    public function __invoke(ServerRequestInterface $request)
    {
        $webhook = $this->manager->getWebhook($request->getServerParams()['HTTP_STRIPE_SIGNATURE']);
        $object = $webhook->data->object;
        if (empty($object->metadata) === false){
            
            $response = $this->manager->confirm($request);
            return $this->json(['success' => $response instanceof Transaction]);
        }
        $id = $object->metadata->transaction;

        $userId = $object->metadata->user;
        
        $user = $this->user->find($userId);
        $transaction = $this->transaction->findTransaction($id);
        if ($transaction != null && $transaction->getState() === $transaction::PENDING) {
            $response = $this->manager->test($transaction, $request, $user);
            return $this->json(['success' => $response instanceof Transaction]);
        }
        return $this->json(['error' => true]);

    }
}
