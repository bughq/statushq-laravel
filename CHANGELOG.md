# Changelog

All notable changes to `statushq/laravel-sdk` are documented here. This project
follows [semantic versioning](https://semver.org).

## v0.1.0 — 2026-08-18

First release.

### Metrics, pushed from the scheduler

- `statushq:report` samples CPU, memory and disk and pushes them to a StatusHQ
  metrics monitor. Registered on Laravel's scheduler automatically once
  `STATUSHQ_METRICS_TOKEN` is set; set `STATUSHQ_METRICS_SCHEDULE=false` to run
  it from your own cron instead.
- Every sample carries its `host`, so several machines reporting to one monitor
  are several series rather than one that overwrites itself.
- The first run after a deploy reports nothing and says so. CPU usage is a rate
  and needs two counter readings to exist; a number derived from one reading is
  the machine's average since boot.

### Health, pulled by StatusHQ

- A JSON endpoint in the `spatie/laravel-health` schema, so StatusHQ and Oh Dear
  both read it with no adapter.
- **The route is only registered when a secret is configured.** Installing a
  package must not be enough to publish an unauthenticated description of an
  application's internals. `STATUSHQ_HEALTH_ALLOW_UNAUTHENTICATED=true` opts in
  for endpoints that are not publicly reachable.
- Three host checks — CPU, memory, disk — configurable per threshold, plus your
  own via `HealthRegistry`. A check that throws is reported as `crashed` rather
  than taking the endpoint down with it, and the endpoint always answers 200.

### Container awareness

- Memory resolves `cgroup-v2` → `cgroup-v1` → `/proc/meminfo`, excluding the
  reclaimable file cache. Inside a container `/proc/meminfo` describes the host,
  so an app capped at 512 MB on a 64 GB box would otherwise read as 3% used
  while being OOM-killed.
- CPU resolves `cgroup-v2` → `cgroup-v1` → `/proc/stat`, measured against the
  container's quota where one is set.
- Each result reports which interface answered in `meta.source`.

### Interoperating with spatie/laravel-health

- `StatusHq\Spatie\HostChecks::all()` adapts these checks into an existing
  `Health::checks([...])` registry, adding a memory check (that package has
  none), a bounded CPU percentage rather than a load average, and a disk check
  that reads `statvfs` instead of shelling out to `df`.

### Notes

- Nothing shells out. Disk uses PHP's built-ins, so it works where `proc_open`
  is disabled.
- Readings that cannot be derived are reported as `skipped` and pushes are
  withheld — never a plausible-looking zero.
- Requires PHP 8.2+ and Laravel 10, 11 or 12.
