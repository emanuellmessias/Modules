<?php

namespace Modules\ExecutiveReport\Classes;

/**
 * Executive Score — nota única (0 a 100) que resume a saúde do ambiente
 * do cliente no período consultado.
 *
 * A nota parte de 100 e aplica penalidades por dimensão. Cada dimensão tem
 * um teto de desconto (peso), de forma que nenhuma sozinha zere a nota:
 *
 *   Disponibilidade (SLA por severidade) ....... até -40
 *   Impactos ativos (problemas em aberto) ....... até -20
 *   Hosts indisponíveis (ICMP no período) ....... até -15
 *   Eventos críticos ativos (Alta + Desastre) ... até -25
 *
 * O SLA aqui já é o SLA por severidade calculado em Sla.php (Alta/Desastre
 * = downtime), então o Score reflete disponibilidade de SERVIÇO, não apenas
 * de ping.
 */
class ExecutiveScore {

    /** Tetos de penalidade por dimensão (pesos). */
    private const MAX_PENALTY_SLA        = 40;
    private const MAX_PENALTY_PROBLEMS   = 20;
    private const MAX_PENALTY_HOSTS_DOWN = 15;
    private const MAX_PENALTY_CRITICAL   = 25;

    /** SLA alvo: abaixo disso começa a penalizar. */
    private const SLA_TARGET = 99.90;

    public static function calculate(array $report): array {

        $score = 100.0;

        $summary = $report['summary'] ?? [];
        $health  = $report['health'] ?? [];

        /*
        |--------------------------------------------------------------------------
        | Disponibilidade (SLA por severidade)
        |--------------------------------------------------------------------------
        | Cada ponto percentual abaixo do alvo custa 4 pontos de score, até o
        | teto da dimensão. Ex.: SLA 90% => (99.90 - 90) * 4 = ~39.6 => -40 (teto).
        */
        $sla = (float)($summary['sla'] ?? 100);
        $penalty_sla = 0.0;

        if ($sla < self::SLA_TARGET) {
            $penalty_sla = min((self::SLA_TARGET - $sla) * 4.0, self::MAX_PENALTY_SLA);
        }

        /*
        |--------------------------------------------------------------------------
        | Impactos ativos (problemas em aberto no snapshot atual)
        |--------------------------------------------------------------------------
        */
        $problems = (int)($summary['problems'] ?? 0);
        $penalty_problems = min($problems * 0.35, self::MAX_PENALTY_PROBLEMS);

        /*
        |--------------------------------------------------------------------------
        | Hosts indisponíveis (ICMP) no período
        |--------------------------------------------------------------------------
        */
        $hosts_down = (int)($summary['hosts_down'] ?? 0);
        $penalty_hosts_down = min($hosts_down * 3.0, self::MAX_PENALTY_HOSTS_DOWN);

        /*
        |--------------------------------------------------------------------------
        | Eventos críticos ativos (Alta + Desastre)
        |--------------------------------------------------------------------------
        | Desastre pesa mais que Alta.
        */
        $disaster = (int)($health['disaster'] ?? 0);
        $high     = (int)($health['high'] ?? 0);
        $penalty_critical = min(($disaster * 1.2) + ($high * 0.6), self::MAX_PENALTY_CRITICAL);

        /*
        |--------------------------------------------------------------------------
        | Total
        |--------------------------------------------------------------------------
        */
        $score -= ($penalty_sla + $penalty_problems + $penalty_hosts_down + $penalty_critical);
        $score = max(0, min(100, round($score, 2)));

        [$status, $color] = self::classify($score);

        return [
            'score'  => $score,
            'status' => $status,
            'color'  => $color,

            // Transparência: quanto cada dimensão descontou.
            'breakdown' => [
                'sla'        => round($penalty_sla, 2),
                'problems'   => round($penalty_problems, 2),
                'hosts_down' => round($penalty_hosts_down, 2),
                'critical'   => round($penalty_critical, 2)
            ]
        ];
    }

    /**
     * Classifica a nota em faixa (status + cor).
     */
    private static function classify(float $score): array {

        if ($score >= 98) {
            return ['Excelente', '#00C853'];
        }

        if ($score >= 95) {
            return ['Muito Bom', '#64DD17'];
        }

        if ($score >= 90) {
            return ['Bom', '#FFD54F'];
        }

        if ($score >= 80) {
            return ["Aten\u{00e7}\u{00e3}o", '#FB8C00'];
        }

        return ["Cr\u{00ed}tico", '#E53935'];
    }
}
