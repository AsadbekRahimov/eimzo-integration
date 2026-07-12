<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use AsadbekRahimov\EimzoIntegration\Models\EimzoSignature;
use AsadbekRahimov\EimzoIntegration\Tests\TestCase;

class SignatureAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_view_stored_signatures(): void
    {
        $sig = $this->makeSignature(null);

        $r = $this->getJson('/eimzo/signatures/' . $sig->id);

        $r->assertStatus(403);
        $this->assertSame(-1, $r->json('status'));
    }

    public function test_owner_can_view_their_signature(): void
    {
        $user = TestUser::create(['name' => 'Owner', 'email' => 'owner@example.test']);
        $sig = $this->makeSignature($user->id);

        $r = $this->actingAs($user)->getJson('/eimzo/signatures/' . $sig->id);

        $r->assertOk();
        $this->assertSame(1, $r->json('status'));
        $this->assertSame($sig->id, $r->json('signature.id'));
    }

    public function test_other_users_cannot_view_foreign_signatures(): void
    {
        $owner = TestUser::create(['name' => 'Owner', 'email' => 'owner@example.test']);
        $other = TestUser::create(['name' => 'Other', 'email' => 'other@example.test']);
        $sig = $this->makeSignature($owner->id);

        $this->actingAs($other)
            ->getJson('/eimzo/signatures/' . $sig->id)
            ->assertStatus(403);
    }

    public function test_authenticated_users_cannot_view_ownerless_signatures_by_default(): void
    {
        $user = TestUser::create(['name' => 'User', 'email' => 'user@example.test']);
        $sig = $this->makeSignature(null);

        $this->actingAs($user)
            ->getJson('/eimzo/signatures/' . $sig->id)
            ->assertStatus(403);
    }

    public function test_registered_policy_takes_over_the_ownership_default(): void
    {
        Gate::policy(EimzoSignature::class, PublicSignaturePolicy::class);
        $sig = $this->makeSignature(null);

        // The permissive policy applies even to guests.
        $r = $this->getJson('/eimzo/signatures/' . $sig->id);

        $r->assertOk();
        $this->assertSame($sig->id, $r->json('signature.id'));
    }

    private function makeSignature(?int $userId): EimzoSignature
    {
        return EimzoSignature::create([
            'user_id' => $userId,
            'document_type' => 'contract',
            'document_name' => 'contract.json',
            'pkcs7' => base64_encode('envelope'),
            'verification_status' => EimzoSignature::STATUS_VALID,
        ]);
    }
}

class PublicSignaturePolicy
{
    public function view(?TestUser $user, EimzoSignature $signature): bool
    {
        return true;
    }
}
