{{-- Inline month-over-month delta chip: green up, red down, nothing when flat. $n = signed int. --}}
@if ($n > 0)
    <span class="font-medium text-success-600 dark:text-success-400">▲ {{ number_format($n) }}</span>
@elseif ($n < 0)
    <span class="font-medium text-danger-600 dark:text-danger-400">▼ {{ number_format(abs($n)) }}</span>
@endif
