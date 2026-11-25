<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;

final class StrategyTrial
{
    public $strategy;
    public $threshold;
    public $atrStop;
    public $holding;

    public function __construct(string $strategy, float $threshold, float $atrStop, int $holding)
    {
        $limits = ['MOMENTUM'=>[1.0,2.0],'MEAN_REVERSION'=>[1.5,3.0]];
        if (!isset($limits[$strategy]) || !is_finite($threshold) || !is_finite($atrStop) ||
            $threshold < $limits[$strategy][0] || $threshold > $limits[$strategy][1] ||
            $atrStop < 1.5 || $atrStop > 3.5 || $holding < 3 || $holding > 25) {
            throw new RuntimeException('INVALID_STRATEGY_TRIAL');
        }
        $this->strategy = $strategy;
        $this->threshold = $threshold;
        $this->atrStop = $atrStop;
        $this->holding = $holding;
    }

    public function signal(array $row): bool
    {
        if ($this->strategy === 'MOMENTUM') {
            return $row['trend'] > 0 && $row['mom20'] > 0 && ($row['volume_ratio'] ?? 0) > $this->threshold;
        }
        return $row['z20'] < -$this->threshold && $row['rsi'] < 35 && $row['mom60'] > -.10 && $row['vol20'] < .45;
    }
}
