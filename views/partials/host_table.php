<?php
/**
 * Host Table Partial
 * Note: This is used for the host view in Storage Analytics
 */

// For inline use, we need the data
if (!isset($storageData) || !isset($filter)) {
    return;
}

// Helper function for view (since we can't call controller methods directly)
function parseDaysInView(string $daysStr): int {
    if ($daysStr === _('No growth') || $daysStr === _('Already full') || 
        $daysStr === _('Growth error') || $daysStr === _('More than 10 years')) {
        return PHP_INT_MAX;
    }
    
    $days = PHP_INT_MAX;
    
    // Extract years
    if (preg_match('/(\d+)\s*years?/', $daysStr, $matches)) {
        $days = (int)$matches[1] * 365;
    }
    
    // Extract months
    if (preg_match('/(\d+)\s*months?/', $daysStr, $matches)) {
        $days = ($days < PHP_INT_MAX) ? $days + ((int)$matches[1] * 30) : ((int)$matches[1] * 30);
    }
    
    // Extract days
    if (preg_match('/(\d+)\s*days?/', $daysStr, $matches)) {
        $days = ($days < PHP_INT_MAX) ? $days + (int)$matches[1] : (int)$matches[1];
    }
    
    // If it's just a plain number
    if ($days === PHP_INT_MAX && is_numeric($daysStr)) {
        $days = (int)$daysStr;
    }
    
    return $days;
}

// Helper function to format days consistently
function formatDaysInView(int $days): string {
    if ($days > 365 * 10) {
        return _('More than 10 years');
    } elseif ($days > 365) {
        $years = floor($days / 365);
        $remainingDays = $days % 365;
        $months = floor($remainingDays / 30);
        
        if ($months > 0) {
            return sprintf(_('%d years %d months'), $years, $months);
        } else {
            return sprintf(_('%d years'), $years);
        }
    } elseif ($days > 30) {
        $months = floor($days / 30);
        $remainingDays = $days % 30;
        
        if ($remainingDays > 0) {
            return sprintf(_('%d months %d days'), $months, $remainingDays);
        } else {
            return sprintf(_('%d months'), $months);
        }
    } else {
        return sprintf(_('%d days'), $days);
    }
}

// Group by host first
$hostsData = [];
foreach ($storageData as $item) {
    $hostId = $item['hostid'];
    if (!isset($hostsData[$hostId])) {
        $hostsData[$hostId] = [
            'hostid' => $hostId,
            'host' => $item['host'],
            'host_name' => $item['host_name'],
            'total_raw' => 0,
            'used_raw' => 0,
            'filesystems' => [],
            'status' => 'ok',
            'fs_count' => 0
        ];
    }
    
    $hostsData[$hostId]['total_raw'] += $item['total_raw'];
    $hostsData[$hostId]['used_raw'] += $item['used_raw'];
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
    } else {
        $hostData['usage_pct'] = 0;
    }
}
?>

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
                
                <!-- GROWTH RATE COLUMN - FIXED -->
                <td>
                    <?php 
                    // Calculate REALISTIC host growth (based on individual filesystems)
                    $validFilesystems = [];
                    foreach ($host['filesystems'] as $fs) {
                        if (isset($fs['daily_growth_raw']) && $fs['daily_growth_raw'] > 0) {
                            $validFilesystems[] = [
                                'growth' => $fs['daily_growth_raw'],
                                'days' => parseDaysInView($fs['days_until_full'])
                            ];
                        }
                    }
                    
                    if (!empty($validFilesystems)) {
                        // Calculate median growth (to avoid outliers)
                        $growthValues = array_column($validFilesystems, 'growth');
                        sort($growthValues);
                        $count = count($growthValues);
                        $middle = floor(($count - 1) / 2);
                        
                        if ($count % 2) {
                            $medianGrowth = $growthValues[$middle];
                        } else {
                            $medianGrowth = ($growthValues[$middle] + $growthValues[$middle + 1]) / 2;
                        }
                        
                        // Cap unrealistic growth (> 10GB/day)
                        if ($medianGrowth > 10737418240) {
                            $medianGrowth = 0;
                        }
                        
                        if ($medianGrowth > 0) {
                            echo '<span class="growth-value positive">';
                            echo '+' . $formatBytes($medianGrowth) . '/day';
                            echo '</span>';
                        } else {
                            echo '<span class="growth-value neutral">' . _('Stable') . '</span>';
                        }
                    } else {
                        echo '<span class="growth-value neutral">' . _('Stable') . '</span>';
                    }
                    ?>
                </td>
                
                <!-- DAYS UNTIL FULL COLUMN - FIXED -->
                <td>
                    <?php
                    if (!empty($validFilesystems)) {
                        // Find the EARLIEST days until full (most critical filesystem)
                        $daysValues = array_column($validFilesystems, 'days');
                        sort($daysValues);
                        $earliestDays = $daysValues[0];
                        
                        // Ensure we have a valid number
                        if ($earliestDays > 0 && $earliestDays < PHP_INT_MAX) {
                            $displayDays = formatDaysInView($earliestDays);
                            
                            // Determine status color based on days
                            $daysStatus = 'ok';
                            if ($earliestDays <= 30) {
                                $daysStatus = 'critical';
                            } elseif ($earliestDays <= 90) {
                                $daysStatus = 'warning';
                            }
                            
                            echo '<span class="days-cell ' . $daysStatus . '">';
                            echo htmlspecialchars($displayDays);
                            echo '</span>';
                        } else {
                            echo '<span class="days-cell ok">' . _('No growth') . '</span>';
                        }
                    } else {
                        echo '<span class="days-cell ok">' . _('No growth') . '</span>';
                    }
                    ?>
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

<style>
/* Additional styles for fixed columns */
.growth-value.positive {
    color: #d9534f;
    font-weight: 600;
    font-size: 13px;
}

.growth-value.neutral {
    color: #777;
    font-style: italic;
}

.days-cell {
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 13px;
    display: inline-block;
}

.days-cell.ok {
    background: #d5f4e6;
    color: #27ae60;
}

.days-cell.warning {
    background: #fef5e7;
    color: #f39c12;
}

.days-cell.critical {
    background: #fdedec;
    color: #e74c3c;
}

.fs-count {
    display: inline-block;
    padding: 2px 8px;
    background: #ecf0f1;
    color: #7f8c8d;
    border-radius: 10px;
    font-size: 11px;
    font-weight: bold;
}
</style>
