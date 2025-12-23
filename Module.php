<?php
namespace Modules\StorageAnalytics; // [!code ++]
// Important: Replace "StorageAnalytics" with the exact name of your module's directory.

use Zabbix\Core\CModule;
use APP;
use CMenuItem;

class Module extends CModule {
    public function init(): void {
        // Adds a menu item under "Monitoring". For "Reports", change as needed.
        APP::Component()->get('menu.main')
            ->findOrAdd(_('Monitoring')) // [!code ++] // Or (_('Reports'))
                ->getSubmenu()
                    // Insert your item at the desired position
                    ->insertAfter(_('Screens'),
                        (new CMenuItem(_('Storage Analytics')))->setAction('storage.analytics')
                    );
    }
}
