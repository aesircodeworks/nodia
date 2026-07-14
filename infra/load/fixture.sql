-- Load-test fixture: one tenant, one domain, and two events.
--
-- Applied by run.sh against the Compose stack's database before every run, and
-- re-runnable: it resets its own tenant's rows first, so each run starts from
-- pristine inventory and a run's holds never leak into the next run's oversell
-- probe. The ids are fixed (and UUIDv7-shaped per data-conventions) so the k6
-- scripts and the probes can name them without parsing anything out of psql.
--
-- The high-demand event's admission_rate_per_minute (120) is deliberately below
-- what the waiting-room scenario's crowd needs: 500 entrants against a rate that
-- can admit ~240 inside the measured window means the queue stays backed up for
-- the whole run. Set it high enough to drain the crowd and the scenario stops
-- measuring the gatekeeper's rate and starts measuring how fast k6 can join.
--
-- Two events, not one, because a high-demand event makes an admission token
-- mandatory on every hold (App\Inventory\Actions\CreateHold::assertAdmitted).
-- The hold and payment scenarios need to reach the counter row without a
-- waiting room in front of it, so they use the standard event; the waiting-room
-- scenario needs the gate, so it gets its own high-demand event.
--
-- Rows are written directly rather than through the API because bootstrapping a
-- tenant over HTTP needs a platform staff user with tenants.manage, which no
-- seeder or command creates. Writing them here keeps this task inside its own
-- scope (no application code changes) at the cost of bypassing the domain
-- Actions; the correctness probes, not the fixture, are what assert invariants.

\set tenant_id            '0199a000-0000-7000-8000-00000000e001'
\set domain_id            '0199a000-0000-7000-8000-00000000e002'
\set standard_event_id    '0199a000-0000-7000-8000-00000000e010'
\set hold_ticket_type_id  '0199a000-0000-7000-8000-00000000e011'
\set hold_inventory_id    '0199a000-0000-7000-8000-00000000e012'
\set pay_ticket_type_id   '0199a000-0000-7000-8000-00000000e013'
\set pay_inventory_id     '0199a000-0000-7000-8000-00000000e014'
\set queue_event_id       '0199a000-0000-7000-8000-00000000e020'
\set queue_ticket_type_id '0199a000-0000-7000-8000-00000000e021'
\set queue_inventory_id   '0199a000-0000-7000-8000-00000000e022'

begin;

-- Foreign keys are deferred rather than deleted in dependency order: the
-- fixture tenant owns rows in a couple of dozen tables once a run has gone
-- through checkout, and enumerating them in a safe order would rot with every
-- new table. The role running this is the database superuser, which is also
-- what lets these statements see through RLS.
set session_replication_role = replica;

delete from outbox_deliveries       where tenant_id = :'tenant_id';
delete from outbox_events           where tenant_id = :'tenant_id';
delete from ledger_entries          where tenant_id = :'tenant_id';
delete from refunds                 where tenant_id = :'tenant_id';
delete from payouts                 where tenant_id = :'tenant_id';
delete from payments                where tenant_id = :'tenant_id';
delete from gateway_webhook_events  where tenant_id = :'tenant_id';
delete from check_ins               where tenant_id = :'tenant_id';
delete from tickets                 where tenant_id = :'tenant_id';
delete from order_items             where tenant_id = :'tenant_id';
delete from orders                  where tenant_id = :'tenant_id';
delete from hold_items              where tenant_id = :'tenant_id';
delete from holds                   where tenant_id = :'tenant_id';
delete from purchase_counters       where tenant_id = :'tenant_id';
delete from event_seats             where tenant_id = :'tenant_id';
delete from customers               where tenant_id = :'tenant_id';
delete from activity_log            where tenant_id = :'tenant_id';
delete from event_search_documents  where tenant_id = :'tenant_id';
delete from ticket_type_inventory   where tenant_id = :'tenant_id';
delete from ticket_types            where tenant_id = :'tenant_id';
delete from events                  where tenant_id = :'tenant_id';
delete from tenant_domains          where tenant_id = :'tenant_id';
delete from tenants                 where id        = :'tenant_id';

