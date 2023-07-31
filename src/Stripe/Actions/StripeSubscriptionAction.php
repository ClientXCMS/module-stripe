<?php

namespace App\Stripe\Actions;

use App\Auth\DatabaseUserAuth;
use App\Shop\Services\ServiceService;
use App\Shop\Services\TransactionService;
use App\Stripe\StripeSubscriber;
use ClientX\Actions\Action;
use ClientX\Router;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;

class StripeSubscriptionAction extends Action
{

    private StripeSubscriber $subscriber;
    private TransactionService $transactionService;
    private ServiceService $service;

    public function __construct(StripeSubscriber $subscriber, Router $router, TransactionService $transactionService,ServiceService $service, DatabaseUserAuth $auth)
    {
        $this->subscriber = $subscriber;
        $this->transactionService = $transactionService;
        $this->auth = $auth;
        $this->service = $service;
        $this->router = $router;
    }

    public function __invoke(ServerRequestInterface $request)
    {
        $service = $this->service->findService($request->getAttribute('id'), $this->getUserId());
        if ($service == null){
            return new Response(404);
        }

        $item = $this->transactionService->findTransactionsForService($service)->first();
        $transaction = $this->transactionService->findTransaction($item->transactionId);
        $this->subscriber->createSubscription($service, $transaction);
        return $this->redirectToRoute('shop.services.renew', ['id' => $service->getId()]);
    }
}