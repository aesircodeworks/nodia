# Custom RBAC over spatie/laravel-permission

## Context and Problem Statement

Authorization needs global role templates plus per-tenant custom roles, with a user's role attached to each tenant membership (one user, different roles in different tenants). spatie/laravel-permission is the ecosystem default and was evaluated for this.

## Considered Options

- Custom RBAC: `roles` with nullable `tenant_id` and capability sets, assigned via `memberships`, checked through Gates and Policies
- spatie/laravel-permission with the teams feature (`team_id` as tenant)
- spatie/laravel-permission without teams

## Decision Outcome

Chosen option: "Custom RBAC", because the teams feature is ruled out for this project, and without it laravel-permission assigns roles globally per user, which cannot express per-membership role scoping. The custom model is small (two tables and a policy layer) and matches the membership design exactly.

### Consequences

- Good, because role assignment lives on the membership, precisely matching the identity model.
- Bad, because we maintain the RBAC tables and checks ourselves instead of leaning on a maintained package.
