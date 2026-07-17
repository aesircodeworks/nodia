-- Correctness probes, run against the database after every load run.
--
-- A load run that hits every latency target while breaking an invariant is a
-- failed run, so these are the point of the exercise and not a footnote: they
-- turn the run into a large-scale concurrency test (stage-12 plan, Slice 8).
--
-- Every probe below selects the violating rows. Zero rows is a pass. run.sh
-- fails the run if any probe returns a row, so a new probe needs no wiring
-- beyond being added here with the same shape.

-- Output formatting is the runner's: it invokes psql with -t -A so that a clean
-- probe prints absolutely nothing, which is what lets it treat any output at all
-- as a violation. Do not turn headers or footers back on here.

-- 1. No oversell. The invariant the whole hold mechanism exists to protect: a
-- ticket type can never have more tickets held plus sold than it has.
select 'oversell' as probe, ticket_type_id, quantity, held, sold
from ticket_type_inventory
where sold + held > quantity;

-- 2a. No duplicate payments per idempotency key. The database has a unique
-- index on this, so a row here would mean the index is gone, not that a race
-- beat it; it is cheap to assert and the assertion outlives the index.
select 'duplicate_idempotency_key' as probe, idempotency_key, count(*) as payments
from payments
group by idempotency_key
having count(*) > 1;

-- 2b. No duplicate payment effects. A replayed Idempotency-Key must return the
-- original payment rather than charging again, so no order may carry more than
-- one confirmed payment.
select 'multiple_confirmed_payments' as probe, order_id, count(*) as confirmed
from payments
where status = 'confirmed'
group by order_id
having count(*) > 1;

-- 3. Ledger balanced. Double-entry: per tenant and currency, debits equal
-- credits. A duplicate payment effect that slipped past probe 2 would show up
-- here as an imbalance.
select 'ledger_unbalanced' as probe, tenant_id, currency,
       coalesce(sum(amount) filter (where direction = 'debit'), 0) as debits,
       coalesce(sum(amount) filter (where direction = 'credit'), 0) as credits
from ledger_entries
group by tenant_id, currency
having coalesce(sum(amount) filter (where direction = 'debit'), 0)
     <> coalesce(sum(amount) filter (where direction = 'credit'), 0);

-- 4. The held counter agrees with the holds that are actually outstanding.
-- `held` is maintained by conditional UPDATEs on the counter row while hold_items
-- are the record of what was held; if a hold burst can make the two disagree,
-- the counter is drifting and probe 1 would eventually stop being able to see
-- an oversell coming. An expired-but-unswept hold is still `active` and still
-- counted, which is why status is the only filter here.
select 'held_counter_drift' as probe, i.ticket_type_id, i.held, coalesce(outstanding.quantity, 0) as outstanding
from ticket_type_inventory i
left join (
    select hi.ticket_type_id, sum(hi.quantity) as quantity
    from hold_items hi
    join holds h on h.id = hi.hold_id
    where h.status = 'active'
    group by hi.ticket_type_id
) outstanding on outstanding.ticket_type_id = i.ticket_type_id
where i.held <> coalesce(outstanding.quantity, 0);
