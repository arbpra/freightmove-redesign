<?php

namespace Database\Seeders;

use App\Enums\PostStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\BlogPost;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Marketing content and the admin support queue.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();

        $articles = [
            ['How to price a freight job so carriers actually quote', 'Pricing'],
            ['Choosing between a semi trailer and a B-double', 'Equipment'],
            ['What shippers should check before accepting a quote', 'Shipping'],
            ['Chain of Responsibility: what it means for small carriers', 'Compliance'],
            ['Backloading explained, and when it saves you money', 'Pricing'],
            ['Preparing palletised freight for interstate transport', 'Shipping'],
        ];

        foreach ($articles as [$title, $category]) {
            BlogPost::factory()->create([
                'title' => $title,
                'slug' => Str::slug($title),
                'excerpt' => "A practical guide for Australian shippers and carriers. Filed under {$category}.",
                'author_id' => $admin?->id,
                'status' => PostStatus::Published,
                'published_at' => fake()->dateTimeBetween('-8 months', '-1 day'),
            ]);
        }

        BlogPost::factory()->draft()->count(2)->create(['author_id' => $admin?->id]);

        $users = User::whereIn('role', [UserRole::Shipper, UserRole::Carrier])->get();

        SupportTicket::factory()->count(6)->create([
            'user_id' => fn () => $users->random()->id,
        ]);

        SupportTicket::factory()->resolved()->count(4)->create([
            'user_id' => fn () => $users->random()->id,
        ]);

        SupportTicket::factory()->create([
            'user_id' => $users->random()->id,
            'subject' => 'Carrier did not arrive for scheduled pickup',
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Urgent,
        ]);
    }
}
