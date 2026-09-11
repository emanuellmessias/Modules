<?php

namespace Modules\ExecutiveReport\Classes;

/**
 * Calcula o SLA (percentual de disponibilidade) de um conjunto de hosts,
 * com base apenas em eventos de ICMP ping sem resposta dentro da janela
 * de tempo analisada.
 *
 * Método:
 *  1. Busca os eventos de problema (início) no período, via event.get;
 *  2. Descobre o clock de resolução de cada um (ou considera "ainda
 *     aberto até agora" se não foi resolvido);
 *  3. Faz a UNIÃO dos intervalos [início, fim] (para não contar downtime
 *     duas vezes quando problemas se sobrepõem no tempo, inclusive entre
 *     hosts diferentes do mesmo grupo/categoria);
 *  4. SLA = 100 - (downtime total / duração do período) * 100
 */
class Sla {

    /**
     * Severidade mínima considerada para impactar o SLA.
     * 0 = Não classificada, 1 = Informação, 2 = Atenção,
     * 3 = Média, 4 = Alta, 5 = Desastre.
     *
     * Por padrão, só Média/Alta/Desastre derrubam o SLA. Se quiser que
     * Atenção também conte, mude para 2.
     */
    public const SLA_MIN_SEVERITY = 3;

    /**
     * SLA de uma única categoria (host group).
     */
    public static function getCategorySla(int $groupid, int $period_days): float {

        $hostids = self::getHostIdsByGroups([$groupid]);

        return self::calculate($hostids, $period_days);
    }

    /**
     * SLA global do cliente (união de todos os hosts de todas as
     * categorias dele, não é média das SLAs de categoria).
     */
    public static function getClientSla(int $groupid, int $period_days): float {

        $categories = Category::getByClient($groupid);

        if (!$categories) {
            return 100.0;
        }

        $category_ids = array_column($categories, 'groupid');
        $hostids = self::getHostIdsByGroups($category_ids);

        return self::calculate($hostids, $period_days);
    }

    /**
     * Busca hostids ativos de uma lista de grupos.
     */
    private static function getHostIdsByGroups(array $groupids): array {

        if (!$groupids) {
            return [];
        }

        $hosts = \API::Host()->get([
            'output' => ['hostid'],
            'groupids' => $groupids,
            'filter' => ['status' => 0]
        ]);

        return $hosts ? array_column($hosts, 'hostid') : [];
    }

    /**
     * Faz o cálculo de fato para uma lista de hostids.
     */
    private static function calculate(array $hostids, int $period_days): float {

        if (!$hostids) {
            return 100.0;
        }

        $time_till = time();
        $time_from = $time_till - ($period_days * 86400);

        // Eventos de início de problema no período, já filtrando severidade.
        $events = \API::Event()->get([
            'output' => ['eventid', 'clock', 'r_eventid', 'name'],
            'hostids' => $hostids,
            'source' => EVENT_SOURCE_TRIGGERS,
            'object' => EVENT_OBJECT_TRIGGER,
            'value' => TRIGGER_VALUE_TRUE,
            'severities' => range(self::SLA_MIN_SEVERITY, TRIGGER_SEVERITY_DISASTER),
            'time_from' => $time_from,
            'time_till' => $time_till
        ]);

        if (!$events) {
            return 100.0;
        }

        $events = array_values(array_filter($events, function ($event) {
            return Availability::isIcmpUnavailableEvent($event);
        }));

        if (!$events) {
            return 100.0;
        }

        // Busca o clock de resolução de cada evento resolvido.
        $r_eventids = array_values(array_filter(array_column($events, 'r_eventid')));
        $resolve_clocks = [];

        if ($r_eventids) {
            $resolved = \API::Event()->get([
                'output' => ['eventid', 'clock'],
                'eventids' => $r_eventids
            ]);

            foreach ($resolved as $r) {
                $resolve_clocks[$r['eventid']] = (int)$r['clock'];
            }
        }

        $intervals = [];

        foreach ($events as $event) {
            $start = max((int)$event['clock'], $time_from);

            // Se não tem r_eventid (ainda aberto), considera downtime até agora.
            $end = ($event['r_eventid'] != 0 && isset($resolve_clocks[$event['r_eventid']]))
                ? $resolve_clocks[$event['r_eventid']]
                : $time_till;

            $end = min($end, $time_till);

            if ($end <= $time_from) {
                continue;
            }

            if ($end > $start) {
                $intervals[] = [$start, $end];
            }
        }

        $downtime = self::sumMergedIntervals($intervals);
        $total_period = $time_till - $time_from;

        if ($total_period <= 0) {
            return 100.0;
        }

        $sla = 100 - (($downtime / $total_period) * 100);

        return round(max(0, min(100, $sla)), 4);
    }

    /**
     * Une intervalos sobrepostos e soma a duração total (evita contar
     * downtime duplicado quando há problemas simultâneos).
     */
    private static function sumMergedIntervals(array $intervals): int {

        if (!$intervals) {
            return 0;
        }

        usort($intervals, function ($a, $b) {
            return $a[0] <=> $b[0];
        });

        $merged = [$intervals[0]];

        foreach (array_slice($intervals, 1) as $interval) {
            $last_index = count($merged) - 1;

            if ($interval[0] <= $merged[$last_index][1]) {
                $merged[$last_index][1] = max($merged[$last_index][1], $interval[1]);
            } else {
                $merged[] = $interval;
            }
        }

        $sum = 0;

        foreach ($merged as $interval) {
            $sum += ($interval[1] - $interval[0]);
        }

        return $sum;
    }
}
