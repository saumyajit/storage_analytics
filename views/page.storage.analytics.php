<?php
/**
 * Storage Analytics View Template
 * Part 1: Main Structure
 */

// Check access
if (!array_key_exists('data', $data) || !$data['data']) {
    show_error_message(_('No storage data found.'));
    return;
}

$storage_data = $data['data'];
$summary = $data['summary'];
$filter = $data['filter'];
$page = $data['page'];
$page_limit = $data['page_limit'];
$total_records = $data['total_records'];

// Calculate pagination
$total_pages = ceil($total_records / $page_limit);
$start_record = ($page - 1) * $page_limit + 1;
$end_record = min($page * $page_limit, $total_records);

// Prepare units
$units = [
    'B' => 1,
    'KB' => 1024,
    'MB' => 1048576,
    'GB' => 1073741824,
    'TB' => 1099511627776
];
?>

<!-- Main Container -->
<div class="<?= ZBX_STYLE_LAYOUT_WRAPPER ?> storage-analytics-container">
    
    <!-- Page Header -->
    <div class="header">
        <div class="header-left">
            <h1><?= _('Storage Analytics') ?></h1>
            <p class="header-subtitle"><?= _('Monitor disk space usage and predict storage capacity needs') ?></p>
        </div>
        <div class="header-right">
            <!-- Export buttons will be added here -->
        </div>
    </div>
    
    <!-- Filter Toggle Button -->
    <button type="button" class="btn-filter-toggle <?= ZBX_STYLE_BTN_ALT ?>">
        <span class="toggle-icon">▼</span> <?= _('Filters') ?>
    </button>
    
    <!-- Filter Panel (Initially hidden) -->
    <div class="filter-panel" id="filter-panel" style="display: none;">
        <?php require_once __DIR__ . '/parts/filter_panel.php'; ?>
    </div>
<?php
/**
 * Storage Analytics Filter Panel
 * Part 2: Filter Controls
 */
?>

