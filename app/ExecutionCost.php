<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;

final class ExecutionCost
{
    private const BPS = 10000;

    public static function price(int $reference, int $tick, int $adverseBps, bool $buy): int
    {
        if ($reference <= 0 || $tick <= 0 || $adverseBps < 0 || $adverseBps >= self::BPS ||
            $tick > intdiv(PHP_INT_MAX,self::BPS)) {
            throw new RuntimeException('INVALID_COST_INPUT');
        }
        $factor = self::BPS + ($buy ? $adverseBps : -$adverseBps);
        $divisor = $tick * self::BPS;
        $numerator = self::multiply($reference,$factor);
        $units = intdiv($numerator,$divisor);
        if ($buy && $numerator % $divisor !== 0) {
            $units++;
        }
        return self::multiply($units,$tick);
    }

    public static function affordable(int $reserved, int $price, int $feeBps): int
    {
        self::checkFee($feeBps);
        if ($reserved < 0 || $price <= 0) {
            throw new RuntimeException('INVALID_COST_INPUT');
        }
        return intdiv(self::multiply($reserved,self::BPS),self::multiply($price,self::BPS+$feeBps));
    }

    public static function charge(int $price, int $quantity, int $feeBps, bool $buy): array
    {
        self::checkFee($feeBps);
        if ($price <= 0 || $quantity <= 0) {
            throw new RuntimeException('INVALID_COST_INPUT');
        }
        $notional = self::multiply($price,$quantity);
        $scaled = self::multiply($notional,$feeBps);
        $fee = intdiv($scaled,self::BPS) + (int) ($scaled % self::BPS !== 0);
        if ($buy && $notional > PHP_INT_MAX-$fee) {
            throw new RuntimeException('COST_OVERFLOW');
        }
        return ['fee'=>$fee,'cash'=>$buy ? $notional+$fee : $notional-$fee];
    }

    private static function checkFee(int $feeBps): void
    {
        if ($feeBps < 0 || $feeBps >= self::BPS) {
            throw new RuntimeException('INVALID_COST_INPUT');
        }
    }

    private static function multiply(int $a, int $b): int
    {
        if (min($a,$b) < 0 || ($b > 0 && $a > intdiv(PHP_INT_MAX,$b))) {
            throw new RuntimeException('COST_OVERFLOW');
        }
        return $a*$b;
    }
}
