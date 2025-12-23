<?php
/**
 * Host Table Partial
 */
?>

<div class="table-section">
    <div class="table-header">
        <h2><?= _('Storage Analysis by Host') ?></h2>
        <div class="table-actions">
            <div class="view-toggle">
                <button type="button" class="btn-view-toggle active" data-view="host"><?= _('Host View') ?></button>
                <button type="button" class="btn-view-toggle" data-view="filesystem"><?= _('Filesystem View') ?></button>
            </div>
            
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?action=storage.analytics&page=<?= $page - 1 ?><?= $buildQueryString($filter, ['page']) ?>" 
                       class="pagination-link prev"><?= _('Previous') ?></a>
                <?php endif; ?>
                
                <span class="pagination-info">
                    <?= sprintf(_('Page %1$s of %2$s'), $page, $total_pages) ?>
                </span>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?action=storage.analytics&page=<?= $page + 1 ?><?= $buildQueryString($filter, ['page']) ?>" 
                       class="pagination-link next"><?= _('Next') ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Host View Table -->
    <div class="table-container host-view" id="host-view">
        <table class="list-table">
            <thead>
                <tr>
                    <th><?= _('Host') ?></th>
                    <th><?= _('Total Space') ?></th>
                    <th><?= _('Used Space') ?></th>
                    <th><?= _('Usage %') ?></th>
                    <th><?= _('Growth Rate') ?></th>
                    <th><?= _('Days Until Full') ?></th>
                    <th><?= _('Status') ?></th>
                    <th><?= _('Filesystems') ?></th>
                    <th><?= _('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Group by host first
                $hostsData = [];
                foreach ($storageData as $item) {
                    $hostId = $item['hostid'];
                    if (!isset($hostsData[$hostId])) {
                        $hostsData[$hostId] = [
                            'host' => $item['host'],
                            'host_name' => $item['host_name'],
                            'total_raw' => 0,
                            'used_raw' => 0,
                            'usage_pct' => 0,
                            'daily_growth_raw' => 0,
                            'days_until_full' => null,
                            'filesystems' => [],
                            'status' => 'ok',
                            'fs_count' => 0
                        ];
                    }
                    
                    $hostsData[$hostId]['total_raw'] += $item['total_raw'];
                    $hostsData[$hostId]['used_raw'] += $item['used_raw'];
                    $hostsData[$hostId]['daily_growth_raw'] += $item['daily_growth_raw'];
                    $hostsData[$hostId]['filesystems'][] = $item;
                    $hostsData[$hostId]['fs_count']++;
                    
                    // Set status to worst among filesystems
                    if ($item['status'] === 'critical' || $hostsData[$hostId]['status'] === 'critical') {
                        $hostsData[$hostId]['status'] = 'critical';
                    } elseif ($item['status'] === 'warning' && $hostsData[$hostId]['status'] !== 'critical') {
                        $hostsData[$hostId]['status'] = 'warning';
                    }
                }
                
                // Calculate host-level metrics
                foreach ($hostsData as &$hostData) {
                    if ($hostData['total_raw'] > 0) {
                        $hostData['usage_pct'] = round(($hostData['used_raw'] / $hostData['total_raw']) * 100, 1);
                    }
                    
                    // Calculate earliest days until full among filesystems
                    $earliestDays = null;
                    foreach ($hostData['filesystems'] as $fs) {
                        if ($fs['daily_growth_raw'] > 0) {
                            preg_match('/\d+/', $fs['days_until_full'], $matches);
                            $days = $matches[0] ?? PHP_INT_MAX;
                            if ($earliestDays === null || $days < $earliestDays) {
                                $earliestDays = $days;
                            }
                        }
                    }
                    
                    $hostData['days_until_full'] = $earliestDays !== null ? $earliestDays . ' days' : _('No growth');
                }
                
                // Paginate hosts
                $hostsList = array_slice($hostsData, $start_record - 1, $page_limit);
                
                // Display hosts
                foreach ($hostsList as $hostId => $host): 
                ?>
                <tr class="host-row" data-hostid="<?= $hostId ?>">
                    <td class="host-cell">
                        <strong><?= htmlspecialchars($host['host']) ?></strong>
                        <?php if ($host['host_name'] !== $host['host']): ?>
                            <div class="host-alias"><?= htmlspecialchars($host['host_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= $formatBytes($host['total_raw']) ?></td>
                    <td><?= $formatBytes($host['used_raw']) ?></td>
                    <td>
                        <div class="usage-cell">
                            <span class="usage-value"><?= $host['usage_pct'] ?>%</span>
                            <div class="usage-bar">
                                <div class="usage-fill" style="width: <?= min($host['usage_pct'], 100) ?>%"
                                     data-threshold-warning="<?= $filter['warning_threshold'] ?>"
                                     data-threshold-critical="<?= $filter['critical_threshold'] ?>">
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($host['daily_growth_raw'] > 0): ?>
                            <span class="growth-value positive">
                                +<?= $formatBytes($host['daily_growth_raw'] * 86400) ?>/day
                            </span>
                        <?php else: ?>
                            <span class="growth-value neutral"><?= _('Stable') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="days-cell <?= $host['status'] ?>">
                            <?= $host['days_until_full'] ?>
                        </span>
                    </td>
                    <td>
                        <span class="status-badge <?= $host['status'] ?>">
                            <?= ucfirst($host['status']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="fs-count"><?= $host['fs_count'] ?></span>
                    </td>
                    <td>
                        <button type="button" class="btn-details btn-small" 
                                data-hostid="<?= $hostId ?>"
                                data-host="<?= htmlspecialchars($host['host']) ?>">
                            <?= _('Details') ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($hostsList)): ?>
                <tr>
                    <td colspan="9" class="no-data">
                        <?= _('No storage data found matching the filters.') ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Filesystem View Table (Initially hidden) -->
    <div class="table-container filesystem-view" id="filesystem-view" style="display: none;">
        <?php include __DIR__ . '/filesystem_details.php'; ?>
    </div>
</div>

<!-- Host Details Modal (for AJAX loading) -->
<div class="modal" id="host-details-modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modal-title"><?= _('Host Details') ?></h3>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body" id="modal-body">
            <!-- Content loaded via AJAX -->
            <div class="loading"><?= _('Loading...') ?></div>
        </div>
    </div>
</div>
