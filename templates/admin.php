<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="scim-server" class="section"
	data-settings-url="<?php p($_['settingsUrl']); ?>" data-tokens-url="<?php p($_['tokensUrl']); ?>">
	<h2><?php p($l->t('SCIM Server')); ?></h2>
	<p class="settings-hint"><?php p($l->t('Lets an identity provider such as authentik provision, update, disable and delete accounts and groups.')); ?></p>

	<h3><?php p($l->t('Connection')); ?></h3>
	<p><?php p($l->t('SCIM base URL (use it, with a token below, in your identity provider):')); ?> <code><?php p($_['baseUrl']); ?></code></p>

	<h3><?php p($l->t('Tokens')); ?></h3>
	<ul id="scim-tokens">
		<?php foreach ($_['tokens'] as $t) { ?>
			<li>
				<strong><?php p($t['label']); ?></strong>
				<?php p($l->t('created %s', [date('Y-m-d H:i', (int)$t['created_at'])])); ?>,
				<?php p($t['last_used'] ? $l->t('last used %s', [date('Y-m-d H:i', (int)$t['last_used'])]) : $l->t('never used')); ?>
				<button type="button" class="scim-revoke" data-id="<?php p($t['id']); ?>"><?php p($l->t('Revoke')); ?></button>
			</li>
		<?php } ?>
	</ul>
	<input type="text" id="scim-label" placeholder="<?php p($l->t('Label, e.g. authentik')); ?>">
	<button type="button" id="scim-create"><?php p($l->t('Create token')); ?></button>
	<p id="scim-new-token" hidden><?php p($l->t('Copy the token now, it is not shown again:')); ?> <code></code></p>

	<h3><?php p($l->t('When the identity provider deletes an account')); ?></h3>
	<p>
		<select id="scim-mode">
			<option value="disable" <?php if ($_['deleteMode'] === 'disable') { p('selected'); } ?>><?php p($l->t('Only disable the account (keep all data)')); ?></option>
			<option value="disable_purge" <?php if ($_['deleteMode'] === 'disable_purge') { p('selected'); } ?>><?php p($l->t('Disable now, delete the account and its data later')); ?></option>
			<option value="delete" <?php if ($_['deleteMode'] === 'delete') { p('selected'); } ?>><?php p($l->t('Delete the account and its data immediately')); ?></option>
		</select>
	</p>
	<p>
		<label><?php p($l->t('Days until deletion')); ?>
			<input type="number" id="scim-days" min="1" max="3650" value="<?php p($_['purgeDays']); ?>">
		</label>
	</p>
	<p>
		<input type="checkbox" id="scim-protect" class="checkbox" <?php if ($_['protectAdmins']) { p('checked'); } ?>>
		<label for="scim-protect"><?php p($l->t('Protect administrators and the admin group from SCIM changes')); ?></label>
	</p>
	<p class="settings-hint"><?php p($l->t('An account that is marked inactive is always only disabled, and enabled again when it becomes active.')); ?></p>
	<button type="button" id="scim-save"><?php p($l->t('Save')); ?></button>
	<span id="scim-status"></span>
</div>
