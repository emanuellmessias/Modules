<?php

namespace Modules\ExecutiveReport\Classes;

/**
 * Classe responsável pelas estatísticas do ambiente.
 *
 * Toda informação quantitativa utilizada pelo Executive Report
 * deve ser obtida através desta classe.
 */
class Statistics {

    /**
     * Retorna todas as estatísticas de uma categoria, já incluindo o SLA
     * calculado para o período informado.
     */
    public static function getCategoryStatistics(int $groupid, int $period_days = 30): array {

        $hostids = self::getHostIds($groupid);

        return [
            'hosts' => self::countHosts($hostids),
            'items' => self::countItems($hostids),
            'triggers' => self::countTriggers($hostids),
            'problems' => self::countProblems($hostids),
            'sla' => Sla::getCategorySla($groupid, $period_days)
        ];
    }

    /**
     * Descobre todos os hosts ativos pertencentes ao grupo.
     */
    private static function getHostIds(int $groupid): array {

        if ($groupid <= 0) {
            return [];
        }

        $hosts = \API::Host()->get([
            'output' => ['hostid'],
            'groupids' => $groupid,
            'filter' => [
                'status' => 0
            ]
        ]);

        if (!$hosts) {
            return [];
        }

        return array_column($hosts, 'hostid');
    }

    /**
     * Conta hosts.
     */
    private static function countHosts(array $hostids): int {
        return count($hostids);
    }

    /**
     * Conta itens habilitados.
     */
    private static function countItems(array $hostids): int {

        if (!$hostids) {
            return 0;
        }

        return (int)\API::Item()->get([
            'hostids' => $hostids,
            'filter' => [
                'status' => 0
            ],
            'countOutput' => true
        ]);
    }

    /**
     * Conta triggers habilitadas.
     */
    private static function countTriggers(array $hostids): int {

        if (!$hostids) {
            return 0;
        }

        return (int)\API::Trigger()->get([
            'hostids' => $hostids,
            'filter' => [
                'status' => 0
            ],
            'countOutput' => true
        ]);
    }

    /**
     * Conta problemas ativos (snapshot atual, não depende do período).
     */
    private static function countProblems(array $hostids): int {

        if (!$hostids) {
            return 0;
        }

        return (int)\API::Problem()->get([
            'hostids' => $hostids,
            'countOutput' => true
        ]);
    }
}
