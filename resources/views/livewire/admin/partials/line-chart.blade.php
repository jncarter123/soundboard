{{--
    Line / area chart (inline SVG, no JS).
    Expects:
      $labels  array<string>                              bucket labels
      $series  array<array{name,values,color,fill?,unit?}> one or more series; fill=true draws an area
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

    $niceMax = 1;
    if ($rawMax > 0) {
        $pow = 10 ** floor(log10($rawMax));
        $niceMax = 10 * $pow;
        foreach ([1, 2, 2.5, 5, 10] as $f) {
            if ($rawMax <= $f * $pow) { $niceMax = $f * $pow; break; }
        }
    }

    $stepX = $count > 1 ? $plotW / ($count - 1) : $plotW;
    $labelEvery = max(1, (int) ceil($count / 8));
    $baseY = $padT + $plotH;

    $xOf = fn ($i) => $padL + $i * $stepX;
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
        @foreach($series as $s)
            @php
                // Buckets with no data (null) break the line instead of dropping
                // it to zero, so each run of consecutive values is its own segment.
                $segments = [];
                $current = [];
                foreach ($s['values'] as $i => $v) {
                    if ($v === null) {
                        if ($current) { $segments[] = $current; $current = []; }
                        continue;
                    }
                    $current[] = [round($xOf($i), 2), round($yOf((float) $v), 2)];
                }
                if ($current) { $segments[] = $current; }
            @endphp
            @foreach($segments as $segment)
                @php($line = implode(' ', array_map(fn ($p) => $p[0].','.$p[1], $segment)))
                @if($s['fill'] ?? false)
                    <polygon points="{{ $segment[0][0] }},{{ $baseY }} {{ $line }} {{ end($segment)[0] }},{{ $baseY }}"
                             fill="{{ $s['color'] }}" fill-opacity="0.15" />
                @endif
                @if(count($segment) > 1)
                    <polyline points="{{ $line }}" fill="none" stroke="{{ $s['color'] }}"
                              stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                @else
                    <circle cx="{{ $segment[0][0] }}" cy="{{ $segment[0][1] }}" r="2" fill="{{ $s['color'] }}" />
                @endif
            @endforeach
            {{-- Invisible hover targets per point for native tooltips --}}
            @foreach(array_filter($s['values'], fn ($v) => $v !== null) as $i => $v)
                <circle cx="{{ round($xOf($i), 2) }}" cy="{{ round($yOf((float) ($v ?? 0)), 2) }}" r="6" fill="transparent">
                    <title>{{ $labels[$i] ?? '' }} · {{ $s['name'] }}: {{ number_format((float) ($v ?? 0), (($v ?? 0) == (int) ($v ?? 0)) ? 0 : 1) }}{{ isset($s['unit']) ? ' '.$s['unit'] : '' }}</title>
                </circle>
            @endforeach
        @endforeach

        {{-- X-axis labels (thinned) --}}
        @for($i = 0; $i < $count; $i += $labelEvery)
            <text x="{{ round($xOf($i), 2) }}" y="{{ $H - 10 }}" text-anchor="middle"
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
