<?php

namespace Database\Seeders;

use App\Enums\JobStatus;
use App\Enums\MessageType;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\FreightJob;
use App\Models\JobAcceptance;
use App\Models\JobQuote;
use App\Models\JobTracking;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Seeds freight jobs spread across the whole lifecycle so every dashboard
 * view has something realistic to render.
 */
class MarketplaceSeeder extends Seeder
{
    /** @var Collection<int, User> */
    private Collection $shippers;

    /** @var Collection<int, User> */
    private Collection $carriers;

    public function run(): void
    {
        $this->shippers = User::where('role', UserRole::Shipper)->get();
        $this->carriers = User::where('role', UserRole::Carrier)->get();

        if ($this->shippers->isEmpty() || $this->carriers->isEmpty()) {
            $this->command?->warn('No shippers or carriers found — run UserSeeder first.');

            return;
        }

        // Jobs that never left the shipper's drafts.
        $this->makeJobs(4, JobStatus::Draft, quotes: 0);

        // Live on the board, still collecting interest.
        $this->makeJobs(8, JobStatus::Published, quotes: 0);
        $this->makeJobs(5, JobStatus::Quoted, quotes: 4);

        // Won work, in progress.
        $this->makeJobs(4, JobStatus::Accepted, quotes: 3, accept: true);

        // Delivered and reviewed.
        $this->makeJobs(6, JobStatus::Completed, quotes: 3, accept: true, complete: true);

        // Unhappy paths for admin moderation views.
        $this->makeJobs(2, JobStatus::Cancelled, quotes: 2);
        $this->makeJobs(1, JobStatus::Disputed, quotes: 2, accept: true);
    }

    private function makeJobs(int $count, JobStatus $status, int $quotes, bool $accept = false, bool $complete = false): void
    {
        for ($i = 0; $i < $count; $i++) {
            $shipper = $this->shippers->random();

            $job = FreightJob::factory()->status($status)->create([
                'shipper_id' => $shipper->id,
                'created_by' => $shipper->id,
                'updated_by' => $shipper->id,
            ]);

            // Distinct carriers, so the unique(job_id, carrier_id) index holds.
            $bidders = $this->carriers->shuffle()->take($quotes);

            $submitted = $bidders->map(fn (User $carrier) => JobQuote::factory()->create([
                'job_id' => $job->id,
                'carrier_id' => $carrier->id,
            ]));

            if ($submitted->isNotEmpty()) {
                $this->notify($shipper, 'quote_received', 'New quote received',
                    "{$submitted->count()} carrier(s) quoted on \"{$job->title}\".", $job);
            }

            if (! $accept || $submitted->isEmpty()) {
                continue;
            }

            $this->acceptQuote($job, $shipper, $submitted, $complete);
        }
    }

    /**
     * @param  Collection<int, JobQuote>  $submitted
     */
    private function acceptQuote(FreightJob $job, User $shipper, Collection $submitted, bool $complete): void
    {
        // Cast to float: the decimal:2 cast returns strings, which sort lexically.
        $winning = $submitted->sortBy(fn (JobQuote $quote) => (float) $quote->amount)->first();
        $carrier = $this->carriers->firstWhere('id', $winning->carrier_id);

        $winning->update(['status' => QuoteStatus::Accepted]);
        $submitted->reject(fn (JobQuote $quote) => $quote->is($winning))
            ->each(fn (JobQuote $quote) => $quote->update(['status' => QuoteStatus::Rejected]));

        JobAcceptance::create([
            'job_id' => $job->id,
            'quote_id' => $winning->id,
            'carrier_id' => $carrier->id,
            'shipper_id' => $shipper->id,
            'accepted_at' => now()->subDays(fake()->numberBetween(1, 30)),
        ]);

        JobTracking::create([
            'job_id' => $job->id,
            'current_status' => $complete ? 'delivered' : fake()->randomElement(['awaiting_pickup', 'in_transit']),
            'last_location' => $complete ? $job->delivery_location : $job->pickup_location,
            'eta' => $complete ? null : now()->addDays(fake()->numberBetween(1, 9)),
        ]);

        Payment::factory()
            ->when($complete, fn ($factory) => $factory->paid())
            ->create([
                'job_id' => $job->id,
                'payer_id' => $shipper->id,
                'payee_id' => $carrier->id,
                'amount' => $winning->amount,
                'status' => $complete ? PaymentStatus::Paid : PaymentStatus::Pending,
            ]);

        $this->notify($carrier, 'quote_accepted', 'Your quote was accepted',
            "You won the job \"{$job->title}\".", $job);

        $this->startConversation($job, $shipper, $carrier);

        if (! $complete) {
            return;
        }

        // Both sides review each other once the job is done.
        Review::factory()->create([
            'job_id' => $job->id,
            'reviewer_id' => $shipper->id,
            'reviewed_user_id' => $carrier->id,
        ]);

        Review::factory()->create([
            'job_id' => $job->id,
            'reviewer_id' => $carrier->id,
            'reviewed_user_id' => $shipper->id,
        ]);
    }

    private function startConversation(FreightJob $job, User $shipper, User $carrier): void
    {
        $conversation = Conversation::create([
            'job_id' => $job->id,
            'participant_one_id' => $shipper->id,
            'participant_two_id' => $carrier->id,
        ]);

        $script = [
            [$carrier, 'Thanks for accepting. I can have a truck at the pickup site from 7am.'],
            [$shipper, 'Perfect. The loading dock is on the south side, ask for the warehouse manager.'],
            [$carrier, 'Noted. I will send through the consignment note once we are loaded.'],
        ];

        foreach ($script as $index => [$sender, $body]) {
            Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'message_type' => MessageType::Text,
                'body' => $body,
                'read_at' => $index < 2 ? now()->subHours(3) : null,
                'created_at' => now()->subHours(6 - $index),
                'updated_at' => now()->subHours(6 - $index),
            ]);
        }
    }

    private function notify(User $user, string $type, string $title, string $body, FreightJob $job): void
    {
        Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'is_read' => fake()->boolean(40),
            'related_type' => FreightJob::class,
            'related_id' => $job->id,
        ]);
    }
}
