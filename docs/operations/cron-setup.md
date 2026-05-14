# Cron-runner setup

The platform's `bin/console` -CLI runs scheduled jobs. Three jobs are in scope for milestone 0.7 (MembershipBilling):

| Command | Frequency | Time | Purpose |
|---|---|---|---|
| `membership:generate-anniversary-invoices` | Daily | 02:00 | Create yearly invoices for members whose anniversary is today |
| `membership:mark-overdue-invoices` | Daily | 02:30 | Flag PENDING → OVERDUE for invoices past due_date + grace |
| `membership:lapse-inactive-members` | Daily | 03:00 | Lapse members with 2 consecutive years OVERDUE (§ 4 deemed-resignation) |

Each command:

- Acquires an exclusive `flock()` on `var/run/<command>.lock` — overlapping runs exit 0 cleanly
- Writes JSONL audit to `var/log/cron/<command>-YYYY-MM-DD.log`
- Per-tenant try/catch — one tenant's failure does not block others

## Windows Server (Task Scheduler)

Production runs on Windows. Create three scheduled tasks via PowerShell as Administrator:

```powershell
# Anniversary-invoice creation
$Action = New-ScheduledTaskAction `
  -Execute 'C:\laragon\bin\php\php-8.3.26\php.exe' `
  -Argument 'C:\laragon\www\daems-platform\bin\console membership:generate-anniversary-invoices' `
  -WorkingDirectory 'C:\laragon\www\daems-platform'

$Trigger = New-ScheduledTaskTrigger -Daily -At 02:00

$Settings = New-ScheduledTaskSettingsSet `
  -StartWhenAvailable `
  -RunOnlyIfNetworkAvailable `
  -ExecutionTimeLimit (New-TimeSpan -Hours 1)

Register-ScheduledTask `
  -TaskName 'Daems\Membership-Anniversary-Invoices' `
  -Action $Action `
  -Trigger $Trigger `
  -Settings $Settings `
  -User 'NT AUTHORITY\SYSTEM' `
  -RunLevel Highest
```

Repeat for `membership:mark-overdue-invoices` at 02:30 and `membership:lapse-inactive-members` at 03:00. Replace `Daems\` task-folder prefix with your tenant slug if hosting multiple platforms.

## Linux / macOS (crontab)

```cron
# /etc/cron.d/daems-membership-billing
0 2 * * * www-data cd /var/www/daems-platform && /usr/bin/php bin/console membership:generate-anniversary-invoices >> var/log/cron/cron.out 2>> var/log/cron/cron.err
30 2 * * * www-data cd /var/www/daems-platform && /usr/bin/php bin/console membership:mark-overdue-invoices >> var/log/cron/cron.out 2>> var/log/cron/cron.err
0 3 * * * www-data cd /var/www/daems-platform && /usr/bin/php bin/console membership:lapse-inactive-members >> var/log/cron/cron.out 2>> var/log/cron/cron.err
```

## Dev workflow

Run manually any time:

```bash
php bin/console membership:generate-anniversary-invoices
php bin/console membership:mark-overdue-invoices
php bin/console membership:lapse-inactive-members --dry-run    # preview lapses
php bin/console membership:lapse-inactive-members              # commit
```

`--dry-run` is supported on `lapse-inactive-members` only — it prints the user_ids that would lapse without modifying state. Anniversary + overdue commands have no dry-run because their effects are reversible (anniversary creates idempotent rows; overdue flips a flag that auto-mark commands can re-evaluate).

## Verifying a scheduled run

After a scheduled run, inspect the log:

```bash
type var\log\cron\membership-generate-anniversary-invoices-2026-05-15.log
```

Each line is JSON. Expect per-tenant rows + a summary row at the end:

```jsonl
{"ts":"2026-05-15T02:00:01+03:00","level":"info","tenant":"daems","processed":42,"created":5,"skipped":0,"errors":0}
{"ts":"2026-05-15T02:00:03+03:00","level":"info","tenant":"sahegroup","processed":12,"created":2,"skipped":0,"errors":0}
{"ts":"2026-05-15T02:00:03+03:00","level":"info","summary":true,"total_tenants":2,"total_created":7,"duration_ms":2103}
```

If a run is skipped due to a stale lock (previous run still in progress), you'll see:

```jsonl
{"ts":"2026-05-15T02:00:00+03:00","level":"info","message":"lock held, skipping","command":"membership:generate-anniversary-invoices"}
```

## Troubleshooting

- **Lock-held loops:** if a command is "always skipping" check `var/run/<cmd>.lock` content (it holds the PID of the holder). If the PID is dead, delete the lock file manually. The next run will re-acquire.
- **Permission errors writing to var/log/cron:** make sure the task user (NT AUTHORITY\SYSTEM on Windows, www-data on Linux) has write access to `var/log/cron/` and `var/run/`.
- **PHP version mismatch:** the platform requires PHP 8.3+. On Windows, point the scheduled task `Execute` field to `C:\laragon\bin\php\php-8.3.x\php.exe` explicitly, not the system `php` alias.
