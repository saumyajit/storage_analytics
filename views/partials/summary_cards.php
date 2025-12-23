<?php
/**
 * Summary Cards Partial
 */
?>

<div class="summary-cards">
    <!-- Total Storage Card -->
    <div class="summary-card card-total">
        <div class="card-icon">💾</div>
        <div class="card-content">
            <h3><?= _('Total Storage') ?></h3>
            <p class="card-subtitle"><?= _('Across all selected hosts') ?></p>
            <div class="card-value"><?= $summary['total_capacity'] ?></div>
            <div class="card-progress">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= min($summary['total_usage_pct'], 100) ?>%"></div>
                </div>
                <div class="progress-label">
                    <span class="progress-percent"><?= $summary['total_usage_pct'] ?>%</span>
                    <span class="progress-text"><?= _('used') ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Used Storage Card -->
    <div class="summary-card card-used">
        <div class="card-icon">📊</div>
        <div class="card-content">
            <h3><?= _('Used Storage') ?></h3>
            <p class="card-subtitle"><?= _('of total capacity') ?></p>
            <div class="card-value"><?= $summary['total_used'] ?></div>
            <div class="card-stats">
                <div class="stat-item">
                    <span class="stat-label warning"><?= _('Warning') ?>:</span>
                    <span class="stat-value"><?= $summary['warning_count'] ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label critical"><?= _('Critical') ?>:</span>
                    <span class="stat-value"><?= $summary['critical_count'] ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Growth Rate Card -->
    <div class="summary-card card-growth">
        <div class="card-icon">📈</div>
        <div class="card-content">
            <h3><?= _('Avg Daily Growth') ?></h3>
            <p class="card-subtitle"><?= _('Based on historical data') ?></p>
            <div class="card-value"><?= $summary['avg_daily_growth_fmt'] ?></div>
            <div class="card-stats">
                <div class="stat-item">
                    <span class="stat-label"><?= _('Hosts') ?>:</span>
                    <span class="stat-value"><?= $summary['total_hosts'] ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label"><?= _('Filesystems') ?>:</span>
                    <span class="stat-value"><?= $summary['total_filesystems'] ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Prediction Card -->
    <div class="summary-card card-prediction">
        <div class="card-icon">⏰</div>
        <div class="card-content">
            <h3><?= _('Earliest Full') ?></h3>
            <p class="card-subtitle"><?= _('Based on current growth') ?></p>
            <div class="card-value">
                <?php if ($summary['earliest_full']): ?>
                    <?= $summary['earliest_full']['days'] ?> <?= _('days') ?>
                <?php else: ?>
                    <?= _('N/A') ?>
                <?php endif; ?>
            </div>
            <div class="card-details">
                <?php if ($summary['earliest_full']): ?>
                    <div class="detail-item">
                        <span class="detail-label"><?= _('Host') ?>:</span>
                        <span class="detail-value"><?= htmlspecialchars($summary['earliest_full']['host']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label"><?= _('Date') ?>:</span>
                        <span class="detail-value"><?= $summary['earliest_full']['date'] ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Top Growers Mini Table -->
<?php if (!empty($summary['top_growers'])): ?>
<div class="top-growers">
    <h4><?= _('Fastest Growing Filesystems') ?></h4>
    <table class="mini-table">
        <thead>
            <tr>
                <th><?= _('Host') ?></th>
                <th><?= _('Mount') ?></th>
                <th><?= _('Growth/Day') ?></th>
                <th><?= _('Days Left') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($summary['top_growers'] as $grower): ?>
            <tr>
                <td><?= htmlspecialchars($grower['host']) ?></td>
                <td><?= htmlspecialchars($grower['mount']) ?></td>
                <td class="growth-cell">
                    <span class="growth-badge rapid"><?= $grower['daily_growth'] ?></span>
                </td>
                <td>
                    <span class="days-badge <?= $grower['status'] ?>">
                        <?= $grower['days_until_full'] ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
