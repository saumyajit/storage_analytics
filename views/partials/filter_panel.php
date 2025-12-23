<?php
/**
 * Filter Panel Partial
 */
?>

<div class="filter-section">
    <button type="button" class="btn-filter-toggle btn-alt" id="filter-toggle">
        <span class="toggle-icon">▼</span> <?= _('Filters') ?>
        <?php if ($filter['filter_enabled']): ?>
            <span class="filter-badge"><?= _('Active') ?></span>
        <?php endif; ?>
    </button>
    
    <div class="filter-panel" id="filter-panel" style="<?= $filter['filter_enabled'] ? '' : 'display: none;' ?>">
        <form id="filter-form" method="get" action="zabbix.php">
            <input type="hidden" name="action" value="storage.analytics">
            
            <div class="filter-grid">
                <!-- Host Groups -->
                <div class="filter-group">
                    <label for="groupids"><?= _('Host Groups') ?></label>
                    <select id="groupids" name="groupids[]" multiple class="multiselect" data-placeholder="<?= _('All groups') ?>">
                        <?php foreach ($filterOptions['hostgroups'] as $group): ?>
                            <option value="<?= $group['id'] ?>" <?= $group['selected'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($group['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Hosts -->
                <div class="filter-group">
                    <label for="hostids"><?= _('Hosts') ?></label>
                    <select id="hostids" name="hostids[]" multiple class="multiselect" data-placeholder="<?= _('All hosts') ?>">
                        <?php foreach ($filterOptions['hosts'] as $host): ?>
                            <option value="<?= $host['id'] ?>" <?= $host['selected'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($host['name']) ?> (<?= htmlspecialchars($host['host']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Time Range -->
                <div class="filter-group">
                    <label for="time_range"><?= _('Analysis Period') ?></label>
                    <select id="time_range" name="time_range" class="select">
                        <?php foreach ($filterOptions['time_ranges'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $filter['time_range'] == $value ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Prediction Method -->
                <div class="filter-group">
                    <label for="prediction_method"><?= _('Prediction Method') ?></label>
                    <select id="prediction_method" name="prediction_method" class="select">
                        <?php foreach ($filterOptions['prediction_methods'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $filter['prediction_method'] == $value ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Thresholds -->
                <div class="filter-group threshold-group">
                    <label><?= _('Thresholds') ?></label>
                    <div class="threshold-inputs">
                        <div class="threshold-input">
                            <span class="threshold-label warning"><?= _('Warning') ?>:</span>
                            <input type="number" id="warning_threshold" name="warning_threshold" 
                                   value="<?= $filter['warning_threshold'] ?>" min="0" max="100" step="1" class="input-small">
                            <span class="threshold-unit">%</span>
                        </div>
                        <div class="threshold-input">
                            <span class="threshold-label critical"><?= _('Critical') ?>:</span>
                            <input type="number" id="critical_threshold" name="critical_threshold" 
                                   value="<?= $filter['critical_threshold'] ?>" min="0" max="100" step="1" class="input-small">
                            <span class="threshold-unit">%</span>
                        </div>
                    </div>
                </div>
                
                <!-- Auto-refresh -->
                <div class="filter-group refresh-group">
                    <label><?= _('Auto-refresh') ?></label>
                    <div class="refresh-controls">
                        <label class="checkbox-label">
                            <input type="checkbox" id="refresh_enabled" name="refresh_enabled" value="1" 
                                   <?= $filter['refresh_enabled'] ? 'checked' : '' ?>>
                            <?= _('Enabled') ?>
                        </label>
                        
                        <select id="refresh" name="refresh" class="select" <?= !$filter['refresh_enabled'] ? 'disabled' : '' ?>>
                            <?php foreach ($filterOptions['refresh_intervals'] as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $filter['refresh'] == $value ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <span class="refresh-status" id="refresh-status">
                            <?php if ($filter['refresh_enabled'] && $filter['refresh'] > 0): ?>
                                <?= sprintf(_('Refreshing every %s seconds'), $filter['refresh']) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Filter Actions -->
            <div class="filter-actions">
                <button type="submit" class="btn-apply btn-main">
                    <span class="icon-apply"></span> <?= _('Apply Filters') ?>
                </button>
                <button type="button" class="btn-clear btn-alt" id="clear-filters">
                    <?= _('Clear All') ?>
                </button>
                <button type="button" class="btn-toggle-advanced btn-alt" id="toggle-advanced">
                    <?= _('Advanced') ?> <span class="toggle-icon">▶</span>
                </button>
            </div>
            
            <!-- Advanced Filters (Initially hidden) -->
            <div class="advanced-filters" id="advanced-filters" style="display: none;">
                <div class="filter-grid">
                    <!-- Host Search -->
                    <div class="filter-group">
                        <label for="host"><?= _('Host Search') ?></label>
                        <input type="text" id="host" name="host" value="<?= htmlspecialchars($filter['host']) ?>" 
                               placeholder="<?= _('Search by host name') ?>" class="input">
                    </div>
                    
                    <!-- Tags Filter (simplified) -->
                    <div class="filter-group">
                        <label for="tags"><?= _('Tags') ?></label>
                        <input type="text" id="tags" name="tags" value="<?= htmlspecialchars(implode(', ', $filter['tags'])) ?>" 
                               placeholder="<?= _('tag:value, tag2:value2') ?>" class="input">
                        <small class="filter-hint"><?= _('Comma-separated key:value pairs') ?></small>
                    </div>
                    
                    <!-- Page Size -->
                    <div class="filter-group">
                        <label for="page_limit"><?= _('Results per page') ?></label>
                        <select id="page_limit" name="page_limit" class="select">
                            <option value="10" <?= $page_limit == 10 ? 'selected' : '' ?>>10</option>
                            <option value="25" <?= $page_limit == 25 ? 'selected' : '' ?>>25</option>
                            <option value="50" <?= $page_limit == 50 ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $page_limit == 100 ? 'selected' : '' ?>>100</option>
                            <option value="250" <?= $page_limit == 250 ? 'selected' : '' ?>>250</option>
                        </select>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