insert into tenants (id, name, branding_settings, default_locale, supported_locales, enabled_gateways, settlement_currency, commission_bps, created_at, updated_at)
values (:'tenant_id', 'Load Fixture', '{}', 'en', '["en"]', '["fake"]', 'USD', 500, now(), now());

-- The storefront resolves the tenant from the Host header only, never from
-- X-Tenant-Id, so this row is what makes the k6 scripts' requests resolvable.
insert into tenant_domains (id, tenant_id, domain, is_primary, created_at, updated_at)
values (:'domain_id', :'tenant_id', 'load.localhost', true, now(), now());

insert into events (id, tenant_id, status, name, description, start_at, end_at, timezone, is_virtual, virtual_event_url, async_payment_policy, on_sale_policy, created_at, updated_at)
values
  (:'standard_event_id', :'tenant_id', 'published',
   '{"en": "Load Fixture: standard on-sale"}', '{"en": "Hold burst and payment initiation scenarios."}',
   now() + interval '14 days', now() + interval '14 days 3 hours', 'UTC',
   true, 'https://load.localhost/stream',
   '{"slow_methods_enabled": true, "low_inventory_cutoff": null}',
   '{"high_demand": false, "admission_rate_per_minute": null, "challenge_required": false}',
   now(), now()),
  (:'queue_event_id', :'tenant_id', 'published',
   '{"en": "Load Fixture: high-demand on-sale"}', '{"en": "Waiting room admission scenario."}',
   now() + interval '14 days', now() + interval '14 days 3 hours', 'UTC',
   true, 'https://load.localhost/stream',
   '{"slow_methods_enabled": true, "low_inventory_cutoff": null}',
   '{"high_demand": true, "admission_rate_per_minute": 120, "challenge_required": false}',
   now(), now());

-- max_per_customer stays null on every one of these: setting it would make a
-- customer bearer mandatory on the hold path (CreateHold::assertHoldable), and
-- the hold burst is deliberately a guest-checkout load, which is the shape a
-- real on-sale takes before anyone has logged in.
insert into ticket_types (id, tenant_id, event_id, name, price_amount, currency, sales_start, sales_end, requires_seat, max_per_customer, created_at, updated_at)
values
  (:'hold_ticket_type_id',  :'tenant_id', :'standard_event_id', 'Hold Burst GA',     2500, 'USD', null, null, false, null, now(), now()),
  (:'pay_ticket_type_id',   :'tenant_id', :'standard_event_id', 'Payment Path GA',   2500, 'USD', null, null, false, null, now(), now()),
  (:'queue_ticket_type_id', :'tenant_id', :'queue_event_id',    'Waiting Room GA',   2500, 'USD', null, null, false, null, now(), now());

-- Quantities are chosen to make each scenario measure what it claims to.
-- The hold burst wants the counter row to actually run out inside the run, so
-- 5,000 (one venue) against 200/s of offered load exhausts partway through and
-- the rest of the run measures the contended-rejection path. The payment path
-- must never run out, or it would start measuring insufficient_inventory
-- instead of the gateway, so it gets far more than the run can consume.
insert into ticket_type_inventory (id, tenant_id, ticket_type_id, quantity, held, sold, created_at, updated_at)
values
  (:'hold_inventory_id',  :'tenant_id', :'hold_ticket_type_id',    5000, 0, 0, now(), now()),
  (:'pay_inventory_id',   :'tenant_id', :'pay_ticket_type_id',   200000, 0, 0, now(), now()),
  (:'queue_inventory_id', :'tenant_id', :'queue_ticket_type_id',   5000, 0, 0, now(), now());

set session_replication_role = default;

commit;
