<?php

namespace SumUpWhmcs;

class Money
{
    /**
     * Formats an amount for the SumUp API (a number with two decimals).
     */
    public static function toApi($amount)
    {
        return round((float) $amount, 2);
    }

    public static function equals($a, $b)
    {
        return abs(round((float) $a, 2) - round((float) $b, 2)) < 0.005;
    }
}
