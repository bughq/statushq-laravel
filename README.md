# StatusHQ for Laravel

Report your application's health and your server's CPU, memory and disk to
[StatusHQ](https://statushq.org), from Laravel.

```bash
composer require statushq/laravel-sdk
```

There are two directions, and most installs want both:

| | Direction | What it answers |
|---|---|---|
| **Metrics** | your app **pushes**, on the scheduler | how loaded is each machine |
| **Health** | StatusHQ **pulls** an endpoint | is the app up and are its dependencies healthy |

Push is the only way to get a true reading **per node** — a pulled endpoint
describes whichever server happened to answer, which behind a load balancer is
a coin toss. Pull is the only thing that proves the app is reachable from the
outside, which a push can never show: a silent agent and a dead network look
identical from the receiving end.

## Metrics: push from the scheduler

Create a **metrics monitor** in StatusHQ, copy its token, and set:

```dotenv
STATUSHQ_METRICS_TOKEN=your-monitor-token
```

That is the whole setup. The package registers a minutely scheduled task, so
as long as Laravel's scheduler is running you are done:

```bash
php artisan schedule:list
# 0 * * * * php artisan statushq:report ......... Next Due: 1 minute from now
```

Check it by hand any time:

```bash
php artisan statushq:report --blocking
```

**The first run after a deploy sends nothing, on purpose.** CPU usage is a
rate: it needs two counter readings to exist. The first run stores a baseline
and says so; the second, a minute later, is the first that reports. A number
invented from a single reading would be the machine's average since boot,
which on a box that has been idle all week and is pinned right now reads as
roughly zero — and nobody would ever catch it, because that is also what a
genuinely idle box looks like.

Prefer your own cron? Turn the schedule off and call the command yourself:

```dotenv
STATUSHQ_METRICS_SCHEDULE=false
```

## Health: an endpoint StatusHQ polls

Set a secret, and the endpoint appears at `/statushq-health-check-results`:

```dotenv
STATUSHQ_HEALTH_SECRET=some-long-random-string
```

Then add a **health monitor** in StatusHQ pointing at that URL, with the same
string in **Health endpoint secret**. The secret travels in the
`oh-dear-health-check-secret` header, and a request without it gets a 403 with
no body — the check names alone describe your internals.

**Without a secret the route is never registered**, and the URL 404s like any
other unknown path. Installing a package should not be enough to publish an
unauthenticated description of your application — which queues it runs, which
services it depends on, how full its disk is — and a 404 does not even reveal
that this package is installed.

If the endpoint genuinely isn't reachable from outside, or the caller can't
send a header (a Kubernetes liveness probe, say), say so explicitly:

```dotenv
STATUSHQ_HEALTH_ALLOW_UNAUTHENTICATED=true
```

The endpoint always returns **200**, even when checks fail. The status code
answers "did the endpoint work"; the body answers "is the app healthy".
Conflating them means a consumer cannot tell a failing check from an
unreachable server.

### Your own checks

```php
use StatusHq\Laravel\HealthRegistry;
use StatusHq\Health\Check;
use StatusHq\Health\CheckResult;

class DatabaseCheck implements Check
{
    public function name(): string  { return 'Database'; }
    public function label(): string { return 'Database'; }

    public function run(): CheckResult
    {
        $start = microtime(true);
        DB::connection()->getPdo();
        $ms = (int) ((microtime(true) - $start) * 1000);

        return $ms < 100
            ? CheckResult::ok($this->name(), $this->label(), "{$ms}ms", ['latency_ms' => $ms])
            : CheckResult::warning($this->name(), $this->label(), "Database took {$ms}ms", "{$ms}ms");
    }
}
```

Register them from a service provider — this **replaces** the built-in host
checks, so include them if you still want them:

```php
app(HealthRegistry::class)->add(new DatabaseCheck());   // keeps the defaults
app(HealthRegistry::class)->checks([new DatabaseCheck()]); // replaces them
```

A check that throws is reported as `crashed` with its own name, not a 500. One
broken check must not blind the monitor to the other twelve.

## Already using spatie/laravel-health?

Then you already have an endpoint, a scheduler entry and a result store — and
StatusHQ reads that schema natively. Point a health monitor at your existing
`/oh-dear-health-check-results` URL and you are done; this package is optional.

What it adds is the checks that package doesn't have:

```php
use Spatie\Health\Facades\Health;
use StatusHq\Spatie\HostChecks;

Health::checks([
    ...HostChecks::all(),      // CPU, memory, disk
    DatabaseCheck::new(),
]);
```

- **Memory** — `spatie/laravel-health` ships no memory check at all.
- **CPU as a percentage** of the container's quota. Their `CpuLoadCheck` (a
  separate package, `spatie/cpu-load-health-check`) reports the Unix load
  average from `sys_getloadavg()`, which is unbounded and core-count-relative:
  8.0 is idle on a 16-core box and a fire on a 2-core one.
