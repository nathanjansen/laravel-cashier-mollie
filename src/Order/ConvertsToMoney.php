<?php

namespace Laravel\Cashier\Order;

trait ConvertsToMoney
{
    /**
     * @param  int  $value
     * @return \Money\Money
     */
    protected function toMoney($value = 0)
    {
        return mollie_money(round($value), $this->getCurrency());
    }
}
