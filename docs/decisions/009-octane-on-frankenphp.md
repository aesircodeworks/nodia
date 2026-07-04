# Laravel Octane on FrankenPHP

## Context and Problem Statement

Ticket on-sales produce sharp request spikes where per-request framework bootstrap becomes a meaningful share of latency. We need a PHP runtime that keeps the application resident between requests and ships as a maintainable container image.

## Considered Options

* Laravel Octane on FrankenPHP, using the official FrankenPHP Docker image
* PHP-FPM behind nginx
* Octane on Swoole or RoadRunner

## Decision Outcome

Chosen option: "Octane on FrankenPHP", because it removes framework bootstrap from the hot path, the official Docker image gives a supported single-container runtime (server and PHP in one process), and FrankenPHP's embedded Caddy aligns with the edge proxy choice for on-demand TLS. Swoole and RoadRunner offer similar performance but need a separate web server and less conventional images.

### Consequences

* Good, because request latency drops and the container story is one official image.
* Bad, because long-lived workers demand state discipline: no mutable static state, request-scoped services must reset between requests, and leaks surface as cross-request bugs.
