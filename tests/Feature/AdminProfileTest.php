<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake('public');
    }

    public function test_administrator_can_view_their_own_profile_and_account_information(): void
    {
        $admin = $this->administrator([
            'name' => 'Sports Coordinator',
            'email' => 'coordinator@example.com',
            'username' => 'coordinator',
            'email_verified_at' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.profile'))
            ->assertOk()
            ->assertSee('Sports Coordinator')
            ->assertSee('coordinator@example.com')
            ->assertSee('coordinator')
            ->assertSee('Administrator / Sports Coordinator')
            ->assertSee($admin->created_at->format('M j, Y'))
            ->assertSee('This email address is not marked as verified.')
            ->assertSee('SC')
            ->assertSee('Change password');
    }

    public function test_admin_navigation_and_sidebar_avatar_link_to_the_admin_profile(): void
    {
        $admin = $this->administrator(['name' => 'Sports Coordinator']);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('admin.profile'), false)
            ->assertSee('Sports Coordinator')
            ->assertSee('SC');
    }

    public function test_administrator_can_update_name_email_and_username_without_changing_role(): void
    {
        $admin = $this->administrator(['email_verified_at' => now()]);

        $this->actingAs($admin)
            ->from(route('admin.profile'))
            ->patch(route('admin.profile.update'), [
                'name' => 'Updated Coordinator',
                'email' => 'updated@example.com',
                'username' => 'updated-coordinator',
                'role' => 'Student',
                'status' => 'Inactive',
            ])
            ->assertRedirect(route('admin.profile'))
            ->assertSessionHas('success', 'Profile updated. The new email address is not marked as verified.');

        $admin->refresh();
        $this->assertSame('Updated Coordinator', $admin->name);
        $this->assertSame('updated@example.com', $admin->email);
        $this->assertSame('updated-coordinator', $admin->username);
        $this->assertSame('Administrator', $admin->role);
        $this->assertSame('Active', $admin->status);
        $this->assertNull($admin->email_verified_at);
    }

    public function test_email_must_be_unique_and_username_must_be_unique_when_provided(): void
    {
        $this->administrator(['email' => 'existing@example.com', 'username' => 'existing-admin']);
        $admin = $this->administrator();
        $originalEmail = $admin->email;

        $this->actingAs($admin)
            ->from(route('admin.profile'))
            ->patch(route('admin.profile.update'), [
                'name' => 'Updated',
                'email' => 'existing@example.com',
                'username' => 'existing-admin',
            ])
            ->assertSessionHasErrors(['email', 'username']);

        $this->assertSame($originalEmail, $admin->fresh()->email);
    }

    public function test_administrator_can_upload_replace_and_remove_their_profile_picture(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)->from(route('admin.profile'))->patch(route('admin.profile.update'), [
            'name' => $admin->name,
            'email' => $admin->email,
            'profile_photo' => $this->fakePhoto('first.png'),
        ])->assertRedirect(route('admin.profile'));

        $firstPhoto = $admin->fresh()->profile_photo_path;
        $this->assertNotNull($firstPhoto);
        Storage::disk('public')->assertExists($firstPhoto);
        $this->actingAs($admin->fresh())
            ->get(route('admin.profile'))
            ->assertSee(Storage::disk('public')->url($firstPhoto), false);
        $this->actingAs($admin->fresh())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($firstPhoto), false);

        $this->actingAs($admin)->from(route('admin.profile'))->patch(route('admin.profile.update'), [
            'name' => $admin->name,
            'email' => $admin->email,
            'profile_photo' => $this->fakePhoto('replacement.png'),
        ])->assertRedirect(route('admin.profile'));

        $replacement = $admin->fresh()->profile_photo_path;
        $this->assertNotSame($firstPhoto, $replacement);
        Storage::disk('public')->assertMissing($firstPhoto);
        Storage::disk('public')->assertExists($replacement);

        $this->actingAs($admin)->from(route('admin.profile'))->patch(route('admin.profile.update'), [
            'name' => $admin->name,
            'email' => $admin->email,
            'remove_photo' => '1',
        ])->assertRedirect(route('admin.profile'));

        $this->assertNull($admin->fresh()->profile_photo_path);
        Storage::disk('public')->assertMissing($replacement);
    }

    public function test_invalid_profile_picture_is_rejected(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->from(route('admin.profile'))
            ->patch(route('admin.profile.update'), [
                'name' => $admin->name,
                'email' => $admin->email,
                'profile_photo' => UploadedFile::fake()->create('document.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasErrors('profile_photo');

        $this->assertNull($admin->fresh()->profile_photo_path);
    }

    public function test_oversized_profile_picture_is_rejected(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->from(route('admin.profile'))
            ->patch(route('admin.profile.update'), [
                'name' => $admin->name,
                'email' => $admin->email,
                'profile_photo' => $this->fakePhoto('large.png', 3 * 1024),
            ])
            ->assertSessionHasErrors('profile_photo');

        $this->assertNull($admin->fresh()->profile_photo_path);
    }

    public function test_removing_a_photo_does_not_delete_a_file_referenced_by_another_user(): void
    {
        $admin = $this->administrator();
        $student = User::factory()->create(['role' => 'Student']);
        $sharedPath = 'profile-photos/shared.png';
        Storage::disk('public')->put($sharedPath, 'shared photo');
        $admin->update(['profile_photo_path' => $sharedPath]);
        $student->update(['profile_photo_path' => $sharedPath]);

        $this->actingAs($admin)->from(route('admin.profile'))->patch(route('admin.profile.update'), [
            'name' => $admin->name,
            'email' => $admin->email,
            'remove_photo' => '1',
        ])->assertRedirect(route('admin.profile'));

        $this->assertNull($admin->fresh()->profile_photo_path);
        $this->assertSame($sharedPath, $student->fresh()->profile_photo_path);
        Storage::disk('public')->assertExists($sharedPath);
    }

    public function test_administrator_can_change_password_after_entering_the_current_password(): void
    {
        $admin = $this->administrator(['password' => 'OldPassword1']);

        $this->actingAs($admin)
            ->from(route('admin.profile'))
            ->patch(route('admin.profile.password'), [
                'current_password' => 'OldPassword1',
                'password' => 'NewPassword2',
                'password_confirmation' => 'NewPassword2',
            ])
            ->assertRedirect(route('admin.profile'))
            ->assertSessionHas('success', 'Your password has been changed successfully.');

        $this->assertTrue(Hash::check('NewPassword2', $admin->fresh()->password));
        $this->assertDatabaseMissing('users', ['password' => 'NewPassword2']);
    }

    public function test_incorrect_current_password_and_mismatched_confirmation_are_rejected(): void
    {
        $admin = $this->administrator(['password' => 'OldPassword1']);

        $this->actingAs($admin)
            ->from(route('admin.profile'))
            ->patch(route('admin.profile.password'), [
                'current_password' => 'WrongPassword1',
                'password' => 'NewPassword2',
                'password_confirmation' => 'NewPassword2',
            ])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($admin)
            ->from(route('admin.profile'))
            ->patch(route('admin.profile.password'), [
                'current_password' => 'OldPassword1',
                'password' => 'NewPassword2',
                'password_confirmation' => 'NotTheSame2',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('OldPassword1', $admin->fresh()->password));
    }

    public function test_students_and_guests_cannot_access_admin_profile(): void
    {
        $student = User::factory()->create(['role' => 'Student']);

        $this->get(route('admin.profile'))->assertRedirect(route('login'));
        $this->actingAs($student)->get(route('admin.profile'))->assertForbidden();
        $this->actingAs($student)->patch(route('admin.profile.update'), [
            'name' => 'Changed',
            'email' => $student->email,
        ])->assertForbidden();
    }

    public function test_profile_endpoint_has_no_other_account_target_and_ignores_submitted_user_ids(): void
    {
        $admin = $this->administrator();
        $otherAdmin = $this->administrator(['name' => 'Other Coordinator', 'email' => 'other@example.com']);

        $this->actingAs($admin)->from(route('admin.profile'))->patch(route('admin.profile.update'), [
            'name' => 'My Updated Name',
            'email' => $admin->email,
            'user_id' => $otherAdmin->id,
            'admin_id' => $otherAdmin->id,
            'role' => 'Student',
        ])->assertRedirect(route('admin.profile'));

        $this->assertSame('My Updated Name', $admin->fresh()->name);
        $this->assertSame('Other Coordinator', $otherAdmin->fresh()->name);
        $this->assertSame('Administrator', $admin->fresh()->role);
    }

    private function administrator(array $attributes = []): User
    {
        static $nextId = 0;
        $nextId++;

        return User::factory()->create(array_merge([
            'role' => 'Administrator',
            'status' => 'Active',
            'name' => 'Admin User',
            'email' => 'admin'.$nextId.'@example.com',
            'password' => 'OldPassword1',
        ], $attributes));
    }

    private function fakePhoto(string $name, int $paddingKilobytes = 0): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($name, $png.str_repeat('0', $paddingKilobytes * 1024));
    }
}
