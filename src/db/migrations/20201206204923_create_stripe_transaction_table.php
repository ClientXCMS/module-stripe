<?php

use Phinx\Migration\AbstractMigration;

class CreateStripeTransactionTable extends AbstractMigration
{
    public function change()
    {
            $this->table("stripe_transactions")
            ->addColumn('payment_id', 'string')
            ->addColumn('user_id', 'integer')
            ->addColumn('payer_id', 'string')
            ->addColumn('payer_email', 'string')
            ->addColumn('total', 'float', ['precision' => 6, 'scale' => 2])
            ->addColumn('subtotal', 'float', ['precision' => 6, 'scale' => 2])
            ->addColumn('tax', 'float', ['precision' => 6, 'scale' => 2])
            ->addForeignKey('user_id', 'users', 'id')
            ->addTimestamps()
            ->create();
    }
}
