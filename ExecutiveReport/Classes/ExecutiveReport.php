<?php

namespace Modules\ExecutiveReport\Classes;

class ExecutiveReport {

    /**
     * Monta todo o relatório executivo.
     */
    public static function build(int $groupid, int $period_days = 30): array {

        $categories = [];
        $summary = [
            'hosts'       => 0,
            'items'       => 0,
            'triggers'    => 0,
            'problems'    => 0,
            'hosts_down'  => 0,
            'sla'         => 100
        ];

        /*
        |--------------------------------------------------------------------------
        | Categorias
        |--------------------------------------------------------------------------
        */

        if ($groupid > 0) {

            foreach (Category::getByClient($groupid) as $category) {

                $stats = Statistics::getCategoryStatistics(
                    (int) $category['groupid'],
                    $period_days
                );

                $categories[] = array_merge(
                    $category,
                    $stats
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Resumo Geral
        |--------------------------------------------------------------------------
        */

        foreach ($categories as $category) {

            $summary['hosts'] += (int) $category['hosts'];
            $summary['items'] += (int) $category['items'];
            $summary['triggers'] += (int) $category['triggers'];
            $summary['problems'] += (int) $category['problems'];

            /*
             * Futuramente Statistics poderá retornar esse campo.
             */
            if (isset($category['hosts_down'])) {
                $summary['hosts_down'] += (int) $category['hosts_down'];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | SLA Global
        |--------------------------------------------------------------------------
        */

        if (count($categories) > 0) {

            $summary['sla'] = Sla::getClientSla($groupid, $period_days);
            $summary['sla_global'] = $summary['sla'];
        }

        /*
        |--------------------------------------------------------------------------
        | Saúde
        |--------------------------------------------------------------------------
        */

        $health = Health::getByClient($groupid);

        /*
        |--------------------------------------------------------------------------
        | Top Ofensores
        |--------------------------------------------------------------------------
        */

        $offenders = Offenders::getByClient($groupid, $period_days, 0);
        $summary['hosts_down'] = count(array_filter($offenders, function ($offender) {
            return (float)($offender['availability'] ?? 100) < 100;
        }));
        $top_offenders = array_slice($offenders, 0, 10);

        /*
        |--------------------------------------------------------------------------
        | Executive Score
        |--------------------------------------------------------------------------
        */

        $executive_score = ExecutiveScore::calculate([
            'summary' => $summary,
            'health'  => $health
        ]);

        /*
        |--------------------------------------------------------------------------
        | Retorno
        |--------------------------------------------------------------------------
        */

        return [

            'summary' => $summary,

            'health' => $health,

            'categories' => $categories,

            'offenders' => $top_offenders,

            'executive_score' => $executive_score
        ];
    }
}
