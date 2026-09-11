<?php

namespace Modules\ExecutiveReport\Classes;

class ExecutiveScore {

    /**
     * Calcula o Executive Score.
     *
     * Pesos:
     *
     * SLA.....................30%
     * Disponibilidade.........25%
     * Problemas...............20%
     * Hosts Down..............15%
     * Saúde...................10%
     */
    public static function calculate(array $report): array {

        $score = 100;

        /*
        |--------------------------------------------------------------------------
        | SLA
        |--------------------------------------------------------------------------
        */

        $sla = (float)($report['summary']['sla'] ?? 100);

        if ($sla < 99.90) {
            $score -= (99.90 - $sla) * 0.30;
        }

        /*
        |--------------------------------------------------------------------------
        | Problemas
        |--------------------------------------------------------------------------
        */

        $problems = (int)($report['summary']['problems'] ?? 0);

        $score -= min($problems * 0.35, 20);

        /*
        |--------------------------------------------------------------------------
        | Hosts Down
        |--------------------------------------------------------------------------
        */

        $hosts_down = (int)($report['summary']['hosts_down'] ?? 0);

        $score -= min($hosts_down * 3, 15);

        /*
        |--------------------------------------------------------------------------
        | Severidades
        |--------------------------------------------------------------------------
        */

        $health = $report['health'] ?? [];

        $critical =
            ($health['disaster'] ?? 0) +
            ($health['high'] ?? 0);

        $score -= min($critical * 0.60, 10);

        /*
        |--------------------------------------------------------------------------
        | Limites
        |--------------------------------------------------------------------------
        */

        $score = max(0, min(100, round($score, 2)));

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        if ($score >= 98) {

            $status = 'Excelente';
            $color = '#00C853';

        }
        elseif ($score >= 95) {

            $status = 'Muito Bom';
            $color = '#64DD17';

        }
        elseif ($score >= 90) {

            $status = 'Bom';
            $color = '#FFD54F';

        }
        elseif ($score >= 80) {

            $status = "Aten\u{00e7}\u{00e3}o";
            $color = '#FB8C00';

        }
        else {

            $status = "Cr\u{00ed}tico";
            $color = '#E53935';

        }

        return [

            'score' => $score,

            'status' => $status,

            'color' => $color
        ];
    }
}
