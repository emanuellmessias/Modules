<?php

namespace Modules\ExecutiveReport\Classes;

class Offenders {

    public static function getByClient(int $groupid, int $period_days = 30, int $limit = 10): array {

        if ($groupid <= 0) {
            return [];
        }

        $categories = Category::getByClient($groupid);

        if (!$categories) {
            return [];
        }

        $category_ids = array_column($categories, 'groupid');
        $category_names = [];

        foreach ($categories as $category) {
            $category_names[(string)$category['groupid']] = $category['category'];
        }

        $hosts = \API::Host()->get([
            'output' => ['hostid', 'name'],
            'groupids' => $category_ids,
            'filter' => ['status' => 0],
            'selectGroups' => ['groupid', 'name']
        ]);

        if (!$hosts) {
            return [];
        }

        $hostids = [];
        $host_names = [];
        $host_categories = [];

        foreach ($hosts as $host) {
            $hostid = (string)$host['hostid'];

            $hostids[] = $hostid;
            $host_names[$hostid] = $host['name'];
            $host_categories[$hostid] = '';

            foreach (($host['groups'] ?? []) as $group) {
                $host_groupid = (string)$group['groupid'];

                if (isset($category_names[$host_groupid])) {
                    $host_categories[$hostid] = $category_names[$host_groupid];
                    break;
                }
            }
        }

        $time_till = time();
        $time_from = $time_till - ($period_days * 86400);

        $events = \API::Event()->get([
            'output' => ['eventid', 'clock', 'r_eventid', 'name'],
            'hostids' => $hostids,
            'source' => EVENT_SOURCE_TRIGGERS,
            'object' => EVENT_OBJECT_TRIGGER,
            'value' => TRIGGER_VALUE_TRUE,
            'severities' => range(Sla::SLA_MIN_SEVERITY, TRIGGER_SEVERITY_DISASTER),
            'time_from' => $time_from,
            'time_till' => $time_till,
            'selectHosts' => ['hostid', 'name']
        ]);

        if (!$events) {
            return [];
        }

        $events = array_values(array_filter($events, function ($event) {
            return Availability::isIcmpUnavailableEvent($event);
        }));

        if (!$events) {
            return [];
        }

        $resolve_clocks = self::getResolveClocks($events);
        $offenders = [];

        foreach ($events as $event) {
            $event_hosts = $event['hosts'] ?? [];

            if (!$event_hosts) {
                continue;
            }

            $hostid = (string)$event_hosts[0]['hostid'];
            $start = max((int)$event['clock'], $time_from);
            $end = ((int)$event['r_eventid'] !== 0 && isset($resolve_clocks[$event['r_eventid']]))
                ? $resolve_clocks[$event['r_eventid']]
                : $time_till;

            $end = min($end, $time_till);

            if ($end <= $time_from || $end <= $start) {
                continue;
            }

            if (!isset($offenders[$hostid])) {
                $offenders[$hostid] = [
                    'host' => $host_names[$hostid] ?? $event_hosts[0]['name'],
                    'category' => $host_categories[$hostid] ?: '-',
                    'problems' => 0,
                    'intervals' => []
                ];
            }

            $offenders[$hostid]['problems']++;
            $offenders[$hostid]['intervals'][] = [$start, $end];
        }

        $total_period = max(1, $time_till - $time_from);
        $rows = [];

        foreach ($offenders as $offender) {
            $downtime = self::sumMergedIntervals($offender['intervals']);
            $availability = 100 - (($downtime / $total_period) * 100);

            $rows[] = [
                'host' => $offender['host'],
                'category' => $offender['category'],
                'problems' => $offender['problems'],
                'downtime_hours' => round($downtime / 3600, 2),
                'availability' => round(max(0, min(100, $availability)), 4)
            ];
        }

        usort($rows, function ($a, $b) {
            if ($a['downtime_hours'] === $b['downtime_hours']) {
                return $b['problems'] <=> $a['problems'];
            }

            return $b['downtime_hours'] <=> $a['downtime_hours'];
        });

        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    private static function getResolveClocks(array $events): array {

        $r_eventids = array_values(array_filter(array_column($events, 'r_eventid')));

        if (!$r_eventids) {
            return [];
        }

        $resolved = \API::Event()->get([
            'output' => ['eventid', 'clock'],
            'eventids' => $r_eventids
        ]);

        $resolve_clocks = [];

        foreach ($resolved as $event) {
            $resolve_clocks[$event['eventid']] = (int)$event['clock'];
        }

        return $resolve_clocks;
    }

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
