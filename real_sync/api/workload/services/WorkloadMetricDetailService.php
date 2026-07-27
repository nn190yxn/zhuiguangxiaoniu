<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadAnalyticsQueryService.php';
require_once __DIR__ . '/WorkloadMetricVersionService.php';

final class WorkloadMetricDetailService {
    private WorkloadAnalyticsQueryService $analytics;
    private WorkloadMetricVersionService $metricVersion;

    public function __construct(PDO $pdo) {
        $this->analytics = new WorkloadAnalyticsQueryService($pdo);
        $this->metricVersion = new WorkloadMetricVersionService($pdo);
    }

    public function detail(array $input, array $context): array {
        $facts = $this->analytics->facts($input, $context);
        $page = max(1, (int) ($input['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($input['page_size'] ?? 20)));
        $total = count($facts['rows']);
        $offset = ($page - 1) * $pageSize;
        $rows = array_slice($facts['rows'], $offset, $pageSize);
        $metadata = $this->metricVersion->responseMetadata($facts['filters'], $facts['filters']['sources']);

        return array_merge($metadata, [
            'data_cutoff_at' => $metadata['generated_at'],
            'filters' => $facts['filters'],
            'permission_scope' => $facts['permission_scope'],
            'rows' => $rows,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $total > 0 ? (int) ceil($total / $pageSize) : 0,
            ],
        ]);
    }
}
