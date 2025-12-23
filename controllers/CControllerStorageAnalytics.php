<?php
class CControllerStorageAnalytics extends CController {
    
    protected function init(): void {
        $this->disableSIDvalidation();
    }
    
    protected function checkInput(): bool {
        $fields = [
            'hostids' => 'array|db hosts.hostid',
            'groupids' => 'array|db hstgrp.groupid',
            'tags' => 'array',
            'refresh' => 'in 0,30,60,120,300',
            'refresh_enabled' => 'in 0,1',
            'time_range' => 'in 7,30,90,180,365',
            'prediction_method' => 'in simple,seasonal',
            'critical_threshold' => 'ge 0|le 100',
            'warning_threshold' => 'ge 0|le 100',
            'page' => 'ge 1'
        ];
        
        $ret = $this->validateInput($fields);
        
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        
        return $ret;
    }
    
    protected function checkPermissions(): bool {
        return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
    }
    
    protected function doAction(): void {
        // Get filter values with defaults
        $filter = [
            'hostids' => $this->getInput('hostids', []),
            'groupids' => $this->getInput('groupids', []),
            'tags' => $this->getInput('tags', []),
            'refresh' => $this->getInput('refresh', 60),
            'refresh_enabled' => $this->getInput('refresh_enabled', 1),
            'time_range' => $this->getInput('time_range', 30),
            'prediction_method' => $this->getInput('prediction_method', 'seasonal'),
            'critical_threshold' => $this->getInput('critical_threshold', 90),
            'warning_threshold' => $this->getInput('warning_threshold', 80),
            'page' => $this->getInput('page', 1)
        ];
        
        // Get storage data using API
        $storage_data = $this->getStorageData($filter);
        
        // Calculate predictions with seasonality
        $data = $this->calculatePredictions($storage_data, $filter);
        
        // Calculate summary statistics
        $summary = $this->calculateSummary($data, $filter);
        
        // Prepare response
        $response = new CControllerResponseData([
            'data' => $data,
            'summary' => $summary,
            'filter' => $filter,
            'page' => $filter['page'],
            'page_limit' => 50,
            'total_records' => count($data)
        ]);
        
        $response->setTitle(_('Storage Analytics'));
        $this->setResponse($response);
    }
    
    private function getStorageData(array $filter): array {
        // Build API parameters
        $params = [
            'output' => ['itemid', 'hostid', 'name', 'key_', 'units'],
            'selectHosts' => ['hostid', 'host', 'name'],
            'webitems' => true,
            'search' => ['key_' => 'vfs.fs.size'],
            'sortfield' => 'name',
            'preservekeys' => true
        ];
        
        // Apply host filters
        if (!empty($filter['hostids'])) {
            $params['hostids'] = $filter['hostids'];
        }
        
        // Apply group filters
        if (!empty($filter['groupids'])) {
            $params['groupids'] = $filter['groupids'];
        }
        
        // Apply tag filters
        if (!empty($filter['tags'])) {
            $params['tags'] = $filter['tags'];
        }
        
        // Get items
        $items = API::Item()->get($params);
        
        // Get current values
        $itemids = array_keys($items);
        $history = API::History()->get([
            'output' => ['itemid', 'value', 'clock'],
            'itemids' => $itemids,
            'history' => 3,
            'sortfield' => 'clock',
            'sortorder' => 'DESC',
            'limit' => 1
        ]);
        
        // Organize data by host and filesystem
        $storage_data = [];
        
        foreach ($items as $item) {
            $itemid = $item['itemid'];
            $host = $item['hosts'][0];
            
            // Extract filesystem name from key
            preg_match('/vfs\.fs\.size\[(.+),(total|used|free|pfree)\]$/', $item['key_'], $matches);
            
            if ($matches) {
                $fs_name = $matches[1];
                $type = $matches[2];
                $host_id = $host['hostid'];
                
                if (!isset($storage_data[$host_id])) {
                    $storage_data[$host_id] = [
                        'hostid' => $host_id,
                        'host' => $host['host'],
                        'host_name' => $host['name'] ?? $host['host'],
                        'filesystems' => []
                    ];
                }
                
                if (!isset($storage_data[$host_id]['filesystems'][$fs_name])) {
                    $storage_data[$host_id]['filesystems'][$fs_name] = [
                        'name' => $fs_name
                    ];
                }
                
                // Find current value
                $current_value = 0;
                foreach ($history as $hist) {
                    if ($hist['itemid'] == $itemid) {
                        $current_value = $hist['value'];
                        break;
                    }
                }
                
                $storage_data[$host_id]['filesystems'][$fs_name][$type] = $current_value;
                
                // Calculate usage percentage
                if ($type == 'total' && isset($storage_data[$host_id]['filesystems'][$fs_name]['used'])) {
                    $used = $storage_data[$host_id]['filesystems'][$fs_name]['used'];
                    $total = $current_value;
                    $storage_data[$host_id]['filesystems'][$fs_name]['usage_percent'] = 
                        $total > 0 ? ($used / $total) * 100 : 0;
                }
            }
        }
        
        return $storage_data;
    }
    
