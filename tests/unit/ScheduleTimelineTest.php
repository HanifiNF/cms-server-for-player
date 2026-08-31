<?php

use App\Libraries\ScheduleTimeline;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class ScheduleTimelineTest extends CIUnitTestCase
{
    public function testItBuildsCanonicalOffsetsAndTotals(): void
    {
        $timeline = (new ScheduleTimeline())->calculate([
            ['title_snapshot' => 'Film A', 'duration_override_ms' => 3_600_000, 'gap_after_ms' => 600_000],
            ['title_snapshot' => 'Film B', 'duration_override_ms' => 1_800_000, 'gap_after_ms' => 300_000],
        ]);

        $this->assertSame(5_400_000, $timeline['film_duration_ms']);
        $this->assertSame(600_000, $timeline['gap_duration_ms']);
        $this->assertSame(6_000_000, $timeline['total_duration_ms']);
        $this->assertSame(0, $timeline['items'][0]['start_offset_ms']);
        $this->assertSame(3_600_000, $timeline['items'][0]['content_end_offset_ms']);
        $this->assertSame(4_200_000, $timeline['items'][0]['next_start_offset_ms']);
        $this->assertSame(4_200_000, $timeline['items'][1]['start_offset_ms']);
        $this->assertSame(6_000_000, $timeline['items'][1]['content_end_offset_ms']);
        $this->assertSame(0, $timeline['items'][1]['effective_gap_after_ms']);
    }

    public function testFinalGapOnlyParticipatesWhenPlaylistLoops(): void
    {
        $items = [
            ['duration_override_ms' => 10_000, 'gap_after_ms' => 2_000],
            ['duration_override_ms' => 20_000, 'gap_after_ms' => 5_000],
        ];

        $singlePass = (new ScheduleTimeline())->calculate($items, false);
        $loop = (new ScheduleTimeline())->calculate($items, true);

        $this->assertSame(32_000, $singlePass['total_duration_ms']);
        $this->assertSame(37_000, $loop['total_duration_ms']);
        $this->assertSame(0, $singlePass['items'][1]['effective_gap_after_ms']);
        $this->assertSame(5_000, $loop['items'][1]['effective_gap_after_ms']);
    }

    public function testEmptyTimelineHasZeroTotals(): void
    {
        $this->assertSame([
            'items' => [], 'film_duration_ms' => 0,
            'gap_duration_ms' => 0, 'total_duration_ms' => 0,
        ], (new ScheduleTimeline())->calculate([]));
    }

    public function testManualBoundaryIsConvertedToTheExistingFilmGap(): void
    {
        $timeline = new ScheduleTimeline();

        $this->assertSame(1_200_000, $timeline->gapFromBoundary(3_600_000, 4_800_000));
        $this->expectException(\InvalidArgumentException::class);
        $timeline->gapFromBoundary(3_600_000, 3_599_999);
    }

    public function testManualBoundaryRejectsAGapLongerThanOneDay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ScheduleTimeline())->gapFromBoundary(0, ScheduleTimeline::MAX_GAP_MS + 1);
    }
}
