<?php
namespace App\Stripe\Api\Entity;

use App\Account\User;
use ClientX\Helpers\Str;

class StripeUser extends User
{

    private array $stripeId;



    public function getStripeId($all = false)
    {
        if ($all) {
            return $this->stripeId;
        }
        return $this->stripeId[$this->getEnvironment()] ?? null;
    }

    public function setStripeId($stripeId)
    {
        if ($stripeId != null){
            $json = json_decode($stripeId);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $stripeId = json_encode([
                    $this->getEnvironment() => $stripeId,
                    $this->getReversedEnvironment() => null,
                ]);
            }

            $this->stripeId = json_decode($stripeId, true);
        } else {
            $this->stripeId = [
            'test'=> null,
            'live' => null,
            ];
        }
    }

    public function updateStripeId($stripeId)
    {
        $this->stripeId[$this->getEnvironment()] = $stripeId;
    }

    private function getEnvironment(): string
    {
        return Str::startsWith($_ENV['STRIPE_SECRET'], 'sk_test') ? 'test' : 'live';
    }


    private function getReversedEnvironment(): string
    {
        return $this->getEnvironment() === 'live' ? 'test' : 'live';
    }
}
