<?php

use Phinx\Migration\AbstractMigration;

class AddStripeIdToUser extends AbstractMigration
{
    public function change()
    {
        $table = $this->table("users");
        if (!$table->hasColumn('stripe_id')) {
            $table->addColumn("stripe_id", "string", ['null' => true]);
            $table->addIndex("stripe_id", ['unique' => true]);
        }
        $table->save();
    }
}