<form id="filter-form" method="get" class="filter-form">
    <input type="hidden" name="action" value="storage.analytics">
    
    <div class="filter-grid">
        <!-- Host Groups Filter -->
        <div class="filter-group">
            <label for="groupids"><?= _('Host Groups') ?></label>
            <select id="groupids" name="groupids[]" multiple class="multiselect">
                <?php
                $groups = API::HostGroup()->get([
                    'output' => ['groupid', 'name'],
                    'sortfield' => 'name'
                ]);
                
                foreach ($groups as $group) {
                    $selected = in_array($group['groupid'], $filter['groupids'] ?? []);
                    echo '<option value="' . $group['groupid'] . '" ' . ($selected ? 'selected' : '') . '>';
                    echo $group['name'];
                    echo '</option>';
                }
                ?>
            </select>
        </div>
        
        <!-- Hosts Filter -->
        <div class="filter-group">
            <label for="hostids"><?= _('Hosts') ?></label>
            <select id="hostids" name="hostids[]" multiple class="multiselect">
                <?php
                $host_params = ['output' => ['hostid', 'host', 'name'], 'sortfield' => 'host'];
                if (!empty($filter['groupids'])) {
                    $host_params['groupids'] = $filter['groupids'];
                }
                
                $hosts = API::Host()->get($host_params);
                
                foreach ($hosts as $host) {
                    $selected = in_array($host['hostid'], $filter['hostids'] ?? []);
                    $display_name = $host['name'] ?: $host['host'];
                    echo '<option value="' . $host['hostid'] . '" ' . ($selected ? 'selected' : '') . '>';
                    echo htmlspecialchars($display_name);
                    echo '</option>';
                }
                ?>
            </select>
        </div>
        
        <!-- Time Range -->
        <div class="filter-group">
            <label for="time_range"><?= _('Analysis Period') ?></label>
            <select id="time_range" name="time_range" class="select">
                <option value="7" <?= $filter['time_range'] == 7 ? 'selected' : '' ?>><?= _('Last 7 days') ?></option>
                <option value="30" <?= $filter['time_range'] == 30 ? 'selected' : '' ?>><?= _('Last 30 days') ?></option>
                <option value="90" <?= $filter['time_range'] == 90 ? 'selected' : '' ?>><?= _('Last 90 days') ?></option>
                <option value="180" <?= $filter['time_range'] == 180 ? 'selected' : '' ?>><?= _('Last 6 months') ?></option>
                <option value="365" <?= $filter['time_range'] == 365 ? 'selected' : '' ?>><?= _('Last year') ?></option>
            </select>
        </div>
        
        <!-- Prediction Method -->
        <div class="filter-group">
            <label for="prediction_method"><?= _('Prediction Method') ?></label>
            <select id="prediction_method" name="prediction_method" class="select">
                <option value="simple" <?= $filter['prediction_method'] == 'simple' ? 'selected' : '' ?>>
                    <?= _('Simple Linear') ?>
                </option>
                <option value="seasonal" <?= $filter['prediction_method'] == 'seasonal' ? 'selected' : '' ?>>
                    <?= _('Seasonal Adjusted') ?>
                </option>
            </select>
        </div>
        
        <!-- Warning Threshold -->
        <div class="filter-group">
            <label for="warning_threshold"><?= _('Warning Threshold (%)') ?></label>
            <input type="number" id="warning_threshold" name="warning_threshold" 
                   value="<?= $filter['warning_threshold'] ?>" min="0" max="100" step="1" class="input">
        </div>
        
        <!-- Critical Threshold -->
        <div class="filter-group">
            <label for="critical_threshold"><?= _('Critical Threshold (%)') ?></label>
            <input type="number" id="critical_threshold" name="critical_threshold" 
                   value="<?= $filter['critical_threshold'] ?>" min="0" max="100" step="1" class="input">
        </div>
        
        <!-- Auto-refresh Controls -->
        <div class="filter-group refresh-controls">
            <label><?= _('Auto-refresh') ?></label>
            <div class="refresh-options">
                <label class="checkbox-label">
                    <input type="checkbox" id="refresh_enabled" name="refresh_enabled" value="1" 
                           <?= $filter['refresh_enabled'] ? 'checked' : '' ?>>
                    <?= _('Enabled') ?>
                </label>
                
                <select id="refresh" name="refresh" class="select refresh-interval">
                    <option value="0" <?= $filter['refresh'] == 0 ? 'selected' : '' ?>><?= _('Manual') ?></option>
                    <option value="30" <?= $filter['refresh'] == 30 ? 'selected' : '' ?>><?= _('30 seconds') ?></option>
                    <option value="60" <?= $filter['refresh'] == 60 ? 'selected' : '' ?>><?= _('1 minute') ?></option>
                    <option value="120" <?= $filter['refresh'] == 120 ? 'selected' : '' ?>><?= _('2 minutes') ?></option>
                    <option value="300" <?= $filter['refresh'] == 300 ? 'selected' : '' ?>><?= _('5 minutes') ?></option>
                </select>
            </div>
        </div>
    </div>
    
    <!-- Filter Actions -->
    <div class="filter-actions">
        <button type="submit" class="<?= ZBX_STYLE_BTN_ALT ?>">
            <span class="icon-filter"></span> <?= _('Apply Filters') ?>
        </button>
        <button type="button" class="<?= ZBX_STYLE_BTN_ALT ?> btn-clear-filters">
            <?= _('Clear All') ?>
        </button>
    </div>
</form>

<script>
// Filter panel toggle
document.querySelector('.btn-filter-toggle').addEventListener('click', function() {
    const panel = document.getElementById('filter-panel');
    const icon = this.querySelector('.toggle-icon');
    
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        icon.textContent = '▲';
    } else {
        panel.style.display = 'none';
        icon.textContent = '▼';
    }
});

