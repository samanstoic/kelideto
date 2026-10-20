<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FinancialLedger\Services\FinancialOperationsService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportController extends Controller
{
    private const CATEGORIES = [
        'payment_dispute',
        'property_damage',
        'late_arrival',
        'misconduct',
        'cost_review',
        'other',
    ];

    private const STAFF_ROLES = ['super_admin', 'operator', 'support'];

    public function createTicket(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->status === 'active', 401, 'احراز هویت فعال لازم است.');
        $data = $request->validate([
            'booking_id' => [Rule::requiredIf($request->input('category') === 'cost_review'), 'nullable', 'integer', 'exists:bookings,id'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'message' => ['required', 'string', 'min:3', 'max:5000'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
            'attachments' => ['sometimes', 'array', 'max:3'],
            'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,pdf,txt',
                'mimetypes:image/jpeg,image/png,application/pdf,text/plain'],
        ]);
        $subject = $this->cleanText($data['subject']);
        $message = $this->cleanText($data['message']);
        abort_if(mb_strlen($subject) < 3 || mb_strlen($message) < 3, 422, 'متن تیکت پس از پاک‌سازی معتبر نیست.');

        if (isset($data['booking_id'])) {
            $booking = DB::table('bookings')->where('id', $data['booking_id'])->first();
            abort_unless($booking && $this->isBookingParty($actor->id, $booking), 404,
                'سفارش مرتبط با حساب شما پیدا نشد.');
            if ($data['category'] === 'cost_review') {
                abort_unless((int) $booking->customer_id === (int) DB::table('customer_profiles')
                    ->where('user_id', $actor->id)->value('id'), 403, 'درخواست بازبینی هزینه فقط برای مشتری سفارش مجاز است.');
                abort_unless($booking->status === 'completed' && $booking->completed_at, 409,
                    'درخواست بازبینی هزینه فقط برای سفارش تکمیل‌شده پذیرفته می‌شود.');
            }
        }

        $result = DB::transaction(function () use ($actor, $data, $subject, $message, $request): array {
            if (($data['category'] ?? null) === 'cost_review') {
                $booking = DB::table('bookings')->where('id', $data['booking_id'])->lockForUpdate()->first();
                abort_unless($booking && (int) $booking->customer_id === (int) DB::table('customer_profiles')
                    ->where('user_id', $actor->id)->value('id'), 403,
                    'درخواست بازبینی هزینه فقط برای مشتری سفارش مجاز است.');
                abort_unless($booking->status === 'completed' && $booking->completed_at, 409,
                    'درخواست بازبینی هزینه فقط برای سفارش تکمیل‌شده پذیرفته می‌شود.');
            }
            $idempotentMessage = DB::table('support_ticket_messages')
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($idempotentMessage) {
                $existingTicket = DB::table('support_tickets')->where('id', $idempotentMessage->ticket_id)->first();
                $firstMessageId = DB::table('support_ticket_messages')->where('ticket_id', $idempotentMessage->ticket_id)
                    ->min('id');
                abort_unless($existingTicket
                    && (int) $existingTicket->user_id === (int) $actor->id
                    && (int) $idempotentMessage->sender_user_id === (int) $actor->id
                    && (int) $idempotentMessage->id === (int) $firstMessageId
                    && $existingTicket->subject === $subject
                    && $existingTicket->category === $data['category']
                    && (int) ($existingTicket->booking_id ?? 0) === (int) ($data['booking_id'] ?? 0)
                    && $idempotentMessage->message === $message
                    && $this->attachmentsMatch($request->file('attachments', []), (int) $idempotentMessage->id), 409,
                    'کلید تکرار برای درخواست دیگری استفاده شده است.');

                return ['ticket' => $existingTicket, 'created' => false];
            }

            if (($data['category'] ?? null) === 'cost_review') {
                $existingCostReview = DB::table('support_tickets')->where('booking_id', $data['booking_id'])
                    ->where('category', 'cost_review')->whereNotIn('status', ['closed', 'resolved'])->exists();
                abort_if($existingCostReview, 409, 'برای این سفارش درخواست بازبینی هزینهٔ بازی وجود دارد.');
            }

            $priority = $this->defaultPriority($data['category']);
            $ticketId = DB::table('support_tickets')->insertGetId([
                'ticket_code' => (string) Str::uuid(),
                'user_id' => $actor->id,
                'booking_id' => $data['booking_id'] ?? null,
                'subject' => $subject,
                'priority' => $priority,
                'status' => 'open',
                'category' => $data['category'],
                'assigned_agent_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $messageId = DB::table('support_ticket_messages')->insertGetId([
                'ticket_id' => $ticketId,
                'sender_user_id' => $actor->id,
                'message' => $message,
                'idempotency_key' => $data['idempotency_key'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->storeAttachments($request, $data['attachments'] ?? [], $ticketId, $messageId, (int) $actor->id);
            $this->recordEvent($ticketId, (int) $actor->id, 'ticket.created', null, [
                'category' => $data['category'],
                'priority' => $priority,
                'booking_id' => $data['booking_id'] ?? null,
            ]);

            return [
                'ticket' => DB::table('support_tickets')->where('id', $ticketId)->first(),
                'created' => true,
            ];
        }, 3);

        return response()->json([
            'data' => $this->ticketSummary($result['ticket']),
            'already_created' => ! $result['created'],
        ], $result['created'] ? 201 : 200);
    }

    public function tickets(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->status === 'active', 401, 'احراز هویت فعال لازم است.');
        $isSupportAgent = $this->isSupportAgent((int) $actor->id);
        $query = DB::table('support_tickets')
            ->leftJoin('bookings', 'bookings.id', '=', 'support_tickets.booking_id')
            ->when(! $isSupportAgent, fn (Builder $query) => $query->where('support_tickets.user_id', $actor->id))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('support_tickets.status', $request->string('status')->toString()))
            ->orderByDesc('support_tickets.id')
            ->select([
                'support_tickets.id',
                'support_tickets.ticket_code',
                'support_tickets.booking_id',
                'support_tickets.subject',
                'support_tickets.category',
                'support_tickets.priority',
                'support_tickets.status',
                'support_tickets.created_at',
                'support_tickets.updated_at',
                'bookings.booking_code',
            ]);

        return response()->json(['data' => $query->paginate($this->pageSize($request))]);
    }

    public function relatedBookings(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->status === 'active', 401, 'احراز هویت فعال لازم است.');
        $bookings = DB::table('bookings')
            ->leftJoin('customer_profiles', 'customer_profiles.id', '=', 'bookings.customer_id')
            ->leftJoin('technician_profiles', 'technician_profiles.id', '=', 'bookings.technician_id')
            ->join('services', 'services.id', '=', 'bookings.service_id')
            ->where(function (Builder $query) use ($actor): void {
                $query->where('customer_profiles.user_id', $actor->id)
                    ->orWhere('technician_profiles.user_id', $actor->id);
            })
            ->orderByDesc('bookings.created_at')
            ->select([
                'bookings.id',
                'bookings.booking_code',
                'bookings.status',
                'services.title_fa as service_name',
            ])
            ->paginate($this->pageSize($request));

        return response()->json(['data' => $bookings]);
    }

    public function messages(Request $request, string $ticketCode): JsonResponse
    {
        $ticket = $this->authorizedTicket($request, $ticketCode);
        $messages = DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)
            ->orderBy('id')->select(['id', 'ticket_id', 'sender_user_id', 'message', 'created_at'])
            ->paginate($this->pageSize($request));
        $messages->getCollection()->transform(function (object $message) use ($ticket): object {
            $message->message = $this->cleanText((string) $message->message);
            $message->attachments = DB::table('support_ticket_attachments')
                ->where('message_id', $message->id)
                ->orderBy('id')
                ->get(['id', 'original_name', 'mime_type', 'size_bytes', 'created_at'])
                ->map(fn (object $file): array => [
                    'id' => (int) $file->id,
                    'name' => $file->original_name,
                    'mime_type' => $file->mime_type,
                    'size_bytes' => (int) $file->size_bytes,
                    'download_url' => "support/tickets/{$ticket->ticket_code}/attachments/{$file->id}",
                ]);

            return $message;
        });
        $history = DB::table('support_ticket_events')
            ->leftJoin('users', 'users.id', '=', 'support_ticket_events.actor_user_id')
            ->where('support_ticket_events.ticket_id', $ticket->id)
            ->orderBy('support_ticket_events.id')
            ->get([
                'support_ticket_events.event_type',
                'support_ticket_events.old_values',
                'support_ticket_events.new_values',
                'support_ticket_events.reason',
                'support_ticket_events.created_at',
                'users.name as actor_name',
            ])->map(function (object $event): object {
                $event->reason = $event->reason ? $this->cleanText($event->reason) : null;
                $event->old_values = $event->old_values ? json_decode($event->old_values, true, flags: JSON_THROW_ON_ERROR) : null;
                $event->new_values = $event->new_values ? json_decode($event->new_values, true, flags: JSON_THROW_ON_ERROR) : null;

                return $event;
            });

        return response()->json(['data' => [
            'ticket' => $this->ticketSummary($ticket),
            'messages' => $messages,
            'history' => $history,
        ]]);
    }

    public function reply(Request $request, string $ticketCode): JsonResponse
    {
        $ticket = $this->authorizedTicket($request, $ticketCode);
        abort_if($ticket->status === 'closed', 409, 'تیکت بسته شده است و پاسخ جدید نمی‌پذیرد.');
        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:5000'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
            'attachments' => ['sometimes', 'array', 'max:3'],
            'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,pdf,txt',
                'mimetypes:image/jpeg,image/png,application/pdf,text/plain'],
        ]);
        $message = $this->cleanText($data['message']);
        abort_if($message === '', 422, 'متن پاسخ پس از پاک‌سازی خالی است.');
        $actor = $request->user();

        $created = DB::transaction(function () use ($ticket, $actor, $data, $message, $request): bool {
            $existing = DB::table('support_ticket_messages')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless((int) $existing->ticket_id === (int) $ticket->id
                    && (int) $existing->sender_user_id === (int) $actor->id
                    && $existing->message === $message
                    && $this->attachmentsMatch($request->file('attachments', []), (int) $existing->id), 409,
                    'کلید تکرار برای درخواست دیگری استفاده شده است.');

                return false;
            }
            $messageId = DB::table('support_ticket_messages')->insertGetId([
                'ticket_id' => $ticket->id,
                'sender_user_id' => $actor->id,
                'message' => $message,
                'idempotency_key' => $data['idempotency_key'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->storeAttachments($request, $data['attachments'] ?? [], (int) $ticket->id, $messageId, (int) $actor->id);
            if ($ticket->status === 'open' && $this->isSupportAgent($actor->id)) {
                DB::table('support_tickets')->where('id', $ticket->id)->update([
                    'status' => 'in_progress',
                    'updated_at' => now(),
                ]);
            }
            $this->recordEvent((int) $ticket->id, (int) $actor->id, 'ticket.message_added', null, [
                'message_id' => $messageId,
                'attachments_count' => count($data['attachments'] ?? []),
            ]);

            return true;
        }, 3);

        return response()->json(['data' => ['created' => $created]], $created ? 201 : 200);
    }

    public function attachment(Request $request, string $ticketCode, int $attachmentId): StreamedResponse
    {
        $ticket = $this->authorizedTicket($request, $ticketCode);
        $attachment = DB::table('support_ticket_attachments')
            ->where('ticket_id', $ticket->id)->where('id', $attachmentId)->first();
        abort_unless($attachment && Storage::disk($attachment->storage_disk)->exists($attachment->storage_path), 404,
            'پیوست پیدا نشد.');
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($attachment->original_name)) ?: 'attachment';
        $response = Storage::disk($attachment->storage_disk)->download(
            $attachment->storage_path,
            $name,
            [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
        $this->recordEvent((int) $ticket->id, (int) $request->user()->id, 'ticket.attachment_downloaded', null, [
            'attachment_id' => $attachmentId,
        ]);

        return $response;
    }

    public function adminTickets(Request $request): JsonResponse
    {
        $this->authorizeSupport($request);
        $query = DB::table('support_tickets')
            ->leftJoin('users as opener', 'opener.id', '=', 'support_tickets.user_id')
            ->leftJoin('bookings', 'bookings.id', '=', 'support_tickets.booking_id')
            ->leftJoin('users as agent', 'agent.id', '=', 'support_tickets.assigned_agent_id')
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = '%'.$this->cleanText($request->string('q')->toString()).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('support_tickets.ticket_code', 'like', $term)
                        ->orWhere('support_tickets.subject', 'like', $term)
                        ->orWhere('support_tickets.category', 'like', $term)
                        ->orWhere('opener.name', 'like', $term);
                });
            })
            ->when($request->filled('status'), fn (Builder $query) => $query->where('support_tickets.status', $request->string('status')->toString()))
            ->when($request->filled('category'), fn (Builder $query) => $query->where('support_tickets.category', $request->string('category')->toString()))
            ->orderByDesc('support_tickets.id')
            ->select([
                'support_tickets.id',
                'support_tickets.ticket_code',
                'support_tickets.user_id',
                'opener.name as opener_name',
                'support_tickets.booking_id',
                'bookings.booking_code',
                'support_tickets.subject',
                'support_tickets.category',
                'support_tickets.priority',
                'support_tickets.status',
                'support_tickets.assigned_agent_id',
                'agent.name as assigned_agent_name',
                'support_tickets.created_at',
                'support_tickets.updated_at',
            ]);

        return response()->json(['data' => $query->paginate($this->pageSize($request))]);
    }

    public function updateTicket(Request $request, string $ticketCode): JsonResponse
    {
        $actor = $this->authorizeSupport($request);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'in_progress', 'resolved', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'emergency'])],
            'category' => ['sometimes', Rule::in(self::CATEGORIES)],
            'assigned_agent_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $reason = $this->cleanText($data['reason']);
        abort_if($reason === '', 422, 'دلیل رسیدگی الزامی است.');

        $updated = DB::transaction(function () use ($ticketCode, $actor, $data, $reason): object {
            $ticket = DB::table('support_tickets')->where('ticket_code', $ticketCode)->lockForUpdate()->first();
            abort_unless($ticket, 404, 'تیکت پیدا نشد.');
            if (array_key_exists('assigned_agent_id', $data) && $data['assigned_agent_id'] !== null) {
                abort_unless($this->isSupportAgent($data['assigned_agent_id']), 422,
                    'ارجاع تیکت فقط به پشتیبان یا اپراتور مجاز امکان‌پذیر است.');
            }
            if (isset($data['status'])) {
                abort_unless($this->validStatusTransition($ticket->status, $data['status']), 409,
                    'تغییر وضعیت با گردش‌کار تیکت سازگار نیست.');
            }
            $changes = array_intersect_key($data, array_flip(['status', 'priority', 'category', 'assigned_agent_id']));
            if (($changes['status'] ?? null) === 'resolved') {
                $changes['resolved_at'] = now();
            }
            if ($changes !== []) {
                $changes['updated_at'] = now();
                DB::table('support_tickets')->where('id', $ticket->id)->update($changes);
            }
            $this->recordEvent(
                (int) $ticket->id,
                (int) $actor->id,
                'ticket.updated',
                ['status' => $ticket->status, 'priority' => $ticket->priority,
                    'category' => $ticket->category, 'assigned_agent_id' => $ticket->assigned_agent_id],
                $changes,
                $reason,
            );

            return DB::table('support_tickets')->where('id', $ticket->id)->first();
        }, 3);

        return response()->json(['data' => $this->ticketSummary($updated)]);
    }

    public function decide(Request $request, string $ticketCode, FinancialOperationsService $finance): JsonResponse
    {
        $actor = $this->authorizeSupport($request);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['reject', 'no_action', 'cost_corrected', 'full_refund'])],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
            'password' => ['required_if:decision,full_refund', 'nullable', 'string', 'max:200'],
            'confirmation' => ['required_if:decision,full_refund', 'nullable', 'in:CONFIRM'],
        ]);
        $reason = $this->cleanText($data['reason']);
        abort_if($reason === '', 422, 'دلیل تصمیم الزامی است.');
        if ($data['decision'] === 'full_refund') {
            abort_unless($this->hasPermission($actor->id, 'finance.refund'), 403,
                'تصمیم بازپرداخت کامل فقط با مجوز مالی امکان‌پذیر است.');
            abort_unless($actor->password && Hash::check($data['password'], $actor->password), 422,
                'تأیید مجدد رمز مدیر ناموفق بود.');
        }

        $result = DB::transaction(function () use ($ticketCode, $actor, $data, $reason, $finance): array {
            $ticket = DB::table('support_tickets')->where('ticket_code', $ticketCode)->lockForUpdate()->first();
            abort_unless($ticket, 404, 'تیکت پیدا نشد.');
            abort_unless(in_array($ticket->category, ['cost_review', 'payment_dispute'], true), 422,
                'تصمیم مالی فقط برای اختلاف هزینه یا پرداخت مجاز است.');
            abort_unless(in_array($ticket->status, ['open', 'in_progress'], true), 409,
                'برای این تیکت قبلاً تصمیم ثبت شده یا تیکت در وضعیت رسیدگی نیست.');

            $refund = null;
            if ($data['decision'] === 'full_refund') {
                abort_unless($ticket->booking_id, 422, 'بازپرداخت نیازمند سفارش مرتبط است.');
                $payment = DB::table('payments')->where('booking_id', $ticket->booking_id)
                    ->where('status', 'paid')->orderByDesc('id')->first();
                abort_unless($payment, 409, 'برای سفارش پرداخت موفق و قابل بازپرداخت پیدا نشد.');
                $refund = $finance->refundToWallet(
                    (int) $payment->id,
                    (int) $actor->id,
                    $reason,
                    'support-ticket-'.$ticket->ticket_code,
                );
                if (isset($refund['error'])) {
                    abort(match ($refund['error']) {
                        'PAYMENT_NOT_FOUND' => 404,
                        'TECHNICIAN_EARNINGS_ALREADY_SPENT' => 409,
                        default => 409,
                    }, 'بازپرداخت طبق سیاست مالی در وضعیت فعلی ممکن نیست.');
                }
            }
            DB::table('support_tickets')->where('id', $ticket->id)->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
            $this->recordEvent((int) $ticket->id, (int) $actor->id, 'ticket.decision_recorded', [
                'status' => $ticket->status,
            ], [
                'decision' => $data['decision'],
                'status' => 'resolved',
                'refund_uuid' => $refund['refund_uuid'] ?? null,
                'refund_amount_minor' => $refund['amount_minor'] ?? null,
            ], $reason);

            return [
                'ticket_code' => $ticket->ticket_code,
                'decision' => $data['decision'],
                'status' => 'resolved',
                'refund' => $refund,
            ];
        }, 3);

        return response()->json(['data' => $result]);
    }

    public function moderateReviews(Request $request): JsonResponse
    {
        $this->authorizeSupport($request);
        $reviews = DB::table('reviews')->join('bookings', 'bookings.id', '=', 'reviews.booking_id')
            ->join('customer_profiles', 'customer_profiles.id', '=', 'reviews.customer_id')
            ->join('users as customer', 'customer.id', '=', 'customer_profiles.user_id')
            ->join('technician_profiles', 'technician_profiles.id', '=', 'reviews.technician_id')
            ->join('users as technician', 'technician.id', '=', 'technician_profiles.user_id')
            ->where('reviews.is_published', false)
            ->orderBy('reviews.id')
            ->paginate($this->pageSize($request), [
                'reviews.id', 'reviews.booking_id', 'bookings.booking_code',
                'reviews.rating', 'reviews.punctuality_rating', 'reviews.expertise_rating',
                'reviews.fair_price_rating', 'reviews.comment', 'reviews.created_at',
                'customer.name as customer_name', 'technician.name as technician_name',
            ]);
        $reviews->getCollection()->transform(function (object $review): object {
            $review->comment = $review->comment ? $this->cleanText($review->comment) : null;

            return $review;
        });

        return response()->json(['data' => $reviews]);
    }

    public function moderateReview(Request $request, int $reviewId): JsonResponse
    {
        $actor = $this->authorizeSupport($request);
        $data = $request->validate([
            'is_published' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $reason = $this->cleanText($data['reason']);
        $review = DB::transaction(function () use ($reviewId, $actor, $data, $reason): object {
            $review = DB::table('reviews')->where('id', $reviewId)->lockForUpdate()->first();
            abort_unless($review, 404, 'نظر پیدا نشد.');
            DB::table('reviews')->where('id', $reviewId)->update([
                'is_published' => $data['is_published'],
                'moderated_by_user_id' => $actor->id,
                'moderated_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($actor->id, 'support.review.moderated', 'review', $reviewId,
                ['is_published' => (bool) $review->is_published],
                ['is_published' => (bool) $data['is_published']], $reason);
            $this->updateTechnicianRating((int) $review->technician_id);

            return DB::table('reviews')->where('id', $reviewId)->first();
        }, 3);

        return response()->json(['data' => [
            'id' => (int) $review->id,
            'is_published' => (bool) $review->is_published,
        ]]);
    }

    public function qualityReport(Request $request): JsonResponse
    {
        $actor = $this->authorizeSupport($request);
        $technicians = DB::table('technician_profiles')
            ->join('users', 'users.id', '=', 'technician_profiles.user_id')
            ->leftJoin('reviews', function ($join): void {
                $join->on('reviews.technician_id', '=', 'technician_profiles.id')
                    ->where('reviews.is_published', true);
            })
            ->select([
                'technician_profiles.id as technician_id',
                'users.name as technician_name',
                'technician_profiles.status as technician_status',
                'technician_profiles.total_jobs_completed',
                DB::raw('COUNT(reviews.id) as published_review_count'),
                DB::raw('COALESCE(AVG(reviews.rating), 0) as average_rating'),
            ])
            ->groupBy('technician_profiles.id', 'users.name', 'technician_profiles.status',
                'technician_profiles.total_jobs_completed')
            ->orderByDesc('average_rating')
            ->paginate($this->pageSize($request));
        $technicians->getCollection()->transform(function (object $technician): object {
            $technician->open_complaint_count = DB::table('support_tickets')
                ->join('bookings', 'bookings.id', '=', 'support_tickets.booking_id')
                ->where('bookings.technician_id', $technician->technician_id)
                ->whereIn('support_tickets.status', ['open', 'in_progress'])
                ->count();
            $technician->average_rating = round((float) $technician->average_rating, 2);
            $technician->total_jobs_completed = (int) $technician->total_jobs_completed;
            $technician->published_review_count = (int) $technician->published_review_count;

            return $technician;
        });
        $this->audit((int) $actor->id, 'support.quality_report.viewed', 'technician_quality', null, null,
            ['page' => $technicians->currentPage(), 'count' => $technicians->count()]);

        return response()->json(['data' => [
            'technicians' => $technicians,
            'tickets_by_status' => DB::table('support_tickets')
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')->orderBy('status')->get(),
            'tickets_by_category' => DB::table('support_tickets')
                ->select('category', DB::raw('COUNT(*) as total'))
                ->groupBy('category')->orderBy('category')->get(),
            'generated_at' => now()->toIso8601String(),
        ]]);
    }

    public function publicQuality(int $technicianId): JsonResponse
    {
        $technician = DB::table('technician_profiles')->where('id', $technicianId)
            ->where('status', 'verified')->first(['id', 'rating', 'total_jobs_completed']);
        abort_unless($technician, 404, 'متخصص پیدا نشد.');
        $reviews = DB::table('reviews')->where('technician_id', $technicianId)
            ->where('is_published', true);
        $summary = (clone $reviews)->selectRaw('COUNT(*) as count, COALESCE(AVG(rating), 0) as average')->first();

        return response()->json(['data' => [
            'technician_id' => (int) $technician->id,
            'average_rating' => round((float) $summary->average, 2),
            'review_count' => (int) $summary->count,
            'completed_jobs' => (int) $technician->total_jobs_completed,
        ]]);
    }

    public function createReview(Request $request, int $bookingId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->status === 'active', 401, 'احراز هویت فعال لازم است.');
        $customerId = DB::table('customer_profiles')->where('user_id', $actor->id)->value('id');
        abort_unless($customerId, 403, 'ثبت امتیاز فقط برای حساب مشتری مجاز است.');
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'punctuality_rating' => ['nullable', 'integer', 'between:1,5'],
            'expertise_rating' => ['nullable', 'integer', 'between:1,5'],
            'fair_price_rating' => ['nullable', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $comment = isset($data['comment']) ? $this->cleanText($data['comment']) : null;
        $result = DB::transaction(function () use ($bookingId, $customerId, $actor, $data, $comment): array {
            $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            abort_unless($booking && (int) $booking->customer_id === (int) $customerId, 404,
                'سفارش تکمیل‌شدهٔ متعلق به شما پیدا نشد.');
            abort_unless($booking->status === 'completed' && $booking->completed_at && $booking->technician_id,
                409, 'ثبت امتیاز فقط پس از تکمیل معتبر سفارش امکان‌پذیر است.');
            abort_unless(DB::table('assignments')->where('booking_id', $bookingId)
                ->where('technician_id', $booking->technician_id)->where('status', 'fulfilled')->exists(),
                409, 'تخصیص تکمیل‌شدهٔ متخصص برای سفارش پیدا نشد.');
            abort_if(DB::table('reviews')->where('booking_id', $bookingId)->exists(), 409,
                'برای این سفارش قبلاً نظر ثبت شده است.');

            $reviewId = DB::table('reviews')->insertGetId([
                'booking_id' => $bookingId,
                'customer_id' => $customerId,
                'technician_id' => $booking->technician_id,
                'rating' => $data['rating'],
                'punctuality_rating' => $data['punctuality_rating'] ?? null,
                'expertise_rating' => $data['expertise_rating'] ?? null,
                'fair_price_rating' => $data['fair_price_rating'] ?? null,
                'comment' => $comment,
                'is_published' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit((int) $actor->id, 'customer.review.created', 'review', $reviewId, null, [
                'booking_id' => $bookingId,
                'technician_id' => (int) $booking->technician_id,
                'rating' => (int) $data['rating'],
                'moderation_status' => 'pending',
            ]);

            return ['id' => $reviewId, 'moderation_status' => 'pending'];
        }, 3);

        return response()->json(['data' => $result], 201);
    }

    private function authorizedTicket(Request $request, string $ticketCode): object
    {
        $actor = $request->user();
        abort_unless($actor && $actor->status === 'active', 401, 'احراز هویت فعال لازم است.');
        $ticket = DB::table('support_tickets')->where('ticket_code', $ticketCode)->first();
        abort_unless($ticket && $this->canViewTicket($actor->id, $ticket), 404,
            'تیکت در دسترس نیست.');

        return $ticket;
    }

    private function canViewTicket(int $userId, object $ticket): bool
    {
        return (int) $ticket->user_id === $userId || $this->isSupportAgent($userId);
    }

    private function isBookingParty(int $userId, object $booking): bool
    {
        $customer = (int) DB::table('customer_profiles')->where('id', $booking->customer_id)->value('user_id');
        if ($customer === $userId) {
            return true;
        }

        return $booking->technician_id && (int) DB::table('technician_profiles')
            ->where('id', $booking->technician_id)->value('user_id') === $userId;
    }

    private function isSupportAgent(int $userId): bool
    {
        return DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('users', 'users.id', '=', 'role_user.user_id')
            ->where('role_user.user_id', $userId)
            ->where('users.status', 'active')
            ->whereIn('roles.name', self::STAFF_ROLES)
            ->exists();
    }

    private function authorizeSupport(Request $request): object
    {
        $actor = $request->user();
        abort_unless($actor && $actor->status === 'active', 401, 'احراز هویت فعال لازم است.');
        abort_unless($this->isSupportAgent($actor->id), 403, 'مجوز رسیدگی به تیکت‌ها را ندارید.');

        return $actor;
    }

    private function hasPermission(int $userId, string $permission): bool
    {
        return DB::table('role_user')->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('users', 'users.id', '=', 'role_user.user_id')
            ->where('role_user.user_id', $userId)->where('users.status', 'active')
            ->where('permissions.name', $permission)->exists();
    }

    private function storeAttachments(Request $request, array $files, int $ticketId, int $messageId, int $actorId): void
    {
        foreach ($files as $file) {
            $path = $file->store('support-tickets/'.$ticketId, 'local');
            if (! is_string($path) || $path === '') {
                throw new \RuntimeException('ذخیره امن پیوست تیکت ناموفق بود.');
            }

            $originalName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file->getClientOriginalName())) ?: 'attachment';
            DB::table('support_ticket_attachments')->insert([
                'ticket_id' => $ticketId,
                'message_id' => $messageId,
                'uploaded_by_user_id' => $actorId,
                'storage_disk' => 'local',
                'storage_path' => $path,
                'original_name' => substr($originalName, 0, 180),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => (int) $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'created_at' => now(),
            ]);
        }
    }

    private function attachmentsMatch(array $files, int $messageId): bool
    {
        $stored = DB::table('support_ticket_attachments')->where('message_id', $messageId)
            ->orderBy('id')->pluck('sha256')->all();
        $incoming = array_map(
            fn ($file): string => hash_file('sha256', $file->getRealPath()),
            $files,
        );

        return $stored === $incoming;
    }

    private function recordEvent(
        int $ticketId,
        ?int $actorId,
        string $type,
        ?array $oldValues,
        ?array $newValues,
        ?string $reason = null,
    ): void {
        DB::table('support_ticket_events')->insert([
            'ticket_id' => $ticketId,
            'actor_user_id' => $actorId,
            'event_type' => $type,
            'old_values' => $oldValues === null ? null : json_encode($oldValues, JSON_THROW_ON_ERROR),
            'new_values' => $newValues === null ? null : json_encode($newValues, JSON_THROW_ON_ERROR),
            'reason' => $reason ? $this->cleanText($reason) : null,
            'created_at' => now(),
        ]);
    }

    private function audit(
        int $actorId,
        string $action,
        string $type,
        ?int $id,
        ?array $old,
        ?array $new,
        ?string $reason = null,
    ): void {
        if ($reason !== null) {
            $new = [...($new ?? []), 'reason' => $this->cleanText($reason)];
        }
        DB::table('audit_logs')->insert([
            'user_id' => $actorId,
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 1000),
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function updateTechnicianRating(int $technicianId): void
    {
        $stats = DB::table('reviews')->where('technician_id', $technicianId)
            ->where('is_published', true)->selectRaw('COUNT(*) as review_count, AVG(rating) as average_rating')->first();
        DB::table('technician_profiles')->where('id', $technicianId)->update([
            'rating' => $stats->review_count ? round((float) $stats->average_rating, 2) : 5.00,
            'updated_at' => now(),
        ]);
    }

    private function ticketSummary(object $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'ticket_code' => $ticket->ticket_code,
            'booking_id' => $ticket->booking_id ? (int) $ticket->booking_id : null,
            'subject' => $this->cleanText($ticket->subject),
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'assigned_agent_id' => $ticket->assigned_agent_id ? (int) $ticket->assigned_agent_id : null,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
        ];
    }

    private function validStatusTransition(string $from, string $to): bool
    {
        return in_array($to, match ($from) {
            'open' => ['open', 'in_progress', 'closed', 'resolved'],
            'in_progress' => ['in_progress', 'open', 'closed', 'resolved'],
            'resolved' => ['resolved', 'closed', 'open'],
            'closed' => ['closed', 'open'],
            default => [],
        }, true);
    }

    private function defaultPriority(string $category): string
    {
        return match ($category) {
            'property_damage', 'misconduct', 'payment_dispute', 'cost_review' => 'high',
            default => 'medium',
        };
    }

    private function cleanText(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        return trim($text);
    }

    private function pageSize(Request $request): int
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return (int) ($data['per_page'] ?? 20);
    }
}