    private function calculatePredictions(array $storage_data, array $filter): array {
        $method = $filter['prediction_method'];
        $time_range = $filter['time_range'];
        
        foreach ($storage_data as &$host) {
            foreach ($host['filesystems'] as $fs_name => &$fs) {
                if (!isset($fs['total']) || !isset($fs['used'])) {
                    continue;
                }
                
                $itemid = $this->findItemId($host['hostid'], $fs_name);
                
                if ($itemid) {
                    if ($method === 'seasonal') {
                        $fs['daily_growth'] = $this->calculateSeasonalGrowth($itemid, $fs_name, $time_range);
                    } else {
                        $fs['daily_growth'] = $this->calculateSimpleGrowth($itemid, $time_range);
                    }
                    
                    $fs['days_until_full'] = $this->calculateDaysUntilFull(
                        $fs['total'],
                        $fs['used'],
                        $fs['daily_growth']
                    );
                    
                    // Add seasonal patterns if available
                    if ($method === 'seasonal') {
                        $fs['seasonal_pattern'] = $this->getSeasonalPattern($itemid);
                    }
                }
            }
        }
        
        return $storage_data;
    }
    
    private function calculateSeasonalGrowth($itemid, $fs_name, $days): float {
        // Get trends for the specified period
        $trends = API::Trend()->get([
            'output' => ['clock', 'value_avg'],
            'itemids' => [$itemid],
            'time_from' => time() - ($days * 86400),
            'time_till' => time(),
            'sortfield' => 'clock',
            'sortorder' => 'ASC'
        ]);
        
        if (count($trends) < 7) { // Need at least a week of data
            return $this->calculateSimpleGrowth($itemid, $days);
        }
        
        // Group by day of week for weekly seasonality
        $weekly_pattern = [];
        foreach ($trends as $trend) {
            $day_of_week = date('N', $trend['clock']);
            if (!isset($weekly_pattern[$day_of_week])) {
                $weekly_pattern[$day_of_week] = [];
            }
            $weekly_pattern[$day_of_week][] = $trend['value_avg'];
        }
        
        // Calculate average growth considering weekly patterns
        $growth_rates = [];
        for ($i = 1; $i < count($trends); $i++) {
            $day1 = date('N', $trends[$i-1]['clock']);
            $day2 = date('N', $trends[$i]['clock']);
            $time_diff = ($trends[$i]['clock'] - $trends[$i-1]['clock']) / 86400;
            
            if ($time_diff > 0) {
                $daily_growth = ($trends[$i]['value_avg'] - $trends[$i-1]['value_avg']) / $time_diff;
                
                // Adjust for weekly patterns
                $avg_day1 = array_sum($weekly_pattern[$day1]) / count($weekly_pattern[$day1]);
                $avg_day2 = array_sum($weekly_pattern[$day2]) / count($weekly_pattern[$day2]);
                $pattern_factor = $avg_day2 - $avg_day1;
                
                $adjusted_growth = $daily_growth - $pattern_factor;
                $growth_rates[] = $adjusted_growth;
            }
        }
        
        return count($growth_rates) > 0 ? array_sum($growth_rates) / count($growth_rates) : 0;
    }
    