// Clear filters
document.querySelector('.btn-clear-filters').addEventListener('click', function() {
    document.getElementById('filter-form').reset();
    document.getElementById('filter-form').submit();
});
</script>
<!-- Summary Cards Section -->
<div class="summary-cards">
    
    <!-- Total Storage Card -->
    <div class="summary-card">
        <div class="card-header">
            <h3><?= _('Total Storage') ?></h3>
            <span class="card-subtitle"><?= _('Across all selected hosts') ?></span>
        </div>
        <div class="card-body">
            <div class="card-value"><?= $summary['total_capacity_fmt'] ?></div>
            <div class="card-progress">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= min($summary['total_usage_percent'], 100) ?>%"></div>
                </div>
                <div class="progress-label">
                    <span><?= round($summary['total_usage_percent'], 1) ?>%</span>
                    <span><?= _('used') ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Used Storage Card -->
    <div class="summary-card">
        <div class="card-header">
            <h3><?= _('Used Storage') ?></h3>
            <span class="card-subtitle"><?= _('of total capacity') ?></span>
        </div>
        <div class="card-body">
            <div class="card-value"><?= $summary['total_used_fmt'] ?></div>
            <div class="card-details">
                <div class="detail-item">
                    <span class="detail-label"><?= _('Warning') ?>:</span>
                    <span class="detail-value warning"><?= $summary['warning_count'] ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><?= _('Critical') ?>:</span>
                    <span class="detail-value critical"><?= $summary['critical_count'] ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Average Daily Growth Card -->
    <div class="summary-card">
        <div class="card-header">
            <h3><?= _('Average Daily Growth') ?></h3>
            <span class="card-subtitle"><?= _('Based on historical data') ?></span>
        </div>
        <div class="card-body">
            <div class="card-value"><?= $summary['avg_daily_growth_fmt'] ?>/day</div>
            <div class="card-details">
                <div class="detail-item">
                    <span class="detail-label"><?= _('Hosts') ?>:</span>
                    <span class="detail-value"><?= $summary['total_hosts'] ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><?= _('Filesystems') ?>:</span>
                    <span class="detail-value"><?= $summary['total_filesystems'] ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Prediction Card -->
    <div class="summary-card">
        <div class="card-header">
            <h3><?= _('Earliest Full') ?></h3>
            <span class="card-subtitle"><?= _('Based on current growth') ?></span>
        </div>
        <div class="card-body">
            <?php
            // Find filesystem with shortest days until full
            $earliest_full = null;
            foreach ($storage_data as $host) {
                foreach ($host['filesystems'] as $fs) {
                    if (isset($fs['days_until_full']) && $fs['days_until_full'] > 0) {
                        if ($earliest_full === null || $fs['days_until_full'] < $earliest_full['days']) {
                            $earliest_full = [
                                'host' => $host['host_name'],
                                'fs' => $fs['name'],
                                'days' => $fs['days_until_full'],
                                'date' => date('Y-m-d', time() + ($fs['days_until_full'] * 86400))
                            ];
                        }
                    }
                }
            }
            ?>
            <div class="card-value">
                <?php if ($earliest_full): ?>
                    <?= $earliest_full['days'] ?> <?= _('days') ?>
                <?php else: ?>
                    <?= _('N/A') ?>
                <?php endif; ?>
            </div>
            <div class="card-details">
                <?php if ($earliest_full): ?>
                    <div class="detail-item">
                        <span class="detail-label"><?= _('Host') ?>:</span>
                        <span class="detail-value"><?= htmlspecialchars($earliest_full['host']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label"><?= _('Filesystem') ?>:</span>
                        <span class="detail-value"><?= htmlspecialchars($earliest_full['fs']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label"><?= _('Date') ?>:</span>
                        <span class="detail-value"><?= $earliest_full['date'] ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<!-- Main Table Section -->
<div class="table-section">
    <div class="table-header">
        <h2><?= _('Storage Details') ?></h2>
        <div class="table-actions">
            <span class="pagination-info">
                <?= sprintf(_('Showing %1$s to %2$s of %3$s entries'), $start_record, $end_record, $total_records) ?>
            </span>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?action=storage.analytics&page=<?= $page - 1 ?><?= $this->buildQueryString($filter, ['page']) ?>"
                       class="pagination-link"><?= _('Previous') ?></a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="pagination-current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?action=storage.analytics&page=<?= $i ?><?= $this->buildQueryString($filter, ['page']) ?>"
                           class="pagination-link"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?action=storage.analytics&page=<?= $page + 1 ?><?= $this->buildQueryString($filter, ['page']) ?>"
                       class="pagination-link"><?= _('Next') ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Hosts Table -->
    <div class="table-container">
        <table class="list-table storage-table">
            <thead>
                <tr>
                    <th><?= _('Host') ?></th>
                    <th><?= _('Total Space') ?></th>
                    <th><?= _('Used Space') ?></th>
                    <th><?= _('Usage %') ?></th>
                    <th><?= _('Growth Rate') ?></th>
                    <th><?= _('Days Until Full') ?></th>
                    <th><?= _('Status') ?></th>
                    <th><?= _('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 0;
                foreach ($storage_data as $host):
                    if ($counter++ < $start_record - 1) continue;
                    if ($counter > $end_record) break;
                    
                    // Calculate host-level summary
                    $host_total = 0;
                    $host_used = 0;
                    $host_growth = 0;
                    $host_earliest_full = null;
                    $fs_count = 0;
                    
                    foreach ($host['filesystems'] as $fs) {
                        if (isset($fs['total'])) $host_total += $fs['total'];
                        if (isset($fs['used'])) $host_used += $fs['used'];
                        if (isset($fs['daily_growth']) && $fs['daily_growth'] > 0) {
                            $host_growth += $fs['daily_growth'];
                        }
                        if (isset($fs['days_until_full']) && $fs['days_until_full'] > 0) {
                            if ($host_earliest_full === null || $fs['days_until_full'] < $host_earliest_full) {
                                $host_earliest_full = $fs['days_until_full'];
                            }
                        }
                        $fs_count++;
                    }
                    
                    $host_usage_percent = $host_total > 0 ? ($host_used / $host_total) * 100 : 0;
                    $host_avg_growth = $fs_count > 0 ? $host_growth / $fs_count : 0;
                    
                    // Determine status
                    $status_class = '';
                    if ($host_usage_percent >= $filter['critical_threshold']) {
                        $status_class = 'status-critical';
                    } elseif ($host_usage_percent >= $filter['warning_threshold']) {
                        $status_class = 'status-warning';
                    } else {
                        $status_class = 'status-ok';
                    }
                ?>
                <tr class="host-row" data-hostid="<?= $host['hostid'] ?>">
                    <td class="host-cell">
                        <strong><?= htmlspecialchars($host['host_name']) ?></strong>
                        <div class="host-details">
                            <span class="detail-item"><?= $fs_count ?> <?= _('filesystems') ?></span>
                        </div>
                    </td>
                    <td><?= $this->formatBytes($host_total) ?></td>
                    <td><?= $this->formatBytes($host_used) ?></td>
                    <td>
                        <div class="usage-cell">
                            <span class="usage-value"><?= round($host_usage_percent, 1) ?>%</span>
                            <div class="usage-bar">
                                <div class="usage-fill" style="width: <?= min($host_usage_percent, 100) ?>%"></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($host_avg_growth > 0): ?>
                            <span class="growth-positive">+<?= $this->formatBytes($host_avg_growth) ?>/day</span>
                        <?php elseif ($host_avg_growth < 0): ?>
                            <span class="growth-negative"><?= $this->formatBytes($host_avg_growth) ?>/day</span>
                        <?php else: ?>
                            <span class="growth-neutral"><?= _('Stable') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($host_earliest_full !== null): ?>
                            <span class="days-until <?= $host_earliest_full <= 30 ? 'days-critical' : ($host_earliest_full <= 90 ? 'days-warning' : 'days-ok') ?>">
                                <?= $host_earliest_full ?> <?= _('days') ?>
                            </span>
                        <?php else: ?>
                            <span class="days-na"><?= _('N/A') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge <?= $status_class ?>">
                            <?php
                            if ($host_usage_percent >= $filter['critical_threshold']) {
                                echo _('Critical');
                            } elseif ($host_usage_percent >= $filter['warning_threshold']) {
                                echo _('Warning');
                            } else {
                                echo _('OK');
                            }
                            ?>
                        </span>
                    </td>
                    <td>
                        <button type="button" class="btn-details <?= ZBX_STYLE_BTN_ALT ?>" 
                                data-hostid="<?= $host['hostid'] ?>">
                            <?= _('Details') ?>
                        </button>
                    </td>
                </tr>
                
                <!-- Filesystem Details Row (hidden by default) -->
                <tr class="fs-details-row" id="fs-details-<?= $host['hostid'] ?>" style="display: none;">
                    <td colspan="8">
                        <?php require_once __DIR__ . '/parts/filesystem_details.php'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($storage_data)): ?>
                <tr>
                    <td colspan="8" class="no-data">
                        <?= _('No storage data found matching the filters.') ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
/**
 * Storage Analytics Filesystem Details
 * Part 5: Individual Filesystem Table
 */
// This file is included within the main template
// $host variable is passed from parent context
?>

<div class="filesystem-details">
    <h4><?= _('Filesystem Details for') ?>: <?= htmlspecialchars($host['host_name']) ?></h4>
    
    <table class="fs-details-table">
        <thead>
            <tr>
                <th><?= _('Mount Point') ?></th>
                <th><?= _('Total') ?></th>
                <th><?= _('Used') ?></th>
                <th><?= _('Free') ?></th>
                <th><?= _('Usage %') ?></th>
                <th><?= _('Daily Growth') ?></th>
                <th><?= _('Days Until Full') ?></th>
                <th><?= _('Suggestions') ?></th>
                <th><?= _('Seasonal Pattern') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($host['filesystems'] as $fs_name => $fs): ?>
            <?php
            $free = isset($fs['total']) && isset($fs['used']) ? $fs['total'] - $fs['used'] : 0;
            $free_percent = isset($fs['total']) && $fs['total'] > 0 ? ($free / $fs['total']) * 100 : 0;
            
            // Determine suggestion
            $suggestion = '';
            if (isset($fs['usage_percent']) && $fs['usage_percent'] >= 80) {
                if (isset($host['filesystems']['/home']['free'])) {
                    $home_free = $host['filesystems']['/home']['free'] ?? 0;
                    if ($home_free > 10737418240) { // 10GB
                        $suggestion = sprintf(_('Move approx %s from /home'), $this->formatBytes(min($home_free * 0.1, 1073741824 * 20)));
                    }
                }
            }
            ?>
            <tr class="<?= isset($fs['usage_percent']) && $fs['usage_percent'] >= 90 ? 'row-critical' : 
                        (isset($fs['usage_percent']) && $fs['usage_percent'] >= 80 ? 'row-warning' : '') ?>">
                <td class="fs-name">
                    <strong><?= htmlspecialchars($fs_name) ?></strong>
                </td>
                <td><?= isset($fs['total']) ? $this->formatBytes($fs['total']) : 'N/A' ?></td>
                <td><?= isset($fs['used']) ? $this->formatBytes($fs['used']) : 'N/A' ?></td>
                <td><?= $this->formatBytes($free) ?></td>
                <td>
                    <div class="usage-cell">
                        <?php if (isset($fs['usage_percent'])): ?>
                        <span class="usage-value"><?= round($fs['usage_percent'], 1) ?>%</span>
                        <div class="usage-bar">
                            <div class="usage-fill" style="width: <?= min($fs['usage_percent'], 100) ?>%"></div>
                        </div>
                        <?php else: ?>
                        N/A
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <?php if (isset($fs['daily_growth'])): ?>
                        <?php if ($fs['daily_growth'] > 0): ?>
                            <span class="growth-positive">+<?= $this->formatBytes($fs['daily_growth']) ?>/day</span>
                        <?php elseif ($fs['daily_growth'] < 0): ?>
                            <span class="growth-negative"><?= $this->formatBytes($fs['daily_growth']) ?>/day</span>
                        <?php else: ?>
                            <span class="growth-neutral"><?= _('Stable') ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (isset($fs['days_until_full']) && $fs['days_until_full'] >= 0): ?>
                        <span class="days-until <?= $fs['days_until_full'] <= 30 ? 'days-critical' : 
                                                ($fs['days_until_full'] <= 90 ? 'days-warning' : 'days-ok') ?>">
                            <?= $fs['days_until_full'] ?> <?= _('days') ?>
                        </span>
                        <?php if ($fs['days_until_full'] > 0): ?>
                            <div class="prediction-date">
                                (<?= date('Y-m-d', time() + ($fs['days_until_full'] * 86400)) ?>)
                            </div>
                        <?php endif; ?>
                    <?php elseif (isset($fs['days_until_full']) && $fs['days_until_full'] == -1): ?>
                        <span class="days-na"><?= _('No growth') ?></span>
                    <?php else: ?>
                        <span class="days-na"><?= _('N/A') ?></span>
                    <?php endif; ?>
                </td>
                <td class="suggestion-cell">
                    <?php if ($suggestion): ?>
                        <span class="suggestion"><?= $suggestion ?></span>
                    <?php else: ?>
                        <span class="no-suggestion">-</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (isset($fs['seasonal_pattern']) && !empty($fs['seasonal_pattern'])): ?>
                        <button type="button" class="btn-pattern <?= ZBX_STYLE_BTN_ALT ?>" 
                                data-fs="<?= htmlspecialchars($fs_name) ?>"
                                data-host="<?= htmlspecialchars($host['host_name']) ?>">
                            <?= _('View Pattern') ?>
                        </button>
                    <?php else: ?>
                        <span class="no-pattern">-</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<!-- Footer -->
<div class="footer-info">
    <div class="footer-left">
        <span class="calculation-info">
            <?= _('Calculations based on') ?>: 
            <strong><?= $filter['time_range'] ?> <?= _('days') ?></strong> | 
            <?= _('Method') ?>: 
            <strong><?= $filter['prediction_method'] == 'seasonal' ? _('Seasonal Adjusted') : _('Simple Linear') ?></strong>
        </span>
    </div>
    <div class="footer-right">
        <span class="last-updated">
            <?= sprintf(_('Last updated: %s'), date('Y-m-d H:i:s')) ?>
        </span>
    </div>
</div>
</div> <!-- Close main container -->

<!-- JavaScript -->
<script>
// Auto-refresh functionality
class StorageAnalytics {
    constructor() {
        this.refreshInterval = null;
        this.init();
    }
    
    init() {
        // Toggle filesystem details
        document.querySelectorAll('.btn-details').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const hostId = e.target.dataset.hostid;
                const detailsRow = document.getElementById(`fs-details-${hostId}`);
                
                if (detailsRow.style.display === 'none') {
                    detailsRow.style.display = 'table-row';
                    e.target.textContent = '▲ ' + e.target.textContent.substring(2);
                } else {
                    detailsRow.style.display = 'none';
                    e.target.textContent = '▼ ' + e.target.textContent.substring(2);
                }
            });
        });
        
        // Initialize auto-refresh if enabled
        const refreshEnabled = document.getElementById('refresh_enabled');
        const refreshInterval = document.getElementById('refresh');
        
        if (refreshEnabled && refreshEnabled.checked && refreshInterval.value > 0) {
            this.startAutoRefresh(parseInt(refreshInterval.value));
        }
        
        // Add event listeners for refresh controls
        if (refreshEnabled) {
            refreshEnabled.addEventListener('change', () => this.toggleAutoRefresh());
        }
        
        if (refreshInterval) {
            refreshInterval.addEventListener('change', () => this.updateRefreshInterval());
        }
    }
    
    toggleAutoRefresh() {
        const enabled = document.getElementById('refresh_enabled').checked;
        const interval = parseInt(document.getElementById('refresh').value);
        
        if (enabled && interval > 0) {
            this.startAutoRefresh(interval);
        } else {
            this.stopAutoRefresh();
        }
    }
    
    startAutoRefresh(seconds) {
        this.stopAutoRefresh();
        this.refreshInterval = setInterval(() => {
            this.refreshData();
        }, seconds * 1000);
    }
    
    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    }
    
    updateRefreshInterval() {
        if (document.getElementById('refresh_enabled').checked) {
            const interval = parseInt(document.getElementById('refresh').value);
            if (interval > 0) {
                this.startAutoRefresh(interval);
            } else {
                this.stopAutoRefresh();
            }
        }
    }
    
    refreshData() {
        // Show loading indicator
        const table = document.querySelector('.storage-table');
        table.classList.add('loading');
        
        // Get current filter values
        const formData = new FormData(document.getElementById('filter-form'));
        const params = new URLSearchParams(formData);
        
        // Add current page
        params.set('page', <?= $page ?>);
        
        // Reload page with current filters
        window.location.href = `?${params.toString()}`;
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.storageAnalytics = new StorageAnalytics();
    
    // Format bytes in tables
    document.querySelectorAll('.format-bytes').forEach(el => {
        const bytes = parseInt(el.dataset.bytes);
        el.textContent = formatBytes(bytes);
    });
});

