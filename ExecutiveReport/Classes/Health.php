<?php

namespace Modules\ExecutiveReport\Classes;

class Health {

    public static function getByClient(int $groupid): array {
        $health = [
            'not_classified' => 0, 'information' => 0, 'warning' => 0,
            'average' => 0, 'high' => 0, 'disaster' => 0
        ];

        if ($groupid <= 0) {
            return $health;
        }

        $categories = Category::getByClient($groupid);
        if (!$categories) {
            return $health;
        }

        $category_ids = array_column($categories, 'groupid');

        $hosts = \API::Host()->get([
            'output' => ['hostid'],
            'groupids' => $category_ids,
            'filter' => ['status' => 0]
        ]);

        if (!$hosts) {
            return $health;
        }

        $hostids = array_column($hosts, 'hostid');

        $problems = \API::Problem()->get([
            'hostids' => $hostids,
            'output' => ['severity'],
            'severities' => [TRIGGER_SEVERITY_DISASTER]
        ]);

        foreach ($problems as $problem) {
            switch ((int)$problem['severity']) {
                case 0: $health['not_classified']++; break;
                case 1: $health['information']++; break;
                case 2: $health['warning']++; break;
                case 3: $health['average']++; break;
                case 4: $health['high']++; break;
                case 5: $health['disaster']++; break;
            }
        }

        return $health;
    }
}
