<?php
namespace App\Stripe\Actions;

use App\Auth\Database\UserTable;
use App\Shop\Entity\Transaction;
use App\Shop\Services\TransactionService;
use App\Stripe\Api\Entity\StripeUser;
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
        $response = $this->manager->confirm($request);
        
        return $this->json(['success' => $response instanceof Transaction || $response instanceof StripeUser, 'response' => $response]);
    }
}
