<?php

use App\Libraries\ScheduleDirectoryFilter;
use App\Libraries\ScheduleTimeline;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class ScheduleDirectoryFilterTest extends CIUnitTestCase
{
    public function testCategoriesUseAndWhileValuesInsideTargetsUseOr(): void
    {
        $filter = new ScheduleDirectoryFilter();
        $row = $this->row();
        $filters = $filter->normalize([
            'q' => 'managed campaign',
            'location_ids' => ['location-bogor'],
            'device_ids' => ['another-studio'],
            'asset_ids' => ['asset-a'],
            'status' => 'completed',
        ]);

        $this->assertTrue($filter->matches($row, $filters));
        $filters['status'] = ['upcoming'];
        $this->assertFalse($filter->matches($row, $filters));
    }

    public function testRecurringDateFilterEvaluatesActualOccurrence(): void
    {
        $filter = new ScheduleDirectoryFilter();
        $row = $this->row([
            'recurrence' => 'weekly',
            'recurrence_config' => ['daysOfWeek' => [1], 'until' => '2026-09-30'],
            'start_at' => '2026-08-31 01:00:00',
            'end_at' => '2026-08-31 02:00:00',
        ]);

        $this->assertTrue($filter->matches($row, $filter->normalize(['date_from' => '2026-09-07', 'date_to' => '2026-09-07'])));
        $this->assertFalse($filter->matches($row, $filter->normalize(['date_from' => '2026-09-08', 'date_to' => '2026-09-08'])));
    }

    public function testTimeBucketUsesTheSelectedAssetsActualTimelineSegment(): void
    {
        $filter = new ScheduleDirectoryFilter();
        $timeline = (new ScheduleTimeline())->calculate([
            ['asset_public_id' => 'asset-a', 'title_snapshot' => 'Opening', 'duration_override_ms' => 3_600_000],
            ['asset_public_id' => 'asset-b', 'title_snapshot' => 'Feature', 'duration_override_ms' => 3_600_000],
        ]);
        $row = $this->row(['start_at' => '2026-08-31 06:30:00', 'end_at' => '2026-08-31 08:30:00', 'items' => $timeline['items']]); // 13:30 Jakarta

        $this->assertTrue($filter->matches($row, $filter->normalize(['asset_ids' => ['asset-a'], 'period' => 'noon'])));
        $this->assertFalse($filter->matches($row, $filter->normalize(['asset_ids' => ['asset-a'], 'period' => 'night'])));
        $this->assertTrue($filter->matches($row, $filter->normalize(['asset_ids' => ['asset-b'], 'period' => 'night'])));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        $timeline = (new ScheduleTimeline())->calculate([
            ['asset_public_id' => 'asset-a', 'title_snapshot' => 'Managed Campaign', 'duration_override_ms' => 3_600_000],
        ]);
        return [...[
            'title' => 'Monday Premiere', 'description' => '', 'timezone' => 'Asia/Jakarta',
            'start_at' => '2026-08-31 01:00:00', 'end_at' => '2026-08-31 02:00:00',
            'recurrence' => 'one_time', 'recurrence_config' => null, 'display_status' => 'completed',
            'targets' => [[
                'public_id' => 'studio-one', 'name' => 'Studio 1', 'location_public_id' => 'location-bogor',
                'location_name' => 'Bogor', 'location' => 'Bogor',
            ]],
            'items' => $timeline['items'],
        ], ...$overrides];
    }
}
