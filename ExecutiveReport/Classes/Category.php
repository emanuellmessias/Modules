<?php

namespace Modules\ExecutiveReport\Classes;

class Category {

    public static function getByClient(int $groupid): array {

        if ($groupid <= 0) {
            return [];
        }

        // Busca todos os grupos
        $groups = \API::HostGroup()->get([
            'output' => [
                'groupid',
                'name'
            ],
            'sortfield' => 'name'
        ]);

        // Descobre o nome do cliente
        $client_name = '';

        foreach ($groups as $group) {

            if ((int) $group['groupid'] === $groupid) {
                $client_name = $group['name'];
                break;
            }
        }

        if ($client_name === '') {
            return [];
        }

        $categories = [];

        foreach ($groups as $group) {

            if (strpos($group['name'], $client_name.' | ') === 0) {

                $category = trim(substr($group['name'], strlen($client_name.' | ')));

                $categories[] = [
                    'groupid'  => $group['groupid'],
                    'name'     => $group['name'],
                    'category' => $category
                ];
            }
        }

        return $categories;
    }
}