- **Disk without shelling out.** Theirs runs `df -P` through Symfony Process,
  which needs `proc_open` — routinely disabled on shared hosting and absent
  from hardened php-fpm images. This reads `statvfs` through PHP's built-ins.

If you want the URL to stay byte-identical while you migrate off Oh Dear, set
`STATUSHQ_HEALTH_PATH=oh-dear-health-check-results` — the default differs only
so that installing both packages doesn't collide on one route.

## Containers

Memory is read from the **cgroup** before `/proc/meminfo`, and that ordering is
the point. Inside a container `/proc/meminfo` describes the host: an app capped
at 512 MB on a 64 GB box reads as 3% used while it is being OOM-killed. Every
"memory monitoring" that reads meminfo unconditionally is wrong on Docker,
Kubernetes, Fly, ECS and Cloud Run.

The same applies to CPU: when the container has a quota, usage is measured
against **that**, not the host's cores. A container limited to half a core on a
32-core host is at 100% of what it may use while `/proc/stat` shows 1.5%.

Reported in each check's `meta.source` (`cgroup-v2`, `cgroup-v1`,
`proc-meminfo`) so a wrong number is traceable rather than mysterious.

## What it reports when it can't tell

Never a plausible-looking zero. Every reader returns "unknown" and the check
reports `skipped`, which StatusHQ ignores rather than counting as healthy:

- **macOS or Windows** — there is no `/proc`, so memory and CPU are skipped.
  Disk still works. This is the normal state of local development.
- **`open_basedir` or a hardened container** — same, and equally not an error.
- **The first CPU sample**, as above.

## Configuration

```bash
php artisan vendor:publish --tag=statushq-config
```

| Env var | Default | |
|---|---|---|
| `STATUSHQ_METRICS_TOKEN` | — | Token from your metrics monitor |
| `STATUSHQ_URL` | `https://statushq.org` | Self-hosting? Point it at your instance |
| `STATUSHQ_METRICS_ENABLED` | `true` | |
| `STATUSHQ_METRICS_SCHEDULE` | `true` | Off if you run your own cron |
| `STATUSHQ_HOST` | hostname | What distinguishes four app servers |
| `STATUSHQ_DISK_PATH` | `/` | Mount point to measure |
| `STATUSHQ_HEALTH_ENABLED` | `true` | |
| `STATUSHQ_HEALTH_PATH` | `statushq-health-check-results` | |
| `STATUSHQ_HEALTH_SECRET` | `OH_DEAR_HEALTH_CHECK_SECRET` | Falls back to Oh Dear's variable. **No secret, no route.** |
| `STATUSHQ_HEALTH_ALLOW_UNAUTHENTICATED` | `false` | Serve with no secret — private networks only |

Thresholds live in `config/statushq.php` — at or above `warn` is degraded, at
or above `fail` is down.

## Testing your own health endpoint

The fixture reader ships in the package, so you can assert on a full disk
without one:

```php
use StatusHq\Support\ArrayFileReader;
use StatusHq\Support\FileReader;

$this->app->instance(FileReader::class, new ArrayFileReader([
    '/sys/fs/cgroup/memory.max' => (string) (512 * 1024 * 1024),
    '/sys/fs/cgroup/memory.current' => (string) (500 * 1024 * 1024),
]));

$this->getJson('statushq-health-check-results')
    ->assertJsonPath('checkResults.1.status', 'failed');
```

## Requirements

PHP 8.2+, Laravel 10, 11, 12 or 13.

## License

MIT.
