<section class="support-guest-card" data-support-access>
    <p class="support-kicker">Verdant · Customer support</p><h1>Open your conversation</h1>
    <p>Continue to the private support conversation linked to this email.</p>
    <form method="post" action="/support/access/confirm" data-access-form>
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="access_token" value="">
        <p class="support-access-error" data-access-error hidden>This link could not be read. Open the full link from your email again.</p>
        <button type="submit">Continue to conversation</button>
    </form>
    <p class="support-small">This private link expires and can be used once.</p>
    <details class="support-resend"><summary>Need a new link?</summary><form method="post" action="/support/access/resend"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><label>Enquiry reference<input type="number" name="enquiry_id" min="1" max="999999999999999999" required></label><label>Email address<input type="email" name="email" maxlength="254" autocomplete="email" required></label><button type="submit">Request a new link</button></form></details>
</section>
<script src="/assets/js/support/access.js" defer></script>
