<?php

namespace Laravel\Cashier\Tests;

use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Coupon\AppliedCoupon as CashierAppliedCoupon;
use Laravel\Cashier\Coupon\RedeemedCoupon as CashierRedeemedCoupon;
use Laravel\Cashier\Credit\Credit as CashierCredit;
use Laravel\Cashier\Mollie\Contracts\CreateMolliePayment;
use Laravel\Cashier\Mollie\Contracts\GetMollieMandate;
use Laravel\Cashier\Mollie\Contracts\GetMollieMethodMaximumAmount;
use Laravel\Cashier\Mollie\Contracts\GetMollieMethodMinimumAmount;
use Laravel\Cashier\Mollie\GetMollieCustomer;
use Laravel\Cashier\Order\Order as CashierOrder;
use Laravel\Cashier\Order\OrderItem as CashierOrderItem;
use Laravel\Cashier\Payment as CashierPayment;
use Laravel\Cashier\Refunds\Refund as CashierRefund;
use Laravel\Cashier\Refunds\RefundItem as CashierRefundItem;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\Tests\Fixtures\AppliedCoupon as FixtureAppliedCoupon;
use Laravel\Cashier\Tests\Fixtures\Credit as FixtureCredit;
use Laravel\Cashier\Tests\Fixtures\Order as FixtureOrder;
use Laravel\Cashier\Tests\Fixtures\OrderItem as FixtureOrderItem;
use Laravel\Cashier\Tests\Fixtures\Payment as FixturePayment;
use Laravel\Cashier\Tests\Fixtures\RedeemedCoupon as FixtureRedeemedCoupon;
use Laravel\Cashier\Tests\Fixtures\Refund as FixtureRefund;
use Laravel\Cashier\Tests\Fixtures\RefundItem as FixtureRefundItem;
use Laravel\Cashier\Tests\Fixtures\Subscription as FixtureSubscription;
use Laravel\Cashier\Tests\Fixtures\User;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Customer;
use Mollie\Api\Resources\Mandate;
use Mollie\Api\Resources\Payment;

