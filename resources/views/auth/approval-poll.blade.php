<div x-data="{ message: 'Waiting for staff approval. This page will sign you in automatically.', stopped: false,
    async checkApproval() {
        if (this.stopped) return;
        try {
            const response = await fetch(@js(route('student.sign-in.check')), {
                method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) }
            });
            if (!response.ok) return;
            const result = await response.json();
            if (result.state === 'approved') { this.stopped = true; window.location.assign(result.url); }
            if (result.state === 'expired') { this.stopped = true; this.message = 'This sign-in request expired. Enter your student ID again to request approval.'; }
        } catch (error) { this.message = 'Connection interrupted. Retrying staff approval…'; }
    }
}" x-init="checkApproval()" x-on:approval-check.window="checkApproval()" class="text-sm" role="status">
    @if(session('student_sign_in_code'))
        <p class="mb-2">Your browser verification code: <strong class="font-mono">{{ session('student_sign_in_code') }}</strong>. Show this code to your instructor.</p>
    @endif
    <p x-text="message">Waiting for staff approval. This page will sign you in automatically.</p>
    <span x-init="const timer = setInterval(() => { if (!$el.isConnected) { clearInterval(timer); return; } $dispatch('approval-check'); }, 3000)"></span>
</div>
