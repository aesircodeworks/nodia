# Stage 6 Execution Journal: Inventory and Reserved Seating

## Run: 2026-07-11

- Stage: 6 (docs/plans/stage-06-inventory.md)
- Date: 2026-07-11
- Branch: feat/api-implementation
- Base commit: ffa8ae3d4d87321b9d497c370d170fe6a16c86ff

### Task checklist

- [ ] 06-01 Counters: ticket_type_inventory migration, model, InitializeTicketTypeInventory and AdjustInventoryQuantity Actions, isolation probe (plan slice 1, task 2)
- [ ] 06-02 Catalog quantity contract: additive quantity on ticket type Data objects, delegation to Inventory Actions, requires_seat rejection (plan task 3)
- [ ] 06-03 GA hold creation: holds and hold_items migrations, HoldStatus, CreateHold, HoldCreated, POST and GET endpoints, GA oversell simulation green (plan slice 2, task 4)
- [ ] 06-04 Release and expiry: ReleaseHold, DELETE endpoint, HoldReleased, ReleaseExpiredHolds sweeper, HoldExpired, expiry recovery simulation green (plan slice 3, tasks 5 and 6)
- [ ] 06-05 Availability reads: storefront availability endpoint and admin inventory read with contracts (plan slice 3, task 7)
- [ ] 06-06 ExtendHold and CommitHold internal Actions with commit-versus-expiry race (plan slice 4, task 8)
- [ ] 06-07 Seat materialization on publish: event_seats migration, MaterializeEventSeats, requires_seat publish validation, seat_map_in_use extension (plan slice 5, task 9)
- [ ] 06-08 Seated holds through CreateHold, ReleaseHold, CommitHold, sweeper; double-booking simulation green (plan slice 6, task 10)
- [ ] 06-09 events.manage_seating capability registry addition and role wiring (plan task 10a)
- [ ] 06-10 Seat management and read surfaces: storefront seats, admin seats list, admin PATCH, contracts (plan slice 7, task 11)
- [ ] 06-11 Exit sweep: status table flip to done, OpenAPI consolidation check, full gate run (plan task 12, exit criteria)

### Review rounds

### Decisions and deviations
