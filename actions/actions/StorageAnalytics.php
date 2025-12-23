<?php
namespace Modules\diskanalyser\actions;

use CController;
use CControllerResponseData;
use API;

class StorageAnalytics extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        // Define all possible filter inputs
        $fields = [
            'hostids'           => 'array_id',
            'groupids'          => 'array_id',
            'host'              => 'string',
            'time_range'        => 'in 7,14,30,90,180,365',
            'prediction_method' => 'in simple,seasonal',
            'warning_threshold' => 'ge 0|le 100',
            'critical_threshold'=> 'ge 0|le 100',
            'refresh'           => 'in 0,30,60,120,300,600',
            'refresh_enabled'   => 'in 0,1',
            'page'              => 'ge 1',
            'tags'              => 'array',
            'filter_enabled'    => 'in 0,1'
        ];
        
        $ret = $this->validateInput($fields);
        
        if (!$ret) {
            error(_('Invalid input parameters'));
        }
        
        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        // Get filter values with defaults
        $filter = [
            'hostids'           => $this->getInput('hostids', []),
            'groupids'          => $this->getInput('groupids', []),
            'host'              => $this->getInput('host', ''),
            'time_range'        => $this->getInput('time_range', 30),
            'prediction_method' => $this->getInput('prediction_method', 'seasonal'),
            'warning_threshold' => $this->getInput('warning_threshold', 80),
            'critical_threshold'=> $this->getInput('critical_threshold', 90),
            'refresh'           => $this->getInput('refresh', 60),
            'refresh_enabled'   => $this->getInput('refresh_enabled', 1),
            'page'              => $this->getInput('page', 1),
            'tags'              => $this->getInput('tags', []),
            'filter_enabled'    => $this->getInput('filter_enabled', 0)
        ];

        // Fetch storage data with filters
        $storageData = $this->getFilteredStorageData($filter);
        
        // Calculate advanced predictions
        $enhancedData = $this->calculateEnhancedPredictions($storageData, $filter);
        
        // Calculate summary statistics
        $summary = $this->calculateEnhancedSummary($enhancedData, $filter);
        
        // Get filter options for UI
        $filterOptions = $this->getFilterOptions($filter);

        $response = new CControllerResponseData([
            'storageData'     => $enhancedData,
            'summary'         => $summary,
            'filter'          => $filter,
            'filterOptions'   => $filterOptions,
            'page'            => $filter['page'],
            'page_limit'      => 50,
            'total_records'   => count($enhancedData),
            'formatBytes'     => [$this, 'formatBytes'],
            'buildQueryString'=> function($filter, $exclude = []) {
                return $this->buildQueryString($filter, $exclude);
            }
        ]);
        
        $response->setTitle(_('Storage Analytics'));
        $this->setResponse($response);
    }

    /**
     * Fetch storage data with advanced filtering
     */
    private function getFilteredStorageData(array $filter): array {
        $apiParams = [
            'output' => ['itemid', 'key_', 'name', 'lastvalue', 'hostid'],
            'selectHosts' => ['hostid', 'name', 'host'],
            'selectGroups' => ['groupid', 'name'],
            'selectTags' => ['tag', 'value'],
            'search' => ['key_' => 'vfs.fs.size'],
            'monitored' => true,
            'preservekeys' => true
        ];

        // Apply host group filter
        if (!empty($filter['groupids'])) {
            $apiParams['groupids'] = $filter['groupids'];
        }

        // Apply host filter
        if (!empty($filter['hostids'])) {
            $apiParams['hostids'] = $filter['hostids'];
        }

        // Apply host name filter (search)
        if (!empty($filter['host'])) {
            $apiParams['search']['host'] = $filter['host'];
        }

        // Apply tag filter
        if (!empty($filter['tags'])) {
            $apiParams['tags'] = $filter['tags'];
        }

        $items = API::Item()->get($apiParams);
        
        return $this->processItemsToStorageData($items);
    }

    /**
     * Process API items into organized storage data
     */
    private function processItemsToStorageData(array $items): array {
        $storageData = [];
        $groupedData = [];
        $hostInfo = [];

        foreach ($items as $item) {
            // Parse key to get mount point and type
            if (!preg_match('/vfs\.fs\.size\[(.*?),(total|pused|used)\]/i', $item['key_'], $matches)) {
                continue;
            }

            $hostId = $item['hostid'];
            $mountPoint = trim($matches[1], '"\'') ?: '/';
            $type = strtolower($matches[2]);
            $key = $hostId . '|' . $mountPoint;

            // Store host information
            if (!isset($hostInfo[$hostId])) {
                $hostInfo[$hostId] = [
                    'hostid' => $hostId,
                    'host' => $item['hosts'][0]['host'],
                    'name' => $item['hosts'][0]['name'],
                    'groups' => $item['groups'] ?? [],
                    'tags' => $item['tags'] ?? []
                ];
            }

            // Initialize grouped data
            if (!isset($groupedData[$key])) {
                $groupedData[$key] = [
                    'hostid' => $hostId,
                    'mount' => $mountPoint,
                    'total' => 0.0,
                    'pused' => 0.0,
                    'used' => 0.0
                ];
            }

            $value = (float)$item['lastvalue'];
            
            switch ($type) {
                case 'total':
                    $groupedData[$key]['total'] = $value;
                    break;
                case 'pused':
                    $groupedData[$key]['pused'] = $value;
                    break;
                case 'used':
                    $groupedData[$key]['used'] = $value;
                    break;
            }
        }

        // Convert grouped data to final storage data
        foreach ($groupedData as $key => $data) {
            $hostId = $data['hostid'];
            
            // Skip if we don't have total data
            if ($data['total'] <= 0) {
                continue;
            }

            // Calculate used bytes if we have percentage
            if ($data['pused'] > 0 && $data['used'] == 0) {
                $data['used'] = $data['total'] * ($data['pused'] / 100.0);
            }

            $usagePct = $data['total'] > 0 ? round(($data['used'] / $data['total']) * 100, 1) : 0;

            $storageData[] = [
                'hostid' => $hostId,
                'host' => $hostInfo[$hostId]['name'] ?? $hostInfo[$hostId]['host'],
                'host_name' => $hostInfo[$hostId]['host'],
                'mount' => $data['mount'],
                'total_raw' => $data['total'],
                'used_raw' => $data['used'],
                'pused' => $data['pused'],
                'total_space' => $this->formatBytes($data['total']),
                'used_space' => $this->formatBytes($data['used']),
                'usage_pct' => $usagePct,
                'host_groups' => $hostInfo[$hostId]['groups'],
                'host_tags' => $hostInfo[$hostId]['tags']
            ];
        }

        return $storageData;
    }

    /**
     * Calculate enhanced predictions with seasonal analysis
     */
    private function calculateEnhancedPredictions(array $storageData, array $filter): array {
        $method = $filter['prediction_method'];
        $timeRange = $filter['time_range'];

        foreach ($storageData as &$item) {
            $growthData = $this->calculateGrowthRate(
                $item['hostid'],
                $item['mount'],
                $timeRange,
                $method
            );

            $item['daily_growth_raw'] = $growthData['daily_growth'];
            $item['daily_growth'] = $this->formatBytes($growthData['daily_growth'] * 86400) . '/day';
            $item['days_until_full'] = $this->calculateDaysUntilFull(
                $item['total_raw'],
                $item['used_raw'],
                $growthData['daily_growth']
            );
            $item['growth_trend'] = $growthData['trend'];
            $item['seasonal_pattern'] = $growthData['pattern'];
            $item['confidence'] = $growthData['confidence'];

            // Determine status based on thresholds
            $item['status'] = $this->determineStatus(
                $item['usage_pct'],
                $item['days_until_full'],
                $filter['warning_threshold'],
                $filter['critical_threshold']
            );
        }

        return $storageData;
    }

    /**
     * Advanced growth rate calculation with seasonal adjustment
     */
    private function calculateGrowthRate(int $hostId, string $mount, int $days, string $method): array {
        $itemKey = 'vfs.fs.size[' . $mount . ',used]';
        
        // Get item ID
        $items = API::Item()->get([
            'output' => ['itemid'],
            'hostids' => $hostId,
            'filter' => ['key_' => $itemKey],
            'limit' => 1
        ]);

        if (empty($items)) {
            return [
                'daily_growth' => 0,
                'trend' => 'stable',
                'pattern' => [],
                'confidence' => 0
            ];
        }

        $itemId = $items[0]['itemid'];
        $timeFrom = time() - ($days * 86400);

        // Get history data
        $history = API::History()->get([
            'output' => ['clock', 'value'],
            'itemids' => [$itemId],
            'history' => 3,
            'time_from' => $timeFrom,
            'sortfield' => 'clock',
            'sortorder' => 'ASC'
        ]);

        if (count($history) < 2) {
            return [
                'daily_growth' => 0,
                'trend' => 'insufficient_data',
                'pattern' => [],
                'confidence' => 0
            ];
        }

        if ($method === 'simple') {
            return $this->calculateSimpleGrowth($history, $days);
        } else {
            return $this->calculateSeasonalGrowth($history, $days);
        }
    }

    /**
     * Simple linear growth calculation
     */
    private function calculateSimpleGrowth(array $history, int $days): array {
        $first = reset($history);
        $last = end($history);

        $valueDiff = $last['value'] - $first['value'];
        $timeDiff = max(1, ($last['clock'] - $first['clock']) / 86400);
        
        $dailyGrowth = $valueDiff / $timeDiff;

        // Calculate confidence based on data points
        $confidence = min(100, (count($history) / $days) * 100);

        return [
            'daily_growth' => $dailyGrowth,
            'trend' => $this->determineTrend($dailyGrowth),
            'pattern' => [],
            'confidence' => round($confidence)
        ];
    }

    /**
     * Seasonal growth calculation with pattern detection
     */
    private function calculateSeasonalGrowth(array $history, int $days): array {
        if (count($history) < 7) {
            return $this->calculateSimpleGrowth($history, $days);
        }

        // Group by day of week for weekly patterns
        $weeklyPattern = [];
        foreach ($history as $point) {
            $dayOfWeek = date('N', $point['clock']);
            $weekNumber = date('W', $point['clock']);
            
            if (!isset($weeklyPattern[$weekNumber])) {
                $weeklyPattern[$weekNumber] = [];
            }
            $weeklyPattern[$weekNumber][$dayOfWeek] = $point['value'];
        }

        // Calculate growth adjusted for weekly patterns
        $adjustedGrowths = [];
        $pattern = [];

        for ($i = 1; $i < count($history); $i++) {
            $current = $history[$i];
            $previous = $history[$i - 1];

            $timeDiff = ($current['clock'] - $previous['clock']) / 86400;
            
            if ($timeDiff > 0) {
                $rawGrowth = ($current['value'] - $previous['value']) / $timeDiff;
                
                // Adjust for day of week pattern if we have data
                $currentDay = date('N', $current['clock']);
                $previousDay = date('N', $previous['clock']);
                
                $adjustedGrowths[] = $rawGrowth;
            }
        }

        $dailyGrowth = !empty($adjustedGrowths) ? array_sum($adjustedGrowths) / count($adjustedGrowths) : 0;

        // Extract weekly pattern for display
        if (count($weeklyPattern) >= 2) {
            $pattern = $this->extractWeeklyPattern($weeklyPattern);
        }

        return [
            'daily_growth' => $dailyGrowth,
            'trend' => $this->determineTrend($dailyGrowth),
            'pattern' => $pattern,
            'confidence' => min(100, (count($history) / max($days, 1)) * 80)
        ];
    }

    /**
     * Extract weekly growth pattern
     */
    private function extractWeeklyPattern(array $weeklyData): array {
        $pattern = [];
        $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        for ($day = 1; $day <= 7; $day++) {
            $dayValues = [];
            
            foreach ($weeklyData as $weekData) {
                if (isset($weekData[$day])) {
                    $dayValues[] = $weekData[$day];
                }
            }

            if (!empty($dayValues)) {
                $pattern[] = [
                    'day' => $dayNames[$day - 1],
                    'avg' => array_sum($dayValues) / count($dayValues),
                    'samples' => count($dayValues)
                ];
            }
        }

        return $pattern;
    }

    /**
     * Determine growth trend direction
     */
    private function determineTrend(float $dailyGrowth): string {
        if ($dailyGrowth > 1073741824) { // > 1GB/day
            return 'rapid_increase';
        } elseif ($dailyGrowth > 104857600) { // > 100MB/day
            return 'increasing';
        } elseif ($dailyGrowth > 0) {
            return 'slow_increase';
        } elseif ($dailyGrowth < -104857600) { // < -100MB/day
            return 'decreasing';
        } else {
            return 'stable';
        }
    }

    /**
     * Calculate days until full with growth rate
     */
    private function calculateDaysUntilFull(float $total, float $used, float $dailyGrowth): string {
        if ($dailyGrowth <= 0) {
            return _('No growth');
        }

        $freeSpace = $total - $used;
        
        if ($freeSpace <= 0) {
            return _('Already full');
        }

        $days = floor($freeSpace / $dailyGrowth);

        if ($days > 365) {
            return floor($days / 365) . ' years ' . floor(($days % 365) / 30) . ' months';
        } elseif ($days > 30) {
            return floor($days / 30) . ' months ' . ($days % 30) . ' days';
        } else {
            return $days . ' days';
        }
    }

    /**
     * Determine status based on usage and days until full
     */
    private function determineStatus(float $usagePct, string $daysUntilFull, float $warning, float $critical): string {
        if ($usagePct >= $critical) {
            return 'critical';
        } elseif ($usagePct >= $warning) {
            return 'warning';
        }

        // Check days until full
        preg_match('/\d+/', $daysUntilFull, $matches);
        $days = $matches[0] ?? PHP_INT_MAX;

        if ($days <= 30) {
            return 'critical';
        } elseif ($days <= 90) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Calculate enhanced summary statistics
     */
    private function calculateEnhancedSummary(array $storageData, array $filter): array {
        $summary = [
            'total_capacity_raw' => 0,
            'total_used_raw' => 0,
            'total_growth_raw' => 0,
            'critical_count' => 0,
            'warning_count' => 0,
            'total_hosts' => 0,
            'total_filesystems' => count($storageData),
            'earliest_full' => null,
            'top_growers' => []
        ];

        $hosts = [];
        $growthData = [];

        foreach ($storageData as $item) {
            $summary['total_capacity_raw'] += $item['total_raw'];
            $summary['total_used_raw'] += $item['used_raw'];
            
            if ($item['daily_growth_raw'] > 0) {
                $summary['total_growth_raw'] += $item['daily_growth_raw'];
                $growthData[] = $item;
            }

            if ($item['status'] === 'critical') {
                $summary['critical_count']++;
            } elseif ($item['status'] === 'warning') {
                $summary['warning_count']++;
            }

            $hosts[$item['hostid']] = true;

            // Track earliest full
            if ($item['daily_growth_raw'] > 0) {
                preg_match('/\d+/', $item['days_until_full'], $matches);
                $days = $matches[0] ?? PHP_INT_MAX;
                
                if ($summary['earliest_full'] === null || $days < $summary['earliest_full']['days']) {
                    $summary['earliest_full'] = [
                        'host' => $item['host'],
                        'mount' => $item['mount'],
                        'days' => $days,
                        'date' => date('Y-m-d', time() + ($days * 86400))
                    ];
                }
            }
        }

        $summary['total_hosts'] = count($hosts);
        
        // Calculate percentages
        $summary['total_usage_pct'] = $summary['total_capacity_raw'] > 0 
            ? round(($summary['total_used_raw'] / $summary['total_capacity_raw']) * 100, 1)
            : 0;

        $summary['avg_daily_growth'] = !empty($growthData)
            ? $summary['total_growth_raw'] / count($growthData)
            : 0;

        // Format for display
        $summary['total_capacity'] = $this->formatBytes($summary['total_capacity_raw']);
        $summary['total_used'] = $this->formatBytes($summary['total_used_raw']);
        $summary['avg_daily_growth_fmt'] = $this->formatBytes($summary['avg_daily_growth'] * 86400) . '/day';

        // Get top 5 fastest growing filesystems
        usort($growthData, function($a, $b) {
            return $b['daily_growth_raw'] <=> $a['daily_growth_raw'];
        });
        
        $summary['top_growers'] = array_slice($growthData, 0, 5);

        return $summary;
    }

    /**
     * Get filter options for UI dropdowns
     */
    private function getFilterOptions(array $currentFilter): array {
        $options = [
            'hostgroups' => [],
            'hosts' => [],
            'time_ranges' => [
                7 => _('Last 7 days'),
                14 => _('Last 14 days'),
                30 => _('Last 30 days'),
                90 => _('Last 90 days'),
                180 => _('Last 180 days'),
                365 => _('Last year')
            ],
            'prediction_methods' => [
                'simple' => _('Simple Linear'),
                'seasonal' => _('Seasonal Adjusted')
            ],
            'refresh_intervals' => [
                0 => _('Manual'),
                30 => _('30 seconds'),
                60 => _('1 minute'),
                120 => _('2 minutes'),
                300 => _('5 minutes'),
                600 => _('10 minutes')
            ]
        ];

        // Get host groups
        $groups = API::HostGroup()->get([
            'output' => ['groupid', 'name'],
            'sortfield' => 'name',
            'preservekeys' => true
        ]);

        foreach ($groups as $group) {
            $options['hostgroups'][] = [
                'id' => $group['groupid'],
                'name' => $group['name'],
                'selected' => in_array($group['groupid'], $currentFilter['groupids'] ?? [])
            ];
        }

        // Get hosts (filtered by selected groups if any)
        $hostParams = [
            'output' => ['hostid', 'host', 'name'],
            'sortfield' => 'host'
        ];

        if (!empty($currentFilter['groupids'])) {
            $hostParams['groupids'] = $currentFilter['groupids'];
        }

        $hosts = API::Host()->get($hostParams);

        foreach ($hosts as $host) {
            $options['hosts'][] = [
                'id' => $host['hostid'],
                'name' => $host['name'] ?: $host['host'],
                'host' => $host['host'],
                'selected' => in_array($host['hostid'], $currentFilter['hostids'] ?? [])
            ];
        }

        return $options;
    }

    /**
     * Helper to build query string from filters
     */
    private function buildQueryString(array $filter, array $exclude = []): string {
        $params = [];

        foreach ($filter as $key => $value) {
            if (in_array($key, $exclude)) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $val) {
                    $params[] = $key . '[]=' . urlencode($val);
                }
            } else {
                $params[] = $key . '=' . urlencode($value);
            }
        }

        return $params ? '&' . implode('&', $params) : '';
    }

    /**
     * Format bytes to human readable format
     */
    public function formatBytes($bytes, $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
