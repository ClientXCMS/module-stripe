<?php
namespace App\Stripe\Api\Entity;

use App\Account\User;

class StripeUser extends User
{

    /**
     * @var string
     */
    private $stripeId;

    public function getStripeId():?string
    {
        return $this->stripeId;
    }

    public function setStripeId($stripeId)
    {
        $this->stripeId = $stripeId;
    }
}