class CashierTest extends BaseTestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withPackageMigrations();
        $this->withConfiguredPlans();
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        Cashier::useCurrencyLocale('de_DE');
        Cashier::useCurrency('eur');
    }

    /** @test */
    public function cashierUsesPredefinedModels()
    {
        $this->assertEquals(Cashier::$subscriptionModel, CashierSubscription::class);
        $this->assertEquals(Cashier::$orderModel, CashierOrder::class);
        $this->assertEquals(Cashier::$orderItemModel, CashierOrderItem::class);
        $this->assertEquals(Cashier::$appliedCouponModel, CashierAppliedCoupon::class);
        $this->assertEquals(Cashier::$redeemedCouponModel, CashierRedeemedCoupon::class);
        $this->assertEquals(Cashier::$creditModel, CashierCredit::class);
        $this->assertEquals(Cashier::$paymentModel, CashierPayment::class);
        $this->assertEquals(Cashier::$refundModel, CashierRefund::class);
        $this->assertEquals(Cashier::$refundItemModel, CashierRefundItem::class);
    }

    /** @test */
    public function cashierUsesConfiguredModels()
    {
        Cashier::useSubscriptionModel(FixtureSubscription::class);
        Cashier::useOrderModel(FixtureOrder::class);
        Cashier::useOrderItemModel(FixtureOrderItem::class);
        Cashier::useAppliedCouponModel(FixtureAppliedCoupon::class);
        Cashier::useRedeemedCouponModel(FixtureRedeemedCoupon::class);
        Cashier::useCreditModel(FixtureCredit::class);
        Cashier::usePaymentModel(FixturePayment::class);
        Cashier::useRefundModel(FixtureRefund::class);
        Cashier::useRefundItemModel(FixtureRefundItem::class);

        $this->assertEquals(Cashier::$subscriptionModel, FixtureSubscription::class);
        $this->assertEquals(Cashier::$orderModel, FixtureOrder::class);
        $this->assertEquals(Cashier::$orderItemModel, FixtureOrderItem::class);
        $this->assertEquals(Cashier::$appliedCouponModel, FixtureAppliedCoupon::class);
        $this->assertEquals(Cashier::$redeemedCouponModel, FixtureRedeemedCoupon::class);
        $this->assertEquals(Cashier::$creditModel, FixtureCredit::class);
        $this->assertEquals(Cashier::$paymentModel, FixturePayment::class);
        $this->assertEquals(Cashier::$refundModel, FixtureRefund::class);
        $this->assertEquals(Cashier::$refundItemModel, FixtureRefundItem::class);
    }

    /** @test */
    public function testRunningCashierProcessesOpenOrderItems()
    {
        $this->withMockedGetMollieCustomer();
        $this->withMockedGetMollieMandate();
        $this->withMockedGetMollieMethodMinimumAmount();
        $this->withMockedGetMollieMethodMaximumAmount();
        $this->withMockedCreateMolliePayment();

        $user = $this->getMandatedUser(true, [
            'id' => 1,
            'mollie_customer_id' => 'cst_unique_customer_id',
            'mollie_mandate_id' => 'mdt_unique_mandate_id',
        ]);

        $user->orderItems()->save(factory(Cashier::$orderItemModel)->states('unlinked', 'processed')->make());
        $user->orderItems()->save(factory(Cashier::$orderItemModel)->states('unlinked', 'unprocessed')->make());

        $this->assertEquals(0, $user->orders()->count());
        $this->assertOrderItemCounts($user, 1, 1);

        Cashier::run();

        $this->assertEquals(1, $user->orders()->count());
        $this->assertOrderItemCounts($user, 2, 0);
    }

    /** @test */
    public function testRunningCashierProcessesUnprocessedOrderItemsAndSchedulesNext()
    {
        $this->withMockedGetMollieCustomer([
            'cst_unique_customer_id_1',
            'cst_unique_customer_id_2',
        ]);
        $this->withMockedGetMollieMandate([
            [
                'customerId' => 'cst_unique_customer_id_1',
                'mandateId' => 'mdt_unique_mandate_id_1',
            ],
            [
                'customerId' => 'cst_unique_customer_id_2',
                'mandateId' => 'mdt_unique_mandate_id_2',
            ],
        ]);
        $this->withMockedGetMollieMethodMinimumAmount(2);
        $this->withMockedGetMollieMethodMaximumAmount(2);
        $this->withMockedCreateMolliePayment(2);

        $user1 = $this->getMandatedUser(true, [
            'id' => 1,
            'mollie_customer_id' => 'cst_unique_customer_id_1',
            'mollie_mandate_id' => 'mdt_unique_mandate_id_1',
        ]);

        $user2 = $this->getMandatedUser(true, [
            'id' => 2,
            'mollie_customer_id' => 'cst_unique_customer_id_2',
            'mollie_mandate_id' => 'mdt_unique_mandate_id_2',
        ]);

        $subscription1 = $user1->subscriptions()->save(factory(Cashier::$subscriptionModel)->make());
        $subscription2 = $user2->subscriptions()->save(factory(Cashier::$subscriptionModel)->make());

        $subscription1->orderItems()->save(
            factory(Cashier::$orderItemModel)->states(['unprocessed', 'EUR'])->make([
                'owner_id' => 1,
                'owner_type' => User::class,
                'process_at' => now()->addHour(),
            ]) // should NOT process this (future)
        );

        $subscription1->orderItems()->saveMany(
            factory(Cashier::$orderItemModel, 2)->states(['unprocessed', 'EUR'])->make([
                'owner_id' => 1,
                'owner_type' => User::class,
                'process_at' => now()->subHour(),
            ])
        ); // should process these two

        $subscription1->orderItems()->save(
            factory(Cashier::$orderItemModel)->states('processed')->make()
        ); // should NOT process this (already processed)

        $subscription2->orderItems()->save(
            factory(Cashier::$orderItemModel)->states('unprocessed')->make([
                'owner_id' => 2,
                'owner_type' => User::class,
                'process_at' => now()->subHours(2),
            ])
        ); // should process this one

        $this->assertEquals(0, Cashier::$orderModel::count());
        $this->assertOrderItemCounts($user1, 1, 3);
        $this->assertOrderItemCounts($user2, 0, 1);

        Cashier::run();

        $this->assertEquals(1, $user1->orders()->count());
        $this->assertEquals(1, $user2->orders()->count());
        $this->assertOrderItemCounts($user1, 3, 3); // processed 3, scheduled 3
        $this->assertOrderItemCounts($user2, 1, 1); // processed 1, scheduled 1
    }

    /** @test */
    public function canSwapSubscriptionPlan()
    {
        $this->withTestNow('2019-01-01');
        $user = $this->getMandatedUser(true, [
            'id' => 1,
            'mollie_customer_id' => 'cst_unique_customer_id',
            'mollie_mandate_id' => 'mdt_unique_mandate_id',
        ]);

        $this->withMockedGetMollieCustomer(['cst_unique_customer_id'], 7);
        $this->withMockedGetMollieMandate([[
            'mandateId' => 'mdt_unique_mandate_id',
            'customerId' => 'cst_unique_customer_id',
        ]], 7);
        $this->withMockedGetMollieMethodMinimumAmount(2);
        $this->withMockedGetMollieMethodMaximumAmount(2);
        $this->withMockedCreateMolliePayment(2);

        $subscription = $user->newSubscription('default', 'monthly-20-1')->create();

        $this->assertOrderItemCounts($user, 0, 1);

        Cashier::run();

        $subscription = $subscription->fresh();
        $this->assertEquals(1, $user->orders()->count());
        $this->assertOrderItemCounts($user, 1, 1);
        $processedOrderItem = $user->orderItems()->processed()->first();
        $scheduledOrderItem = $subscription->scheduledOrderItem;

        // Downgrade after two weeks
        $this->withTestNow(now()->copy()->addWeeks(2));
        $subscription = $subscription->swap('monthly-10-1');

        $this->assertEquals('monthly-10-1', $subscription->plan);

        // Swapping results in a new Order being created
        $this->assertEquals(2, $user->orders()->count());

        // Added one processed OrderItem for crediting surplus
        // Added one processed OrderItem for starting the new subscription cycle
        // Removed one unprocessed OrderItem for previous plan
        // Added one unprocessed OrderItem for scheduling next subscription cycle
        $this->assertOrderItemCounts($user, 3, 1);

        $this->assertNull($scheduledOrderItem->fresh());
        $this->assertNotNull($processedOrderItem->fresh());

        // Fast-forward eight days
        $this->withTestNow(now()->addMonth());

        Cashier::run();

        // Assert that an Order for this month was created
        $this->assertEquals(3, $user->orders()->count());

        // Processed one unprocessed OrderItem
        // Scheduled one unprocessed OrderItem for next billing cycle
        $this->assertOrderItemCounts($user, 4, 1);
    }

    /** @test */
    public function canSwapSubscriptionPlanAndReimburseUnusedTime()
    {
        $this->withTestNow('2019-01-01');
        $user = $this->getMandatedUser(true, [
            'id' => 1,
            'mollie_customer_id' => 'cst_unique_customer_id',
            'mollie_mandate_id' => 'mdt_unique_mandate_id',
        ]);

        $this->withMockedGetMollieCustomer(['cst_unique_customer_id'], 7);
        $this->withMockedGetMollieMandate([[
            'mandateId' => 'mdt_unique_mandate_id',
            'customerId' => 'cst_unique_customer_id',
        ]], 7);
        $this->withMockedGetMollieMethodMinimumAmount(2);
        $this->withMockedCreateMolliePayment(2);
        $this->withMockedGetMollieMethodMaximumAmount(2);
        $subscription = $user->newSubscription('default', 'monthly-10-1')->create();

        $this->assertOrderItemCounts($user, 0, 1);

        Cashier::run();

        $subscription = $subscription->fresh();
        $this->assertEquals(1, $user->orders()->count());

        $this->assertOrderItemCounts($user, 1, 1);
        $processedOrderItem = $user->orderItems()->processed()->first();
        $scheduledOrderItem = $subscription->scheduledOrderItem;

        $this->withTestNow(now()->copy()->addMinutes(1));
        $subscription = $subscription->swap('monthly-20-1');

        $this->assertEquals('monthly-20-1', $subscription->plan);
        // Swapping results in a new Order being created
        $this->assertEquals(2, $user->orders()->count());
        $this->assertEquals(1000, $user->orders()->latest()->first()->total);
    }

    /** @test */
    public function testFormatAmount()
    {
        $this->assertEquals('1.000,00 €', Cashier::formatAmount(mollie_money(100000, 'EUR')));
        $this->assertEquals('-9.123,45 €', Cashier::formatAmount(mollie_money(-912345, 'EUR')));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Model  $user
     * @param  int  $processed
     * @param  int  $unprocessed
     */
    protected function assertOrderItemCounts(Model $user, int $processed, int $unprocessed)
    {
        $this->assertEquals(
            $processed,
            $user->orderItems()->processed()->count(),
            'Unexpected amount of processed orderItems.'
        );
        $this->assertEquals(
            $unprocessed,
            $user->orderItems()->unprocessed()->count(),
            'Unexpected amount of unprocessed orderItems.'
        );
        $this->assertEquals(
            $processed + $unprocessed,
            $user->orderItems()->count(),
            'Unexpected total amount of orderItems.'
        );
    }

    /** @test */
    public function canOverrideDefaultCurrencySymbol()
    {
        $this->assertEquals('€', Cashier::usesCurrencySymbol());
        $this->assertEquals('eur', Cashier::usesCurrency());

        Cashier::useCurrency('usd');

        $this->assertEquals('usd', Cashier::usesCurrency());
        $this->assertEquals('$', Cashier::usesCurrencySymbol());
    }

    /** @test */
    public function canOverrideDefaultCurrencyLocale()
    {
        $this->assertEquals('de_DE', Cashier::usesCurrencyLocale());

        Cashier::useCurrencyLocale('nl_NL');

        $this->assertEquals('nl_NL', Cashier::usesCurrencyLocale());
    }

    /** @test */
    public function canOverrideFirstPaymentWebhookUrl()
    {
        $this->assertEquals('mandate-webhook', Cashier::firstPaymentWebhookUrl());

        config(['cashier.first_payment.webhook_url' => 'https://www.example.com/webhook/mollie']);

        $this->assertEquals('webhook/mollie', Cashier::firstPaymentWebhookUrl());

        config(['cashier.first_payment.webhook_url' => 'webhook/cashier']);

        $this->assertEquals('webhook/cashier', Cashier::firstPaymentWebhookUrl());
    }

    /** @test */
    public function canOverrideWebhookUrl()
    {
        $this->assertEquals('webhook', Cashier::webhookUrl());

        config(['cashier.webhook_url' => 'https://www.example.com/webhook/mollie']);

        $this->assertEquals('webhook/mollie', Cashier::webhookUrl());

        config(['cashier.webhook_url' => 'webhook/cashier']);

        $this->assertEquals('webhook/cashier', Cashier::webhookUrl());
    }

    protected function withMockedGetMollieCustomer($customerIds = ['cst_unique_customer_id'], $times = 2): void
    {
        $this->mock(GetMollieCustomer::class, function ($mock) use ($customerIds, $times) {
            foreach ($customerIds as $id) {
                $customer = new Customer(new MollieApiClient);
                $customer->id = $id;
                $mock->shouldReceive('execute')->with($id)->times($times)->andReturn($customer);
            }

            return $mock;
        });
    }

    protected function withMockedGetMollieMandate($attributes = [[
        'mandateId' => 'mdt_unique_mandate_id',
        'customerId' => 'cst_unique_customer_id',
    ]], $times = 2): void
    {
        $this->mock(GetMollieMandate::class, function ($mock) use ($times, $attributes) {
            foreach ($attributes as $data) {
                $mandate = new Mandate(new MollieApiClient);
                $mandate->id = $data['mandateId'];
                $mandate->status = 'valid';
                $mandate->method = 'directdebit';

                $mock->shouldReceive('execute')->with($data['customerId'], $data['mandateId'])->times($times)->andReturn($mandate);
            }

            return $mock;
        });
    }

    protected function withMockedGetMollieMethodMinimumAmount($times = 1): void
    {
        $this->mock(GetMollieMethodMinimumAmount::class, function ($mock) use ($times) {
            return $mock->shouldReceive('execute')->with('directdebit', 'EUR')->times($times)->andReturn(mollie_money(100, 'EUR'));
        });
    }

    protected function withMockedGetMollieMethodMaximumAmount($times = 1): void
    {
        $this->mock(GetMollieMethodMaximumAmount::class, function ($mock) use ($times) {
            return $mock->shouldReceive('execute')->with('directdebit', 'EUR')->times($times)->andReturn(mollie_money(30000, 'EUR'));
        });
    }

    protected function withMockedCreateMolliePayment($times = 1): void
    {
        $this->mock(CreateMolliePayment::class, function ($mock) use ($times) {
            $payment = new Payment($this->getMollieClientMock());
            $payment->id = 'tr_dummy_id';
            $payment->amount = (object) [
                'currency' => 'EUR',
                'value' => '10.00',
            ];
            $payment->amountChargedBack = (object) [
                'currency' => 'EUR',
                'value' => '0.00',
            ];
            $payment->amountRefunded = (object) [
                'currency' => 'EUR',
                'value' => '0.00',
            ];
            $payment->mandateId = 'mdt_dummy_mandate_id';

            return $mock->shouldReceive('execute')->times($times)->andReturn($payment);
        });
    }
}
