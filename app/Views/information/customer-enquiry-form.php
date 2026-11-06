<section class="info-enquiry-panel" id="<?= $esc($formId) ?>" aria-labelledby="enquiry-panel-heading"<?= $formExpanded ? '' : ' hidden' ?>>
    <p class="info-eyebrow">Contact & enquiries</p>
    <h2 id="enquiry-panel-heading">Customer support</h2>
    <p>Tell us how we can help with your plants, order, delivery or return.</p>
    <?php if (($enquiryFlash['status'] ?? '') === 'success'): ?>
        <p class="info-enquiry-feedback" role="status">Your enquiry has been received. Our customer support team can now review your message.</p>
    <?php endif ?>
    <?php if (($enquiryFlash['status'] ?? '') === 'draft'): ?>
        <p class="info-enquiry-feedback" role="status">Your message has been restored. Check the contact details and send it when ready.</p>
    <?php endif ?>
    <form class="info-enquiry-form" action="/contact/enquiry" method="post" aria-labelledby="enquiry-panel-heading">
        <input type="hidden" name="csrf" value="<?= $esc(\Verdant\Support\Csrf::token()) ?>">
        <input type="hidden" name="submission_key" value="<?= $esc(\Verdant\Http\CustomerEnquiryController::submissionKey()) ?>">
        <input type="hidden" name="topic" value="customer">
        <?php if ($formErrors !== []): ?>
            <div class="info-enquiry-errors info-enquiry-wide" role="alert">
                <p>Please check the following:</p>
                <ul><?php foreach ($formErrors as $error): ?><li><?= $esc($error) ?></li><?php endforeach ?></ul>
            </div>
        <?php endif ?>
        <?php if ($actor !== null): ?>
            <p class="info-enquiry-wide">Sending as <strong><?= $esc($actor['name'] ?? '') ?></strong> · <?= $esc($actor['email'] ?? '') ?>. We’ll use the details on your account.</p>
        <?php endif ?>
        <?php foreach (['name' => ['Your name', 'text', 120], 'email' => ['Email address', 'email', 254], 'order_reference' => ['Order reference (optional)', 'text', 80]] as $field => [$fieldLabel, $type, $limit]): ?>
            <?php if ($actor !== null && in_array($field, ['name', 'email'], true)) continue ?>
            <?php
            $defaultValue = match ($field) {
                'name' => $actor['name'] ?? '',
                'email' => $actor['email'] ?? '',
                default => '',
            };
            $value = $formValues[$field] ?? $defaultValue;
            ?>
            <div class="info-enquiry-field">
                <label for="enquiry-<?= $esc($field) ?>"><?= $esc($fieldLabel) ?></label>
                <input id="enquiry-<?= $esc($field) ?>" name="<?= $esc($field) ?>"
                    type="<?= $esc($type) ?>" maxlength="<?= $limit ?>" value="<?= $esc($value) ?>"
                    <?= $field !== 'order_reference' ? 'required' : '' ?>
                    <?= in_array($field, ['name', 'email'], true) ? 'autocomplete="' . $field . '"' : '' ?>
                    <?= isset($formErrors[$field]) ? 'aria-invalid="true"' : '' ?>>
            </div>
        <?php endforeach ?>
        <div class="info-enquiry-field">
            <label for="enquiry-subject">How can we help?</label>
            <input id="enquiry-subject" name="subject" type="text" maxlength="160" value="<?= $esc($formValues['subject'] ?? '') ?>" placeholder="Plant care, an order, delivery or a return" required<?= isset($formErrors['subject']) ? ' aria-invalid="true"' : '' ?>>
        </div>
        <div class="info-enquiry-field info-enquiry-wide">
            <label for="enquiry-message">Your message</label>
            <textarea id="enquiry-message" name="message" rows="6" maxlength="5000" required<?= isset($formErrors['message']) ? ' aria-invalid="true"' : '' ?>><?= $esc($formValues['message'] ?? '') ?></textarea>
        </div>
        <p class="info-enquiry-wide">We use these details to handle your enquiry. <a href="/privacy">Read our privacy notice</a>. Please do not include passwords or payment card details.</p>
        <div class="info-enquiry-wide">
            <button class="info-button info-button-solid" type="submit">Send enquiry <span aria-hidden="true">→</span></button>
            <?php if ($actor === null): ?>
                <button class="info-button info-button-outline" type="submit" formaction="/contact/sign-in" formnovalidate>Save details and sign in <span aria-hidden="true">→</span></button>
            <?php endif ?>
        </div>
    </form>
</section>
