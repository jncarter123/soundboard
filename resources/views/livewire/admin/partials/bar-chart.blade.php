{{--
    Grouped bar chart (inline SVG, no JS).
    Expects:
      $labels  array<string>            bucket labels (one per bucket)
      $series  array<array{name,values,color,unit?}>  one or more series to group per bucket
--}}
@php
    $W = 1000; $H = 320;
    $padL = 52; $padR = 14; $padT = 14; $padB = 32;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;

    $count = count($labels);

    $all = [];
    foreach ($series as $s) {
        foreach ($s['values'] as $v) { $all[] = (float) ($v ?? 0); }
    }
    $rawMax = $all ? max($all) : 0;
    $hasData = $rawMax > 0;

    // Round the axis max up to a "nice" number (1, 2, 2.5, 5, 10 × 10^n).
    $niceMax = 1;
    if ($rawMax > 0) {
        $pow = 10 ** floor(log10($rawMax));
        $niceMax = 10 * $pow;
        foreach ([1, 2, 2.5, 5, 10] as $f) {
            if ($rawMax <= $f * $pow) { $niceMax = $f * $pow; break; }
        }
    }

    $groupW = $count > 0 ? $plotW / $count : $plotW;
    $seriesCount = max(1, count($series));
    $barGap = $groupW * 0.18;
    $barW = max(0.5, ($groupW - $barGap) / $seriesCount);
    $labelEvery = max(1, (int) ceil($count / 8));

    $yOf = fn ($v) => $padT + $plotH - (($v / $niceMax) * $plotH);
@endphp

<svg viewBox="0 0 {{ $W }} {{ $H }}" class="w-full h-auto" role="img" preserveAspectRatio="xMidYMid meet">
    {{-- Horizontal gridlines + y-axis labels --}}
    @for($i = 0; $i <= 4; $i++)
        @php
            $val = $niceMax * ($i / 4);
            $gy = $yOf($val);
        @endphp
        <line x1="{{ $padL }}" y1="{{ $gy }}" x2="{{ $W - $padR }}" y2="{{ $gy }}"
              stroke="#e5e7eb" stroke-width="1" />
        <text x="{{ $padL - 8 }}" y="{{ $gy + 4 }}" text-anchor="end"
              font-size="12" fill="#9ca3af" font-family="ui-sans-serif, system-ui">
            {{ number_format($val, $val < 10 && $val != (int) $val ? 1 : 0) }}
        </text>
    @endfor

    @if($hasData)
        {{-- Bars --}}
        @for($i = 0; $i < $count; $i++)
            @php $groupX = $padL + $i * $groupW + $barGap / 2; @endphp
            @foreach($series as $j => $s)
                @php
                    $v = (float) ($s['values'][$i] ?? 0);
                    $bx = $groupX + $j * $barW;
                    $bh = $v > 0 ? $plotH - ($yOf($v) - $padT) : 0;
                    $by = $yOf($v);
                @endphp
                @if($bh > 0)
                    <rect x="{{ round($bx, 2) }}" y="{{ round($by, 2) }}"
                          width="{{ round(max(0.5, $barW - 1), 2) }}" height="{{ round($bh, 2) }}"
                          fill="{{ $s['color'] }}" rx="1">
                        <title>{{ $labels[$i] ?? '' }} · {{ $s['name'] }}: {{ number_format($v) }}{{ isset($s['unit']) ? ' '.$s['unit'] : '' }}</title>
                    </rect>
                @endif
            @endforeach
        @endfor

        {{-- X-axis labels (thinned) --}}
        @for($i = 0; $i < $count; $i += $labelEvery)
            @php $lx = $padL + $i * $groupW + $groupW / 2; @endphp
            <text x="{{ round($lx, 2) }}" y="{{ $H - 10 }}" text-anchor="middle"
                  font-size="11" fill="#9ca3af" font-family="ui-sans-serif, system-ui">
                {{ $labels[$i] ?? '' }}
            </text>
        @endfor
    @else
        <text x="{{ $W / 2 }}" y="{{ $padT + $plotH / 2 }}" text-anchor="middle"
              font-size="14" fill="#9ca3af" font-family="ui-sans-serif, system-ui">
            No data recorded for this period yet
        </text>
    @endif
</svg>
