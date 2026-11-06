<?php if ($pageKey === 'contact'): ?>
    <?php
    $contactRows = $byRole(['contact-card', 'card']);
    $supportAvailable = array_filter($contactRows, static fn (array $row): bool => $sectionId($row) === 'customer') !== [];
    $enquiryFlash = $supportAvailable ? \Verdant\Identity\SessionManager::takeFlash('customer_enquiry') : null;
    $formValues = $enquiryFlash['values'] ?? [];
    $formErrors = $enquiryFlash['errors'] ?? [];
    $formExpanded = in_array($enquiryFlash['status'] ?? '', ['error', 'success', 'draft'], true);
    $formId = 'contact-form-customer';
    ?>
    <div class="info-contact-grid">
        <?php foreach ($contactRows as $row): ?>
            <?php
            $contactId = $sectionId($row);
            $supported = $contactId === 'customer';
            $contactParts = explode("\n\n", (string) ($row['body'] ?? ''));
            $contactKicker = array_shift($contactParts) ?: '';
            $contactDescription = implode("\n\n", $contactParts);
            ?>
            <section class="info-contact-card" id="<?= $esc($contactId) ?>">
                <div class="info-contact-heading">
                    <span class="info-help-icon" aria-hidden="true"><?= $esc($presentation['icons'][$row['section_key']] ?? '✦') ?></span>
                    <div>
                        <h2><?= $esc($row['heading'] ?? '') ?></h2>
                        <p class="info-contact-kicker"><?= $esc($contactKicker) ?></p>
                    </div>
                </div>
                <div class="info-contact-description"><?php $paragraphs($contactDescription) ?></div>
                <?php if ($supported): ?>
                    <button class="info-button info-button-solid info-contact-action" type="button" data-contact-toggle aria-expanded="<?= $formExpanded ? 'true' : 'false' ?>" aria-controls="<?= $esc($formId) ?>">
                        Contact customer support <span aria-hidden="true">→</span>
                    </button>
                <?php else: ?>
                    <button class="info-button info-button-solid info-contact-action" type="button" disabled>Coming soon</button>
                <?php endif ?>
            </section>
        <?php endforeach ?>
    </div>
    <?php if ($supportAvailable): require __DIR__ . '/customer-enquiry-form.php'; endif ?>
<?php endif ?>
