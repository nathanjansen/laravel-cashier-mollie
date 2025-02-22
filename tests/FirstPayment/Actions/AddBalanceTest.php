<?php

namespace Laravel\Cashier\Tests\FirstPayment\Actions;

use Laravel\Cashier\Cashier;
use Laravel\Cashier\FirstPayment\Actions\AddBalance;
use Laravel\Cashier\Order\OrderItemCollection;
use Laravel\Cashier\Tests\BaseTestCase;
use Laravel\Cashier\Tests\Fixtures\User;

class AddBalanceTest extends BaseTestCase
{
    /** @test */
    public function canGetPayload()
    {
        $this->withPackageMigrations();
        $action = new AddBalance(
            $this->getMandatedUser(),
            mollie_money(1000, 'EUR'),
            1,
            'Adding some test balance'
        );

        $payload = $action->getPayload();

        $this->assertEquals([
            'handler' => AddBalance::class,
            'unit_price' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            'taxPercentage' => 0,
            'description' => 'Adding some test balance',
            'quantity' => 1,
        ], $payload);
    }

    /** @test */
    public function canCreateFromPayload()
    {
        $action = AddBalance::createFromPayload([
            'subtotal' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            'taxPercentage' => 0,
            'description' => 'Adding some test balance',
        ], factory(User::class)->make());

        $this->assertInstanceOf(AddBalance::class, $action);

        $this->assertMoneyEURCents(1000, $action->getTotal());
        $this->assertMoneyEURCents(1000, $action->getSubtotal());
        $this->assertMoneyEURCents(0, $action->getTax());
        $this->assertEquals(0, $action->getTaxPercentage());
    }

    /** @test */
    public function canExecute()
    {
        $this->withPackageMigrations();
        $user = factory(User::class)->create();
        $this->assertFalse($user->hasCredit());

        $action = new AddBalance(
            $user,
            mollie_money(1000, 'EUR'),
            1,
            'Adding some test balance'
        );

        $items = $action->execute();
        $item = $items->first();

        $credit = $user->credit('EUR');
        $this->assertEquals(1000, $credit->value);
        $this->assertEquals('EUR', $credit->currency);

        $this->assertInstanceOf(OrderItemCollection::class, $items);
        $this->assertCount(1, $items);
        $this->assertInstanceOf(Cashier::$orderItemModel, $item);
        $this->assertEquals('Adding some test balance', $item->description);

        $this->assertEquals('EUR', $item->currency);
        $this->assertEquals(1000, $item->unit_price);
        $this->assertEquals(1, $item->quantity);
        $this->assertEquals(0, $item->tax_percentage);
        $this->assertNotNull($item->id); // item is persisted
        $this->assertFalse($item->isProcessed());

        // Assert that the added balance is persisted
        $this->assertTrue($user->fresh()->hasCredit());
    }
}
