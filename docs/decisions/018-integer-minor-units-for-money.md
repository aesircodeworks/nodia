# Integer Minor Units for Money

## Context and Problem Statement

Monetary values flow through orders, payments, the ledger, and payouts in multiple currencies. Floating-point storage introduces rounding errors that break ledger balancing, and amounts stored without a currency invite silent cross-currency mistakes.

## Considered Options

* Integer minor units with an explicit currency code on every monetary record
* Decimal columns
* Adopting a money library's storage model (brick/money, moneyphp)

## Decision Outcome

Chosen option: "Integer minor units with an explicit currency code on every monetary record", because integer arithmetic is exact, keeping double-entry ledger sums verifiable, and pairing every `*_amount` column with a `currency` on the same row makes currency mismatches structurally visible. A shared value object and Eloquent cast in `app/Support/Money` own construction, arithmetic, and formatting; raw integers are never manipulated outside it. A library remains adoptable behind that value object if currency arithmetic grows beyond it.
