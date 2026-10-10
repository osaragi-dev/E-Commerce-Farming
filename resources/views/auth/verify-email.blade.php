<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-heading">
            <p class="eyebrow">One more step</p>
            <h1>Verify Your Email</h1>
            <p class="auth-description">Before continuing, please check your email for a verification link.</p>
        </div>

        @if (session('status'))
            <p class="form-success" role="status">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-primary w-full">Resend Verification Email</button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-ghost w-full">Log Out</button>
        </form>
    </div>
</div>