// Helper function to format bytes
function formatBytes(bytes, decimals = 2) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
    
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}
</script>

<!-- CSS Styles -->
<style>
.storage-analytics-container {
    padding: 20px;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.summary-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
}

.card-header h3 {
    margin: 0 0 5px 0;
    font-size: 14px;
    color: #333;
}

.card-subtitle {
    font-size: 12px;
    color: #999;
}

.card-value {
    font-size: 24px;
    font-weight: bold;
    margin: 10px 0;
    color: #333;
}

.progress-bar {
    height: 8px;
    background: #eee;
    border-radius: 4px;
    overflow: hidden;
    margin: 10px 0;
}

.progress-fill {
    height: 100%;
    background: #337ab7;
    transition: width 0.3s ease;
}

.usage-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.usage-bar {
    flex: 1;
    height: 6px;
    background: #eee;
    border-radius: 3px;
    overflow: hidden;
}

.usage-fill {
    height: 100%;
    background: #5cb85c;
}

.growth-positive { color: #d9534f; }
.growth-negative { color: #5cb85c; }
.growth-neutral { color: #777; }

.status-badge {
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
}

.status-ok { background: #dff0d8; color: #3c763d; }
.status-warning { background: #fcf8e3; color: #8a6d3b; }
.status-critical { background: #f2dede; color: #a94442; }

.days-critical { color: #a94442; font-weight: bold; }
.days-warning { color: #8a6d3b; }
.days-ok { color: #3c763d; }

.row-critical { background: #f2dede !important; }
.row-warning { background: #fcf8e3 !important; }

.btn-details {
    padding: 3px 8px;
    font-size: 12px;
}

.filter-panel {
    background: #f5f5f5;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin: 10px 0;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-group label {
    margin-bottom: 5px;
    font-weight: bold;
    font-size: 12px;
}

.refresh-controls .refresh-options {
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-section {
    margin-top: 30px;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.pagination {
    display: flex;
    gap: 5px;
}

.pagination-link, .pagination-current {
    padding: 5px 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.pagination-current {
    background: #337ab7;
    color: white;
    border-color: #337ab7;
}

.storage-table.loading {
    opacity: 0.7;
    pointer-events: none;
}

.filesystem-details {
    background: #f9f9f9;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin: 5px 0;
}

.fs-details-table {
    width: 100%;
    border-collapse: collapse;
}

.fs-details-table th,
.fs-details-table td {
    padding: 8px;
    border: 1px solid #ddd;
    text-align: left;
}

.footer-info {
    margin-top: 30px;
    padding-top: 15px;
    border-top: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    color: #777;
    font-size: 12px;
}
</style>
