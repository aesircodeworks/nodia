# Containers First, Defer the Orchestrator

## Context and Problem Statement

A production orchestrator (Kubernetes, ECS, Nomad, Compose on VMs) must eventually be chosen, but committing now would front-load operational complexity before traffic patterns and hosting constraints are known.

## Considered Options

- OCI images as the artifact now; orchestrator chosen just before production
- Commit to Kubernetes now
- Deploy to a PaaS

## Decision Outcome

Chosen option: "Containers now, orchestrator later", because constraining every app to be orchestrator-agnostic (stateless processes, env-only config, stdout logs, health endpoints, graceful SIGTERM drain) makes the later choice a deployment decision instead of a code change, and Docker Compose covers development and staging until then.
