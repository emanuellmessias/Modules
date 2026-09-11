<?php

namespace Modules\ExecutiveReport;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

class Module extends CModule {

    public function init(): void {
        APP::Component()->get('menu.main')
            ->add(
                (new CMenuItem(_('Executive Report')))
                    ->setAction('executivereport.view')
            );
    }
}
