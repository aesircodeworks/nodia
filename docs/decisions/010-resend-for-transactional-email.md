# Resend for Transactional Email

## Context and Problem Statement

The platform sends transactional email (order confirmations, tickets, account flows) and needs a provider with good deliverability and a first-class Laravel integration, without coupling application code to the provider.

## Considered Options

* Resend via the official `resend/resend-laravel` driver
* SMTP-compatible provider (Postmark, Mailgun)
* Self-hosted SMTP

## Decision Outcome

Chosen option: "Resend", because it has an official Laravel mail driver and a modern API, while all mail continues to flow through Laravel's mailer contract, so the provider remains swappable by configuration. Self-hosted SMTP makes deliverability the platform's problem.
