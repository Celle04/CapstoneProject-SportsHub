<div class="student-profile admin-profile">
    @if (session('success'))
        <div class="notice" role="status">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="admin-profile-errors" role="alert">
            <strong>Please correct the following:</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <header class="student-profile-hero">
        <div class="student-profile-identity">
            <x-avatar :user="$admin" size="xl" id="profile-photo-preview" class="student-profile-avatar" />
            <div class="student-profile-name">
                <span>ADMIN PROFILE</span>
                <h2>{{ $admin->name }}</h2>
                <p>{{ $admin->email }}</p>
            </div>
        </div>
        <div class="student-profile-id">
            <span>ROLE</span>
            <strong>Administrator / Sports Coordinator</strong>
        </div>
    </header>

    <div class="student-profile-columns">
        <form class="student-profile-column" method="POST" action="{{ route('admin.profile.update') }}" enctype="multipart/form-data">
            @csrf
            @method('PATCH')

            <section class="student-profile-section">
                <header class="student-profile-section-heading">
                    <span>PROFILE INFORMATION</span>
                    <h3>Personal account details</h3>
                </header>

                <div class="student-profile-edit-grid">
                    <div class="form-group">
                        <label for="admin-profile-name">Full name</label>
                        <input class="form-control @error('name') is-invalid @enderror" id="admin-profile-name" name="name" type="text" value="{{ old('name', $admin->name) }}" autocomplete="name" required>
                        @error('name')<small class="form-error">{{ $message }}</small>@enderror
                    </div>
                    <div class="form-group">
                        <label for="admin-profile-email">Email address</label>
                        <input class="form-control @error('email') is-invalid @enderror" id="admin-profile-email" name="email" type="email" value="{{ old('email', $admin->email) }}" autocomplete="email" required>
                        @if ($admin->email_verified_at)
                            <small class="form-hint">Verified {{ $admin->email_verified_at->format('M j, Y') }}.</small>
                        @else
                            <small class="form-hint">This email address is not marked as verified.</small>
                        @endif
                        @error('email')<small class="form-error">{{ $message }}</small>@enderror
                    </div>
                    <div class="form-group">
                        <label for="admin-profile-username">Username</label>
                        <input class="form-control @error('username') is-invalid @enderror" id="admin-profile-username" name="username" type="text" value="{{ old('username', $admin->username) }}" autocomplete="username">
                        <small class="form-hint">Optional. Your email address remains available for sign-in.</small>
                        @error('username')<small class="form-error">{{ $message }}</small>@enderror
                    </div>
                    <div class="form-group">
                        <label for="admin-profile-role">Role</label>
                        <input class="form-control" id="admin-profile-role" type="text" value="Administrator / Sports Coordinator" readonly>
                        <small class="form-hint">Your role cannot be changed from profile settings.</small>
                    </div>
                </div>
            </section>

            <section class="student-profile-section">
                <header class="student-profile-section-heading">
                    <span>PROFILE PICTURE</span>
                    <h3>Update your photo</h3>
                </header>

                <div class="student-profile-photo-grid">
                    <div class="form-group student-profile-photo-field">
                        <label for="admin-profile-photo">Upload a new picture</label>
                        <input class="form-control @error('profile_photo') is-invalid @enderror" id="admin-profile-photo" name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" data-photo-input>
                        <small>JPG, JPEG, PNG or WEBP &middot; maximum 2 MB.</small>
                        @error('profile_photo')<small class="form-error">{{ $message }}</small>@enderror
                    </div>

                    @if ($admin->profile_photo_path)
                        <div class="form-group student-profile-remove-field">
                            <label for="admin-remove-photo">Remove current picture</label>
                            <label class="student-profile-check" for="admin-remove-photo">
                                <input id="admin-remove-photo" name="remove_photo" type="checkbox" value="1" @checked(old('remove_photo'))>
                                <span>Delete my current picture</span>
                            </label>
                        </div>
                    @endif
                </div>
            </section>

            <button class="button student-profile-save" type="submit">Save Profile</button>
        </form>

        <div class="student-profile-column">
            <section class="student-profile-section">
                <header class="student-profile-section-heading">
                    <span>ACCOUNT SECURITY</span>
                    <h3>Change password</h3>
                </header>

                <form method="POST" action="{{ route('admin.profile.password') }}" data-loading-label="Updating...">
                    @csrf
                    @method('PATCH')

                    <div class="student-profile-password-grid">
                        <div class="form-group">
                            <label for="admin-current-password">Current password</label>
                            <span class="student-profile-control">
                                <input class="form-control @error('current_password') is-invalid @enderror" id="admin-current-password" name="current_password" type="password" autocomplete="current-password" required>
                                <button class="student-profile-toggle" type="button" data-password-toggle="admin-current-password" aria-label="Show password" aria-pressed="false">
                                    <svg aria-hidden="true"><use href="#icon-eye"></use></svg>
                                </button>
                            </span>
                            @error('current_password')<small class="form-error">{{ $message }}</small>@enderror
                        </div>
                        <div class="form-group">
                            <label for="admin-new-password">New password</label>
                            <span class="student-profile-control">
                                <input class="form-control @error('password') is-invalid @enderror" id="admin-new-password" name="password" type="password" minlength="10" autocomplete="new-password" required>
                                <button class="student-profile-toggle" type="button" data-password-toggle="admin-new-password" aria-label="Show password" aria-pressed="false">
                                    <svg aria-hidden="true"><use href="#icon-eye"></use></svg>
                                </button>
                            </span>
                            <small class="form-hint">At least 10 characters, including uppercase, lowercase, and a number.</small>
                            @error('password')<small class="form-error">{{ $message }}</small>@enderror
                        </div>
                        <div class="form-group">
                            <label for="admin-confirm-password">Confirm new password</label>
                            <span class="student-profile-control">
                                <input class="form-control" id="admin-confirm-password" name="password_confirmation" type="password" minlength="10" autocomplete="new-password" required>
                                <button class="student-profile-toggle" type="button" data-password-toggle="admin-confirm-password" aria-label="Show password" aria-pressed="false">
                                    <svg aria-hidden="true"><use href="#icon-eye"></use></svg>
                                </button>
                            </span>
                        </div>
                    </div>

                    <button class="button student-profile-save" type="submit">Change Password</button>
                </form>
            </section>

            <section class="student-profile-section">
                <header class="student-profile-section-heading">
                    <span>ACCOUNT INFORMATION</span>
                    <h3>Account details</h3>
                </header>
                <div class="student-profile-details admin-profile-details">
                    <div><span>Account created</span><strong>{{ $admin->created_at?->format('M j, Y') ?? 'Not recorded' }}</strong></div>
                    <div><span>Role</span><strong>Administrator / Sports Coordinator</strong></div>
                    <div><span>Email verification</span><strong>{{ $admin->email_verified_at ? 'Verified' : 'Not verified' }}</strong></div>
                </div>
            </section>
        </div>
    </div>
</div>
