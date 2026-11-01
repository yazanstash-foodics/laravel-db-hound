---
title: Test infrastructure — Docker MySQL/Redis, Unit/Integration suites, CI
labels: [ready-for-agent]
type: AFK
status: pending-ci
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Enabling slice for every later issue. Docker Compose provides MySQL 8.0, MySQL 8.4 and Redis on non-default host ports (developers often run local MySQL/Redis on 3306/6379). Tests split into a `Unit` suite (Testbench defaults, no services) and an `Integration` suite (Pest group `integration`) whose base test case points Laravel's default DB connection at Docker MySQL and its Redis connection at Docker Redis, all overridable via env vars. A smoke test proves the services behave as the package needs: MySQL ≥ 8.0, `EXPLAIN FORMAT=JSON` works, `information_schema.STATISTICS` is readable, Redis pipelines work. CI runs unit tests across the supported PHP × Laravel matrix and integration tests against MySQL 8.0 and 8.4 service containers.

## Acceptance criteria

- [x] `composer services:up` starts all containers and waits for health; `composer services:down` stops them.
- [x] `composer test:unit` runs without Docker.
- [x] `composer test:integration` runs against MySQL 8.0; `composer test:integration:84` against MySQL 8.4.
- [x] Integration smoke test passes on both MySQL versions.
- [ ] CI: unit job (PHP 8.4/8.5 × Laravel 11/12/13) and integration job (MySQL 8.0/8.4 + Redis) both green.

> **Pending CI:** `.github/workflows/run-tests.yml` is written, but it has not run yet. The `draft` branch is local and has not been pushed, so nothing has run it. The last criterion stays unchecked until the workflow runs green on the remote. Everything else is verified locally. The unit job turns off Composer's advisory blocking for its throwaway install. Without that, every tagged Laravel 11 release gets rejected and the solver quietly falls back to `11.x-dev`. The job also fails if the resolved `laravel/framework` is a dev version.

## Blocked by

None - can start immediately.
