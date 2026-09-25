<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChartPartialsTest extends TestCase
{
    protected function yAxisLabels(string $html): array
    {
        preg_match_all('#<text[^>]*text-anchor="end"[^>]*>\s*([^<]*?)\s*</text>#', $html, $m);

        return $m[1];
    }

    public function test_line_chart_axis_keeps_trailing_zeros(): void
    {
        $html = view('livewire.admin.partials.line-chart', [
            'labels' => ['a', 'b', 'c'],
            'series' => [['name' => 'Peak', 'values' => [100, 480, 250], 'color' => '#000']],
        ])->render();

        $this->assertSame(['0', '125', '250', '375', '500'], $this->yAxisLabels($html));
    }

    public function test_bar_chart_axis_keeps_trailing_zeros(): void
    {
        $html = view('livewire.admin.partials.bar-chart', [
            'labels' => ['a', 'b'],
            'series' => [['name' => 'Sent', 'values' => [40, 100], 'color' => '#000']],
        ])->render();

        $this->assertSame(['0', '25', '50', '75', '100'], $this->yAxisLabels($html));
    }

    public function test_small_fractional_ticks_keep_one_decimal(): void
    {
        $html = view('livewire.admin.partials.line-chart', [
            'labels' => ['a', 'b'],
            'series' => [['name' => 'Avg', 'values' => [1, 2], 'color' => '#000']],
        ])->render();

        $this->assertSame(['0', '0.5', '1', '1.5', '2'], $this->yAxisLabels($html));
    }

    public function test_line_breaks_at_missing_buckets_instead_of_dropping_to_zero(): void
    {
        $html = view('livewire.admin.partials.line-chart', [
            'labels' => ['a', 'b', 'c', 'd', 'e', 'f'],
            'series' => [['name' => 'Avg', 'values' => [10, 12, null, 11, 13, null], 'color' => '#000', 'fill' => true]],
        ])->render();

        // Two runs of data -> two lines and two filled areas, no point at y=0.
        $this->assertSame(2, substr_count($html, '<polyline'));
        $this->assertSame(2, substr_count($html, '<polygon'));

        preg_match_all('#<polyline points="([^"]+)"#', $html, $m);
        $baseY = 320 - 32;
        foreach ($m[1] as $points) {
            foreach (explode(' ', $points) as $point) {
                $this->assertNotEquals($baseY, (float) explode(',', $point)[1]);
            }
        }
    }
}
