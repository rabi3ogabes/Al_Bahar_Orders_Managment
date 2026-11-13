<?php

use App\Domain\Settings\Services\Settings;

/** A real (throwaway) RSA key, made once per run: the code under test signs with it. */
function throwawayPrivateKey(): string
{
    static $pem = null;
    if ($pem === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
    }

    return $pem;
}

/** @return array<string, string> what Firebase's "Generate new private key" file looks like (all values fake) */
function fakeServiceAccount(string $project = 'al-bahar-home', string $email = 'firebase-adminsdk-abc12@al-bahar-home.iam.gserviceaccount.com'): array
{
    return [
        'type' => 'service_account', 'project_id' => $project, 'private_key_id' => 'kid9f8e7d6c5b4a', 'private_key' => throwawayPrivateKey(),
        'client_email' => $email, 'client_id' => '109876543210', 'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
        'token_uri' => 'https://evil.example/steal', 'universe_domain' => 'googleapis.com',
    ];
}

function fakeServiceAccountJson(string $project = 'al-bahar-home', string $email = 'firebase-adminsdk-abc12@al-bahar-home.iam.gserviceaccount.com'): string
{
    return json_encode(fakeServiceAccount($project, $email));
}

/** @return array<string, string> valid example values for the six form fields */
function firebaseFormValues(): array
{
    return [
        'projectId' => 'al-bahar-home', 'apiKey' => 'AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q', 'authDomain' => '',
        'senderId' => '123456789012', 'appId' => '1:123456789012:web:0a1b2c3d4e5f6a7b8c9d0e',
        'vapidKey' => 'BAswVXqfxOkOM1h9osfsETZbgKXK7xQ5XoOozfIXPGGGq9D1Gj9kia7T-B1CZ4yx1vsgRWqPtNn-I0htkrfcASY',
    ];
}

/** Saves a complete Firebase setup straight through the Settings service (what the dashboard page does). */
function configureFirebase(bool $enabled = true, bool $withKey = true): void
{
    $s = app(Settings::class);
    $s->set('push.firebase.project_id', 'al-bahar-home');
    $s->set('push.firebase.api_key', 'AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q');
    $s->set('push.firebase.messaging_sender_id', '123456789012');
    $s->set('push.firebase.app_id_web', '1:123456789012:web:0a1b2c3d4e5f6a7b8c9d0e');
    $s->set('push.firebase.vapid_key', firebaseFormValues()['vapidKey']);
    if ($withKey) {
        $s->set('push.firebase.service_account', fakeServiceAccountJson(), secret: true);
    }
    $s->set('push.enabled', $enabled);
}

/** A company-wide staff member who holds exactly these permission keys (for "can look but not change" checks). */
function staffWithPermissions(array $keys): App\Domain\Identity\Models\User
{
    $user = App\Domain\Identity\Models\User::factory()->staff()->create();
    $role = App\Domain\Identity\Models\Role::create(['key' => 'push_'.Illuminate\Support\Str::random(6), 'name_en' => 'Push tester', 'name_ar' => 'مختبر', 'scope' => 'global']);
    $role->permissions()->sync(App\Domain\Identity\Models\Permission::whereIn('key', $keys)->pluck('id'));
    App\Domain\Identity\Models\UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => null]);

    return $user;
}
