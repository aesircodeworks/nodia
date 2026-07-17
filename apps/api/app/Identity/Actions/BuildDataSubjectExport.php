<?php

namespace App\Identity\Actions;

use App\CheckIn\Actions\PaginateCheckInsForDataSubjectExport;
use App\CheckIn\Data\CheckInExportRowData;
use App\Identity\Models\Customer;
use App\Identity\Models\DataSubjectRequest;
use App\Orders\Actions\PaginateOrdersForDataSubjectExport;
use App\Orders\Actions\PaginateTicketsForDataSubjectExport;
use App\Orders\Data\OrderExportRowData;
use App\Orders\Data\TicketExportRowData;
use App\Payments\Actions\PaginatePaymentsForDataSubjectExport;
use App\Payments\Actions\PaginateRefundsForDataSubjectExport;
use App\Payments\Data\PaymentExportRowData;
use App\Payments\Data\RefundExportRowData;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Claims and builds one data subject export document (stage-12 plan,
 * Slice 2; task breakdown item 6), the export counterpart to
 * App\Reporting\Actions\BuildExport: the claim, every source read, the
 * completion or failure transition, and the medialibrary attachment all
 * commit together inside the tenant transaction App\Identity\Jobs\
 * BuildDataSubjectExportJob opens.
 *
 * DataSubjectRequest::claim() is both the exactly-one-worker guard and
 * this action's own existence check, mirroring BuildExport's identical
 * reasoning: a missing or already-claimed request simply returns and
 * this method exits without side effects, so running the job twice for
 * the same request produces exactly one attachment and one completed
 * transition (stage-12 plan, Slice 2 Feature "duplicate delivery").
 *
 * Every category is read through the owning context's own cursor-
 * paginated Action, never through an imported Model (api-conventions'
 * high-volume rule; tests/Architecture/ContextBoundariesTest.php's
 * Identity-boundary assertion): orders and tickets are read by
 * customer_id; payments and refunds by the order IDs collected while
 * paging orders (Payments carries no customer_id column of its own);
 * check-ins by the ticket IDs collected while paging tickets (CheckIn
 * carries no customer_id column either). The document is a single JSON
 * object, snake_case throughout, every monetary field as
 * {amount, currency} (data-conventions Money), attached via
 * medialibrary (no bespoke path column, ADR 014).
 *
 * The customer's own name and email are read directly off the Customer
 * row exactly as AnonymizeCustomer left them: an anonymized customer's
 * row already carries the deterministic placeholder by the time this
 * action ever runs, so exporting one yields the placeholder, never
 * recovered PII, with no special-casing needed here (stage-12 plan,
 * Slice 2 Feature "exporting an anonymized customer").
 *
 * Any throwable from resolving a source, from a source's own pages()
 * iterator, or from attaching the file marks the request failed rather
 * than propagating, mirroring BuildExport's identical posture; nothing
 * is attached to the request in that case.
 */
final readonly class BuildDataSubjectExport
{
    public function __construct(
        private PaginateOrdersForDataSubjectExport $orders,
        private PaginateTicketsForDataSubjectExport $tickets,
        private PaginatePaymentsForDataSubjectExport $payments,
        private PaginateRefundsForDataSubjectExport $refunds,
        private PaginateCheckInsForDataSubjectExport $checkIns,
    ) {}

    public function __invoke(string $requestId): void
    {
        if (! DataSubjectRequest::claim($requestId)) {
            return;
        }

        try {
            $request = DataSubjectRequest::query()->findOrFail($requestId);
            $customer = Customer::query()->findOrFail($request->customer_id);

            $orderIds = [];
            $orderRows = [];

            foreach (($this->orders)($customer->id) as $page) {
                foreach ($page as $row) {
                    $orderIds[] = $row->id;
                    $orderRows[] = $this->orderToArray($row);
                }
            }

            $ticketIds = [];
            $ticketRows = [];

            foreach (($this->tickets)($customer->id) as $page) {
                foreach ($page as $row) {
                    $ticketIds[] = $row->id;
                    $ticketRows[] = $this->ticketToArray($row);
                }
            }

            $paymentRows = [];

            foreach (($this->payments)($orderIds) as $page) {
                foreach ($page as $row) {
                    $paymentRows[] = $this->paymentToArray($row);
                }
            }

            $refundRows = [];

            foreach (($this->refunds)($orderIds) as $page) {
                foreach ($page as $row) {
                    $refundRows[] = $this->refundToArray($row);
                }
            }

            $checkInRows = [];

            foreach (($this->checkIns)($ticketIds) as $page) {
                foreach ($page as $row) {
                    $checkInRows[] = $this->checkInToArray($row);
                }
            }

            $document = [
                'customer' => $this->customerToArray($customer),
                'orders' => $orderRows,
                'tickets' => $ticketRows,
                'payments' => $paymentRows,
                'refunds' => $refundRows,
                'check_ins' => $checkInRows,
            ];

            $json = json_encode(
                $document,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            );

            $request->addMediaFromString($json)
                ->usingFileName("data-subject-export-{$request->id}.json")
                ->toMediaCollection('data_subject_export');

            DataSubjectRequest::complete($requestId);
        } catch (Throwable $exception) {
            report($exception);

            DataSubjectRequest::fail($requestId);
        }
    }

    /**
     * @return array{amount: int, currency: string}
     */
    private function money(Money $money): array
    {
        return ['amount' => $money->amount, 'currency' => $money->currency];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerToArray(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'email' => $customer->email,
            'name' => $customer->name,
            'locale' => $customer->locale,
            'created_at' => CarbonImmutable::instance($customer->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            'anonymized_at' => $customer->anonymized_at !== null
                ? CarbonImmutable::instance($customer->anonymized_at)->utc()->format('Y-m-d\TH:i:s\Z')
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderToArray(OrderExportRowData $row): array
    {
        return [
            'id' => $row->id,
            'event_id' => $row->eventId,
            'status' => $row->status,
            'subtotal' => $this->money($row->subtotal),
            'discount' => $this->money($row->discount),
            'fees' => $this->money($row->fees),
            'total' => $this->money($row->total),
            'created_at' => $row->createdAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketToArray(TicketExportRowData $row): array
    {
        return [
            'id' => $row->id,
            'event_id' => $row->eventId,
            'ticket_type_id' => $row->ticketTypeId,
            'order_id' => $row->orderId,
            'status' => $row->status,
            'attendee_name' => $row->attendeeName,
            'list_price' => $row->listPrice !== null ? $this->money($row->listPrice) : null,
            'issued_at' => $row->issuedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentToArray(PaymentExportRowData $row): array
    {
        return [
            'id' => $row->id,
            'order_id' => $row->orderId,
            'gateway' => $row->gateway,
            'method' => $row->method,
            'status' => $row->status,
            'amount' => $this->money($row->amount),
            'created_at' => $row->createdAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function refundToArray(RefundExportRowData $row): array
    {
        return [
            'id' => $row->id,
            'payment_id' => $row->paymentId,
            'status' => $row->status,
            'amount' => $this->money($row->amount),
            'reason' => $row->reason,
            'created_at' => $row->createdAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkInToArray(CheckInExportRowData $row): array
    {
        return [
            'id' => $row->id,
            'event_id' => $row->eventId,
            'ticket_id' => $row->ticketId,
            'result' => $row->result,
            'scanned_at' => $row->scannedAt,
        ];
    }
}
