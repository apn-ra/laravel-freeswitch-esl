<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Support;

final class WorkerCheckpointHistoryReportBuilder
{
    /**
     * @param  list<array<string, mixed>>  $scopes
     * @param  array<string, string|null>  $filters
     * @param  array<string, mixed>  $retentionMetadata
     * @return array<string, mixed>
     */
    public function report(
        array $scopes,
        int $windowHours,
        int $historyLimit,
        bool $includeHistory,
        array $filters = [],
        array $retentionMetadata = [],
        int $limit = 25,
        int $offset = 0,
        bool $hasMore = false,
    ): array {
        return [
            'report_surface' => 'worker_checkpoint_history',
            'live_recovery_supported' => false,
            'window_hours' => $windowHours,
            'history_limit' => $historyLimit,
            'history_included' => $includeHistory,
            'scope_count' => count($scopes),
            'filters' => $filters,
            ...$retentionMetadata,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'returned_scope_count' => count($scopes),
                'has_more' => $hasMore,
            ],
            'scopes' => $scopes,
        ];
    }
}
