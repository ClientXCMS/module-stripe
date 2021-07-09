<?php
namespace App\Stripe;

use ClientX\Payment\PaymentTypeInterface;

class StripePaymentType implements PaymentTypeInterface
{

    public function getName(): string
    {
        return "stripe";
    }

    public function getTitle(): ?string
    {
        return "Stripe (Blue Card)";
    }

    public function getManager(): string
    {
        return StripePaymentManager::class;
    }

    public function getIcon(): string
    {
        return "fab fa-stripe-s";
    }

    public function getLogPath(): string
    {
        return "stripe.admin";
    }

    public function canPayWith(): bool
    {
        return true;
    }
}
