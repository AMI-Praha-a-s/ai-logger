<?php

namespace AmiPraha\AILogger\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Log;

class UserTrackingTest extends TestCase
{
    public function test_a_user_with_a_uuid_key_is_sent_with_it(): void
    {
        $this->actingAs(new GenericUser(['id' => '9b2f7c4e-1d3a-4f6b-8e2d-5a7c9b1e3f4d', 'email' => 'jana@example.test']));

        Log::warning('Payment failed');

        $userTrackingData = $this->lastSentPayload()['user_tracking_data'];

        $this->assertSame('9b2f7c4e-1d3a-4f6b-8e2d-5a7c9b1e3f4d', $userTrackingData['logger_user_id']);
        $this->assertSame('jana@example.test', $userTrackingData['logger_user_email']);
    }

    public function test_a_user_with_an_integer_key_is_sent_with_it(): void
    {
        $this->actingAs(new GenericUser(['id' => 27, 'email' => 'jana@example.test']));

        Log::warning('Payment failed');

        $this->assertSame(27, $this->lastSentPayload()['user_tracking_data']['logger_user_id']);
    }
}
