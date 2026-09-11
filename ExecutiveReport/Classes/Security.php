<?php

namespace Modules\ExecutiveReport\Classes;

class Security {

    public static function hasAccess(): bool {
        if (!class_exists('\CWebUser') || !\CWebUser::isLoggedIn()) {
            return false;
        }
        $user_type = \CWebUser::$data['type'];
        return ($user_type == USER_TYPE_ZABBIX_ADMIN || $user_type == USER_TYPE_SUPER_ADMIN);
    }
}