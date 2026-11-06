<header class="admin-page-heading"><div><p><a href="/admin/enquiries">Customer support</a> · Conversation #<?= $e($thread['enquiry_id']) ?></p>
    <h1><?= $e($thread['subject']) ?></h1><p><?= $e($thread['name']) ?> · <?= $e($thread['email']) ?> · <?= $e($thread['created_at']) ?></p></div></header>
<section class="support-thread">
    <div class="support-thread__toolbar">
        <span class="support-state support-state--<?= $e($thread['enquiry_state']) ?>"><?= $e(ucfirst($thread['enquiry_state'])) ?></span>
        <?php $nextState = $thread['enquiry_state'] === 'open' ? 'resolved' : 'open'; ?>
        <form method="post" action="/admin/enquiry/state"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="enquiry_id" value="<?= $e($thread['enquiry_id']) ?>"><input type="hidden" name="updated_at" value="<?= $e($statusVersion) ?>"><input type="hidden" name="current_state" value="<?= $e($thread['enquiry_state']) ?>"><input type="hidden" name="latest_message_id" value="<?= $e($thread['latest_message_id']) ?>"><input type="hidden" name="state" value="<?= $e($nextState) ?>"><button type="submit" class="btn-outline"><?= $nextState === 'resolved' ? 'Mark resolved' : 'Reopen conversation' ?></button></form>
    </div>
    <ol class="support-messages" aria-label="Conversation history">
        <?php foreach ($thread['messages'] as $message): ?><li class="support-message support-message--<?= $e($message['author_kind']) ?>">
            <header><strong><?= $message['author_kind'] === 'admin' ? 'Verdant support' : ($message['author_kind'] === 'guest' ? $e($thread['name']) : $e($thread['name'])) ?></strong><time><?= $e($message['created_at']) ?></time></header>
            <p><?= nl2br($e($message['body']), false) ?></p>
        </li><?php endforeach ?>
    </ol>
    <?php if ($thread['messages_pages'] > 1): ?><nav class="support-pagination" aria-label="Message history pages"><span>Page <?= $e($thread['messages_page']) ?> of <?= $e($thread['messages_pages']) ?></span><?php if ($thread['messages_page'] < $thread['messages_pages']): ?><a href="/admin/enquiry?id=<?= $e($thread['enquiry_id']) ?>&page=<?= $e($thread['messages_page'] + 1) ?>">Older messages</a><?php endif ?><?php if ($thread['messages_page'] > 1): ?><a href="/admin/enquiry?id=<?= $e($thread['enquiry_id']) ?>&page=<?= $e($thread['messages_page'] - 1) ?>">Newer messages</a><?php endif ?></nav><?php endif ?>
    <form method="post" action="/admin/enquiry/reply" class="support-reply-form">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="enquiry_id" value="<?= $e($thread['enquiry_id']) ?>"><input type="hidden" name="submission_key" value="<?= $e($replyKey) ?>">
        <?php if (!empty($replyFeedback['error'])): ?><p role="alert"><?= $e($replyFeedback['error']) ?></p><?php endif ?>
        <label for="support-reply">Reply to <?= $e($thread['name']) ?></label><textarea id="support-reply" name="body" rows="6" maxlength="5000" required><?= $e($replyFeedback['body'] ?? '') ?></textarea>
        <p>Replies are saved to this conversation. Guest access links are private and expire.</p><button type="submit" class="btn-primary">Save reply</button>
    </form>
</section>