    private function calculateSimpleGrowth($itemid, $days): float {
        $trends = API::Trend()->get([
            'output' => ['clock', 'value_avg'],
            'itemids' => [$itemid],
            'time_from' => time() - ($days * 86400),
            'time_till' => time(),
            'sortfield' => 'clock',
            'sortorder' => 'ASC',
            'limit' => 2
        ]);
        
        if (count($trends) < 2) {
            return 0;
        }
        
        $time_diff = (end($trends)['clock'] - $trends[0]['clock']) / 86400;
        $value_diff = end($trends)['value_avg'] - $trends[0]['value_avg'];
        
        return $time_diff > 0 ? $value_diff / $time_diff : 0;
    }
    
    private function getSeasonalPattern($itemid): array {
        // Get last 4 weeks of daily averages
        $pattern = [];
        $now = time();
        
        for ($week = 0; $week < 4; $week++) {
            $week_start = $now - (($week + 1) * 7 * 86400);
            $week_end = $now - ($week * 7 * 86400);
            
            $trends = API::Trend()->get([
                'output' => ['clock', 'value_avg'],
                'itemids' => [$itemid],
                'time_from' => $week_start,
                'time_till' => $week_end
            ]);
            
            if (count($trends) > 0) {
                $week_avg = array_sum(array_column($trends, 'value_avg')) / count($trends);
                $pattern[] = [
                    'week' => date('W', $week_start),
                    'average' => $week_avg,
                    'trend' => count($trends) > 1 ? 
                        (end($trends)['value_avg'] - $trends[0]['value_avg']) : 0
                ];
            }
        }
        
        return $pattern;
    }
    
    private function calculateDaysUntilFull($total, $used, $daily_growth): int {
        if ($daily_growth <= 0) {
            return -1; // No growth or shrinking
        }
        
        $free_space = $total - $used;
        if ($free_space <= 0) {
            return 0;
        }
        
        $days = floor($free_space / $daily_growth);
        return max(0, $days);
    }
    
    private function calculateSummary(array $data, array $filter): array {
        $summary = [
            'total_capacity' => 0,
            'total_used' => 0,
            'total_usage_percent' => 0,
            'avg_daily_growth' => 0,
            'critical_count' => 0,
            'warning_count' => 0,
            'total_hosts' => count($data),
            'total_filesystems' => 0
        ];
        
        $growth_values = [];
        
        foreach ($data as $host) {
            foreach ($host['filesystems'] as $fs) {
                $summary['total_filesystems']++;
                
                if (isset($fs['total'])) {
                    $summary['total_capacity'] += $fs['total'];
                }
                
                if (isset($fs['used'])) {
                    $summary['total_used'] += $fs['used'];
                }
                
                if (isset($fs['usage_percent'])) {
                    if ($fs['usage_percent'] >= $filter['critical_threshold']) {
                        $summary['critical_count']++;
                    } elseif ($fs['usage_percent'] >= $filter['warning_threshold']) {
                        $summary['warning_count']++;
                    }
                }
                
                if (isset($fs['daily_growth']) && $fs['daily_growth'] > 0) {
                    $growth_values[] = $fs['daily_growth'];
                }
            }
        }
        
        // Calculate averages
        if ($summary['total_capacity'] > 0) {
            $summary['total_usage_percent'] = ($summary['total_used'] / $summary['total_capacity']) * 100;
        }
        
        if (!empty($growth_values)) {
            $summary['avg_daily_growth'] = array_sum($growth_values) / count($growth_values);
        }
        
        // Format for display
        $summary['total_capacity_fmt'] = $this->formatBytes($summary['total_capacity']);
        $summary['total_used_fmt'] = $this->formatBytes($summary['total_used']);
        $summary['avg_daily_growth_fmt'] = $this->formatBytes($summary['avg_daily_growth']);
        
        return $summary;
    }
    
    private function formatBytes($bytes, $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    private function findItemId($hostid, $fs_name): ?string {
        // Find item ID for a specific filesystem
        $items = API::Item()->get([
            'output' => ['itemid'],
            'hostids' => [$hostid],
            'search' => ['key_' => 'vfs.fs.size[' . $fs_name . ',used]'],
            'limit' => 1
        ]);
        
        return !empty($items) ? $items[0]['itemid'] : null;
    }

    /**
	* Helper function to build query string from filter
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
}
