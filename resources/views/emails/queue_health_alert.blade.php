The hourly queue health check found problems.

@foreach($problems as $problem)
- {{ $problem }}
@endforeach

Counts:
@foreach($metrics as $key => $value)
  {{ str_replace('_', ' ', $key) }}: {{ $value ?? '—' }}
@endforeach

This is a read-only report; nothing has been changed. The recovery commands are
in the runbook: docs/production/paystack-runbook.md (section 5, payout recovery).

Run `php artisan jobs:check` on the Render shell for the current state.
