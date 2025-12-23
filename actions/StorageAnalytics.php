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

        // Fetch storage data with filters - USING WORKING METHOD
        $storageData = $this->getDiskDataWithFilters($filter);
        
        // Calculate predictions
        $enhancedData = $this->calculatePredictions($storageData, $filter);
        
        // Calculate summary statistics
        $summary = $this->calculateSummary($enhancedData, $filter);
        
        // Get filter options for UI
        $filterOptions = $this->getFilterOptions($filter);

        // Prepare response with proper structure for view
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
     * WORKING DATA COLLECTION METHOD (from your attached script)
     */
    private function getDiskDataWithFilters(array $filter): array {
        $diskData = [];
        
        // Build API parameters based on filters
        $apiParams = [
            'search' => ['key_' => 'vfs.fs.size'],
            'output' => ['itemid', 'key_', 'name', 'lastvalue', 'hostid'],
            'selectHosts' => ['hostid', 'name'],
            'monitored' => true,
            'preservekeys' => true
        ];
        
        // Apply group filter
        if (!empty($filter['groupids'])) {
            $apiParams['groupids'] = $filter['groupids'];
        }
        
        // Apply host filter
        if (!empty($filter['hostids'])) {
            $apiParams['hostids'] = $filter['hostids'];
        }
        
        // Apply host search
        if (!empty($filter['host'])) {
            $apiParams['search']['host'] = $filter['host'];
        }
        
        $items = API::Item()->get($apiParams);
        
        $groupedData = [];
        $hostNames = [];
        
        foreach ($items as $item) {
            // Match mount point and item type ('total' or 'pused')
            if (!preg_match('/vfs\.fs\.size\[(.*),(total|pused|used)\]/i', $item['key_'], $matches)) {
                continue;
            }

            $hostId = $item['hostid'];
            $hostNames[$hostId] = $item['hosts'][0]['name'];
            
            $mountPointKey = trim($matches[1], '"') ?: '/';
            $type = strtolower($matches[2]); // 'total', 'pused', or 'used'

            $key = $hostId . '|' . $mountPointKey;

            if (!isset($groupedData[$key])) {
                $groupedData[$key] = [
                    'hostid' => $hostId,
                    'mount' => $mountPointKey,
                    'total' => 0.0,
                    'pused' => 0.0,
                    'used' => 0.0
                ];
            }
            
            $groupedData[$key][$type] = (float) $item['lastvalue'];
        }

        // Calculate metrics for the final array
        foreach ($groupedData as $key => $data) {
            $hostId = $data['hostid'];
            $totalRaw = $data['total'];
            $pused = $data['pused'];
            $usedRaw = $data['used'];
            
            // Skip if we don't have data
            if ($totalRaw <= 0 && $usedRaw <= 0) {
                continue;
            }
            
            // Calculate used bytes if we have percentage but not raw used
            if ($usedRaw <= 0 && $pused > 0 && $totalRaw > 0) {
                $usedRaw = $totalRaw * ($pused / 100.0);
            }
            
            // Calculate total if we have used and percentage
            if ($totalRaw <= 0 && $usedRaw > 0 && $pused > 0) {
                $totalRaw = $usedRaw / ($pused / 100.0);
            }
            
            // Skip if still no valid data
            if ($totalRaw <= 0 || $usedRaw <= 0) {
                continue;
            }
            
            $usagePct = round(($usedRaw / $totalRaw) * 100, 1);
            
            $diskData[] = [
                'hostid' => $hostId,
                'host' => $hostNames[$hostId] ?? 'Unknown',
                'host_name' => $hostNames[$hostId] ?? 'Unknown',
                'mount' => $data['mount'],
                'total_raw' => $totalRaw,
                'used_raw' => $usedRaw,
                'pused' => $pused,
                'total_space' => $this->formatBytes($totalRaw),
                'used_space' => $this->formatBytes($usedRaw),
                'usage_pct' => $usagePct
            ];
        }

        return $diskData;
    }

    /**
     * Calculate growth predictions
     */
    private function calculatePredictions(array $storageData, array $filter): array {
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
            $item['daily_growth'] = $growthData['daily_growth'] > 0 
                ? $this->formatBytes($growthData['daily_growth']) . '/day' 
                : _('Stable');
                
            $item['days_until_full'] = $this->calculateDaysUntilFull(
                $item['total_raw'],
                $item['used_raw'],
                $growthData['daily_growth']
            );
            $item['growth_trend'] = $growthData['trend'];
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
     * Calculate growth rate
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
            'sortorder' => 'ASC',
            'limit' => 30 // Limit to reasonable amount
        ]);

        if (count($history) < 2) {
            return [
                'daily_growth' => 0,
                'trend' => 'insufficient_data',
                'confidence' => 0
            ];
        }

        // Simple growth calculation (working method)
        $first = reset($history);
        $last = end($history);

        $valueDiff = $last['value'] - $first['value'];
        $timeDiff = max(1, ($last['clock'] - $first['clock']) / 86400);
        
        $dailyGrowth = $valueDiff / $timeDiff;
        
        // Cap unrealistic growth
        if (abs($dailyGrowth) > 10737418240) { // > 10GB/day
            $dailyGrowth = 0;
        }

        $confidence = min(100, (count($history) / $days) * 100);

        return [
            'daily_growth' => $dailyGrowth,
            'trend' => $this->determineTrend($dailyGrowth),
            'confidence' => round($confidence)
        ];
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
        
        // Handle unrealistic calculations
        if ($days > 365 * 10) { // More than 10 years
            return _('More than 10 years');
        }

        if ($days > 365) {
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
     * Calculate summary statistics
     */
    private function calculateSummary(array $storageData, array $filter): array {
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
            
            if (isset($item['daily_growth_raw']) && $item['daily_growth_raw'] > 0) {
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
            if (isset($item['daily_growth_raw']) && $item['daily_growth_raw'] > 0) {
                preg_match('/\d+/', $item['days_until_full'], $matches);
                $days = $matches[0] ?? PHP_INT_MAX;
                
                if ($days > 0 && ($summary['earliest_full'] === null || $days < $summary['earliest_full']['days'])) {
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

        // Calculate average growth (only from filesystems with growth)
        $summary['avg_daily_growth'] = !empty($growthData)
            ? $summary['total_growth_raw'] / count($growthData)
            : 0;

        // Format for display
        $summary['total_capacity'] = $this->formatBytes($summary['total_capacity_raw']);
        $summary['total_used'] = $this->formatBytes($summary['total_used_raw']);
        $summary['avg_daily_growth_fmt'] = $summary['avg_daily_growth'] > 0 
            ? $this->formatBytes($summary['avg_daily_growth'] * 86400) . '/day'
            : '0 B/day';

        // Get top 5 fastest growing filesystems
        usort($growthData, function($a, $b) {
            return ($b['daily_growth_raw'] ?? 0) <=> ($a['daily_growth_raw'] ?? 0);
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
