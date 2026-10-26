<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Tests\TestCase;

class BrandedMailTest extends TestCase
{
    public function test_notification_emails_use_the_tidemark_theme(): void
    {
        $user = new User(['name' => 'Sam', 'email' => 'sam@example.com']);
        $user->id = 'user-id';

        foreach ([new VerifyEmail, new ResetPassword('token')] as $notification) {
            $html = (string) $notification->toMail($user)->render();

            $this->assertStringContainsString('#0f6f78', $html);
            $this->assertStringContainsString('The '.config('app.name').' team', $html);
            $this->assertStringNotContainsString('laravel.com', $html);
            $this->assertStringNotContainsString('All rights reserved', $html);
        }
    }
}
