<?php

namespace Modules\StorageAnalytics;

class StorageAnalytics extends \Zabbix\Core\CModule {
    
    public function init(): void {
        // Register module routes
        $this->addRoute('storage.analytics', 'CControllerStorageAnalytics');
        
        // Add menu item
        $this->addMenuItem('Monitoring', [
            'label' => _('Storage Analytics'),
            'url' => 'zabbix.php?action=storage.analytics',
            'icon' => 'icon-storage-analytics',
            'order' => 300
        ]);
    }
    
    public function onTerminate(): void {
        // Cleanup if needed
    }
}
