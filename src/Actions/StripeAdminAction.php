<?php
namespace App\Stripe\Actions;

use ClientX\Actions\Payment\PaymentAdminAction;

class StripeAdminAction extends PaymentAdminAction
{

    protected $routePrefix = "stripe.admin";
    protected $moduleName = "Stripe";
    protected $paymenttype = "stripe";
}
