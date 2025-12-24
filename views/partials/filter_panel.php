<?php
/**
 * Filter Panel Partial
 */

// For inline use, we need the data
if (!isset($filterOptions) || !isset($filter)) {
    return;
}
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
            <input type="hidden" name="filter_enabled" value="1">
            
            <div class="filter-grid">
                <!-- Step 1: Select Multiple Host Groups -->
                <div class="filter-group">
                    <label for="groupids"><?= _('Host Groups') ?></label>
                    <select id="groupids" name="groupids[]" multiple class="select" size="5">
                        <option value=""><?= _('-- All groups --') ?></option>
                        <?php foreach ($filterOptions['hostgroups'] as $group): ?>
                            <option value="<?= $group['id'] ?>" 
                                    <?= in_array($group['id'], $filter['groupids'] ?? []) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($group['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="filter-hint"><?= _('Hold Ctrl/Cmd to select multiple groups') ?></small>
                </div>
                
                <!-- Step 2: Hosts will be auto-updated based on selected groups -->
                <div class="filter-group">
                    <label for="hostids"><?= _('Hosts') ?></label>
                    <div id="hosts-container">
                        <!-- This will be dynamically populated -->
                        <select id="hostids" name="hostids[]" multiple class="select" 
                                size="5" <?= empty($filter['groupids']) ? 'disabled' : '' ?>>
                            <option value=""><?= _('All hosts in selected groups') ?></option>
                            <?php 
                            // Get selected group IDs
                            $selectedGroupIds = $filter['groupids'] ?? [];
                            
                            // Filter hosts: show only hosts that belong to ANY selected group
                            $displayedHosts = 0;
                            foreach ($filterOptions['hosts'] as $host): 
                                $hostGroupIds = $host['groupids'] ?? [];
                                
                                // Show host if:
                                // 1. No groups selected (show all), OR
                                // 2. Host belongs to ANY selected group
                                if (empty($selectedGroupIds) || 
                                    array_intersect($selectedGroupIds, $hostGroupIds)): 
                                    $displayedHosts++;
                            ?>
                                <option value="<?= $host['id'] ?>" 
                                        data-groups="<?= implode(',', $hostGroupIds) ?>"
                                        <?= in_array($host['id'], $filter['hostids'] ?? []) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($host['name']) ?> (<?= htmlspecialchars($host['host']) ?>)
                                </option>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </select>
                    </div>
                    <small class="filter-hint" id="host-count-hint">
                        <?php 
                        if (empty($selectedGroupIds)) {
                            echo _('Select host groups to see available hosts');
                        } else {
                            echo sprintf(_('%d hosts available in selected groups'), $displayedHosts);
                        }
                        ?>
                    </small>
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
            </div>
        </form>
    </div>
</div>

<style>
/* Loading indicator */
.loading-indicator {
    display: inline-block;
    margin-left: 10px;
    color: #3498db;
    font-size: 12px;
}

.loading-indicator::after {
    content: '...';
    animation: dots 1.5s infinite;
}

@keyframes dots {
    0%, 20% { content: '.'; }
    40% { content: '..'; }
    60%, 100% { content: '...'; }
}

/* Disabled state */
.select:disabled {
    background-color: #f5f5f5;
    cursor: not-allowed;
    opacity: 0.7;
}

/* Auto-update notification */
.auto-update-notice {
    background: #e8f4fc;
    border: 1px solid #b3d9ff;
    border-radius: 3px;
    padding: 8px 12px;
    margin: 10px 0;
    font-size: 12px;
    color: #0066cc;
    display: none;
}

/* Better multiselect styling */
.select[multiple] {
    min-height: 120px;
}

.select[multiple] option {
    padding: 5px 8px;
    border-bottom: 1px solid #eee;
}

.select[multiple] option:hover {
    background-color: #f5f5f5;
}

.select[multiple] option:checked {
    background-color: #3498db;
    color: white;
}

.filter-actions button {
    display: flex;
    align-items: center;
    justify-content: center;
}

</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Store all hosts data from PHP
    const allHosts = <?= json_encode($filterOptions['hosts']) ?>;
    
    // Store current host selections
    const currentHostSelections = <?= json_encode($filter['hostids'] ?? []) ?>;
    
    // Get DOM elements
    const groupSelect = document.getElementById('groupids');
    const hostsContainer = document.getElementById('hosts-container');
    const hostCountHint = document.getElementById('host-count-hint');
    const filterForm = document.getElementById('filter-form');
    const clearButton = document.getElementById('clear-filters');
    
    // Debug logging
    console.log('Initializing filter with:', {
        totalHosts: allHosts.length,
        currentSelections: currentHostSelections,
        selectedGroups: Array.from(groupSelect.selectedOptions).map(o => o.value)
    });
    
    // Initialize the hosts list based on current selection
    updateHostsList();
    
    // When groups change, update hosts list immediately
    groupSelect.addEventListener('change', function() {
        console.log('Group selection changed');
        updateHostsList();
    });
    
    // Clear all filters button
    if (clearButton) {
        clearButton.addEventListener('click', function(e) {
            e.preventDefault();
            groupSelect.selectedIndex = -1;
            updateHostsList();
            filterForm.submit();
        });
    }
    
    // Function to update hosts list based on selected groups
    function updateHostsList() {
        const selectedGroupIds = getSelectedGroupIds();
        console.log('Updating hosts for groups:', selectedGroupIds);
        
        if (selectedGroupIds.length === 0) {
            // No groups selected: show placeholder
            showNoGroupsSelected();
            return;
        }
        
        // Show loading indicator
        showLoadingIndicator();
        
        // Filter hosts: show only hosts that belong to ANY selected group
        const matchingHosts = filterHostsByGroups(selectedGroupIds);
        
        // Build the hosts dropdown
        renderHostsDropdown(matchingHosts);
        
        // Update the count hint
        updateHostCountHint(matchingHosts.length);
        
        // Auto-submit the form after a short delay
        if (matchingHosts.length > 0) {
            setTimeout(() => {
                console.log('Auto-submitting form with filtered hosts');
                filterForm.submit();
            }, 800); // 800ms delay to let user see the filtered hosts
        }
    }
    
    function getSelectedGroupIds() {
        return Array.from(groupSelect.selectedOptions)
            .map(option => option.value)
            .filter(value => value !== '' && value !== null);
    }
    
    function filterHostsByGroups(selectedGroupIds) {
        return allHosts.filter(host => {
            const hostGroupIds = host.groupids || [];
            
            // Check if host belongs to ANY selected group
            const hasMatchingGroup = selectedGroupIds.some(groupId => {
                return hostGroupIds.includes(parseInt(groupId));
            });
            
            return hasMatchingGroup;
        });
    }
    
    function renderHostsDropdown(hosts) {
        if (hosts.length === 0) {
            hostsContainer.innerHTML = `
                <select id="hostids" name="hostids[]" multiple class="select" size="5" disabled>
                    <option value=""><?= _("No hosts found in selected groups") ?></option>
                </select>
            `;
            return;
        }
        
        let optionsHtml = `
            <option value=""><?= _("All hosts in selected groups") ?></option>
        `;
        
        hosts.forEach(host => {
            const isSelected = currentHostSelections.includes(host.id.toString());
            const escapedName = escapeHtml(host.name || '');
            const escapedHost = escapeHtml(host.host || '');
            
            optionsHtml += `
                <option value="${host.id}" ${isSelected ? 'selected' : ''}>
                    ${escapedName} (${escapedHost})
                </option>
            `;
        });
        
        hostsContainer.innerHTML = `
            <select id="hostids" name="hostids[]" multiple class="select" size="5">
                ${optionsHtml}
            </select>
        `;
        
        // Re-attach change event to new select element
        const newHostSelect = document.getElementById('hostids');
        if (newHostSelect) {
            newHostSelect.addEventListener('change', function() {
                console.log('Manual host selection changed, submitting form');
                setTimeout(() => filterForm.submit(), 300);
            });
        }
    }
    
    function showNoGroupsSelected() {
        hostsContainer.innerHTML = `
            <select id="hostids" name="hostids[]" multiple class="select" size="5" disabled>
                <option value=""><?= _("Select host groups first") ?></option>
            </select>
        `;
        hostCountHint.textContent = '<?= _("Select host groups to see available hosts") ?>';
    }
    
    function showLoadingIndicator() {
        hostCountHint.innerHTML = `
            <span class="loading-indicator">
                <?= _("Filtering hosts") ?>...
            </span>
        `;
    }
    
    function updateHostCountHint(count) {
        if (count === 0) {
            hostCountHint.textContent = '<?= _("No hosts found in selected groups") ?>';
        } else {
            hostCountHint.textContent = `${count} <?= _("hosts available") ?>`;
        }
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Optional: Auto-expand filter panel if there's an active filter
    if (<?= $filter['filter_enabled'] ? 'true' : 'false' ?>) {
        const filterToggle = document.getElementById('filter-toggle');
        const filterPanel = document.getElementById('filter-panel');
        if (filterToggle && filterPanel) {
            filterPanel.style.display = 'block';
            const icon = filterToggle.querySelector('.toggle-icon');
            if (icon) icon.textContent = '▲';
        }
    }
    
    // Filter panel toggle
    const filterToggle = document.getElementById('filter-toggle');
    if (filterToggle) {
        filterToggle.addEventListener('click', function() {
            const panel = document.getElementById('filter-panel');
            const icon = this.querySelector('.toggle-icon');
            const isVisible = panel.style.display !== 'none';
            
            panel.style.display = isVisible ? 'none' : 'block';
            icon.textContent = isVisible ? '▼' : '▲';
        });
    }
});
</script>