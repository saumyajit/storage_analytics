<?php
/**
 * Storage Analytics - Main View Template
 * @var CView $this
 * @var array $data
 */

// Extract variables for cleaner access
$storageData = $data['storageData'] ?? [];
$summary = $data['summary'] ?? [];
$filter = $data['filter'] ?? [];
$filterOptions = $data['filterOptions'] ?? [];
$formatBytes = $data['formatBytes'] ?? function($b) { return $b; };
$buildQueryString = $data['buildQueryString'] ?? function() { return ''; };
$page = $data['page'] ?? 1;
$page_limit = $data['page_limit'] ?? 50;
$total_records = $data['total_records'] ?? 0;

// Calculate pagination
$total_pages = ceil($total_records / $page_limit);
$start_record = ($page - 1) * $page_limit + 1;
$end_record = min($page * $page_limit, $total_records);
?>

<!-- Main Container -->
<div class="storage-analytics-container" id="storage-analytics-container">
    
    <!-- Page Header -->
	<div class="header">
		<div class="header-left">
			<h1><?= _('Storage Analytics') ?></h1>
			<p class="header-subtitle"><?= _('Monitor disk space usage and predict storage capacity needs') ?></p>
		</div>
		<div class="header-right" style="display: flex; align-items: center; gap: 8px;">
			<!-- Simple Export Buttons -->
			<a href="zabbix.php?action=storage.analytics&export=csv<?= $buildQueryString($filter) ?>" 
			class="btn-alt" 
			style="display: inline-flex; align-items: center; gap: 5px; padding: 8px 12px; 
					text-decoration: none; border: 1px solid #bdc3c7; border-radius: 4px;"
			title="<?= _('Export as CSV') ?>">
				<span>📊</span> CSV
			</a>
			
			<a href="zabbix.php?action=storage.analytics&export=html<?= $buildQueryString($filter) ?>" 
			class="btn-alt" 
			style="display: inline-flex; align-items: center; gap: 5px; padding: 8px 12px; 
					text-decoration: none; border: 1px solid #bdc3c7; border-radius: 4px;"
			title="<?= _('Export as HTML') ?>">
				<span>📄</span> HTML
			</a>
			
			<a href="zabbix.php?action=storage.analytics&export=json<?= $buildQueryString($filter) ?>" 
			class="btn-alt" 
			style="display: inline-flex; align-items: center; gap: 5px; padding: 8px 12px; 
					text-decoration: none; border: 1px solid #bdc3c7; border-radius: 4px;"
			title="<?= _('Export as JSON') ?>">
				<span>{ }</span> JSON
			</a>
			
			<div class="last-updated">
				<?= sprintf(_('Updated: %s'), date('H:i:s')) ?>
			</div>
		</div>
	</div>
	
	<style>
	/* Hover effects for buttons */
	.btn-alt:hover {
		background-color: #e0e0e0 !important;
		border-color: #95a5a6 !important;
	}
	</style>
   
    <!-- Include Filter Panel -->
    <?php include __DIR__ . '/partials/filter_panel.php'; ?>
    
    <!-- Include Summary Cards -->
    <?php include __DIR__ . '/partials/summary_cards.php'; ?>
    
    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Include Host Table -->
        <?php include __DIR__ . '/partials/host_table.php'; ?>
    </div>
    
    <!-- Footer -->
    <div class="footer">
        <div class="footer-left">
            <span class="calculation-info">
                <?= _('Calculations based on') ?>: 
                <strong><?= $filterOptions['time_ranges'][$filter['time_range']] ?? $filter['time_range'] . ' days' ?></strong> | 
                <?= _('Method') ?>: 
                <strong><?= $filterOptions['prediction_methods'][$filter['prediction_method']] ?? $filter['prediction_method'] ?></strong>
            </span>
        </div>
        <div class="footer-right">
            <span class="record-count">
                <?php if ($total_records > 0): ?>
                    <?= sprintf(_('Showing %1$s-%2$s of %3$s hosts'), $start_record, $end_record, $total_records) ?>
                <?php else: ?>
                    <?= _('No data found') ?>
                <?php endif; ?>
            </span>
        </div>
    </div>
</div>

<!-- Include Scripts -->
<?php include __DIR__ . '/partials/scripts.php'; ?>

<!-- Include Styles -->
<?php include __DIR__ . '/partials/styles.php'; ?>
