<?php

namespace Modules\ExecutiveReport\Classes;

class Client {

    public static function getAll(): array {

        $groups = \API::HostGroup()->get([
            'output' => [
                'groupid',
                'name'
            ],
            'sortfield' => 'name'
        ]);

        $clients = [];

        // Índice com todos os nomes dos grupos
        $group_names = [];

        foreach ($groups as $group) {
            $group_names[$group['name']] = true;
        }

        foreach ($groups as $group) {

            // Ignora grupos filhos
            if (strpos($group['name'], '|') !== false) {
                continue;
            }

            // Verifica se existe pelo menos um grupo "CLIENTE | ..."
            foreach ($group_names as $name => $dummy) {

                if (strpos($name, $group['name'].' | ') === 0) {
                    $clients[] = $group;
                    break;
                }
            }
        }

        return $clients;
    }
}