<?php

namespace Modules\ExecutiveReport\Classes;

class Availability {

    public static function isIcmpUnavailableEvent(array $event): bool {

        $text = strtolower($event['name'] ?? '');

        if (strpos($text, 'icmp') === false || strpos($text, 'ping') === false) {
            return false;
        }

        foreach (self::unavailableTerms() as $term) {
            if (strpos($text, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function unavailableTerms(): array {

        return [
            'unavailable',
            'indisponivel',
            'indisponibilidade',
            'no response',
            'sem resposta',
            'nao responde',
            'inacessivel',
            'down'
        ];
    }
}
