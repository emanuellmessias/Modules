<?php declare(strict_types = 1);

namespace Modules\NocExecutiveDashboard;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;

/**
 * NOC Executive Dashboard module.
 *
 * Registers a top-level "NOC" menu with the Executive Overview page.
 */
class Module extends CModule {

	/**
	 * Initialize module: inject the NOC menu entry into the main frontend menu.
	 */
	public function init(): void {
		// Add a dedicated "NOC" section to the main menu, after "Monitoring".
		APP::Component()->get('menu.main')
			->findOrAdd(_('NOC'))
				->getSubmenu()
					->add((new CMenuItem(_('Dashboard Executivo')))
						->setAction('noc.executive.overview')
					);
	}
}
