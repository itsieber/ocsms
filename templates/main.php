<?php
use \OCA\Ocsms\Lib\CountryCodes;

\OCP\Util::addStyle('ocsms', 'style');
\OCP\Util::addScript('ocsms', 'lodash.min');
\OCP\Util::addScript('ocsms', 'vue.min');
\OCP\Util::addScript('ocsms', 'twemoji/twemoji.min');
\OCP\Util::addScript('ocsms', 'anchorme.min');
// Production
\OCP\Util::addScript('ocsms', 'app.min');
// Develop
//\OCP\Util::addScript('ocsms', 'devel/app');
//\OCP\Util::addScript('ocsms', 'devel/settings');
//\OCP\Util::addScript('ocsms', 'devel/contactlist');
//\OCP\Util::addScript('ocsms', 'devel/conversation');
//\OCP\Util::addScript('ocsms', 'devel/helpers');
//\OCP\Util::addScript('ocsms', 'devel/notifications');
?>

<script type="text/x-template" id="modal-template" xmlns:v-on="http://www.w3.org/1999/xhtml"
        xmlns:v-bind="http://www.w3.org/1999/xhtml" xmlns:v-on="http://www.w3.org/1999/xhtml"
        xmlns:v-on="http://www.w3.org/1999/xhtml" xmlns:v-on="http://www.w3.org/1999/xhtml">
    <transition name="modal" v-if="show">
        <div class="modal-mask">
            <div class="modal-wrapper">
                <div class="modal-container">
                    <div class="modal-body">
                        {{ bodyMessage }}
                    </div>

                    <div class="modal-footer">
                        <slot name="footer">
                            <button class="modal-default-button" @click="show = false">
                                <slot name="button-cancel"><?php p($l->t('Cancel'));?></slot>
                            </button>
                            <button class="modal-default-button modal-crit-button" @click="doYes">
                                <slot name="button-ok"><?php p($l->t('Confirm'));?></slot>
                            </button>
                        </slot>
                    </div>
                </div>
            </div>
        </div>
    </transition>
</script>

<div id="app">
    <div id="app-navigation">
        <div id="app-mailbox-peers">
            <div id="app-contact-search">
                <input type="text" v-model="searchQuery" placeholder="<?php p($l->t('Search contact...'));?>" class="contact-search-input" />
            </div>
            <div id="app-contacts-loader" class="icon-loading" v-if="isContactsLoading">
            </div>
            <div class="contact-list-no-contact" v-if="orderedContacts.length === 0 && !isContactsLoading">
                <?php p($l->t('No contact found.'));?>
            </div>
            <ul class="contact-list" v-if="orderedContacts.length > 0 && !isContactsLoading">
                <li v-for="contact in orderedContacts" :key="contact.nav" :peer-label="contact.label" v-on:click="loadConversation(contact);">
                    <img class="ocsms-plavatar" :src="contact.avatar" v-if="contact.avatar !== undefined" />
                    <div class="ocsms-plavatar" v-if="contact.avatar === undefined" :style="{'backgroundColor': getContactColor(contact.uid) }">{{ contact.label | firstCharacter }}</div>
                    <a class="ocsms-plname" :style="{'fontWeight': contact.unread > 0 ? 'bold' : 'normal'}" :mailbox-label="contact.label" :mailbox-navigation="contact.nav">{{ contact.label }}{{ contact.unread > 0 ? ' (' + contact.unread + ') ' : '' }}</a>
                </li>
            </ul>
        </div>
        <div id="app-settings">
            <div id="app-settings-header">
                <button name="app settings" class="settings-button" data-apps-slide-toggle="#app-settings-content">
					<?php p($l->t('Settings'));?>
                </button>
            </div>
            <div id="app-settings-content">
                <div><label for="setting_msg_per_page"><?php p($l->t('Max messages to load per conversation'));?></label>
                    <input type="number" min="10" max="10000" name="setting_msg_per_page" v-model="messageLimit" v-on:change="sendMessageLimit()" to-int />
                    <span class="label-invalid-input" v-if="messageLimit == null || messageLimit === undefined"><?php p($l->t('Invalid message limit'));?></span>
                </div>

                <div><label for="intl_phone"><?php p($l->t('Default country code'));?></label>
                    <select name="intl_phone" id="sel_intl_phone" v-model="country">
						<?php foreach (CountryCodes::$codes as $code => $cval) { ?>
                            <option><?php p($l->t($code)); ?></option>
						<?php } ?>
                    </select>
                    <button class="new-button primary icon-checkmark-white" v-on:click="sendCountry();"></button>
                </div>

                <div>
                    <label for="setting_contact_order"><?php p($l->t('Contact ordering'));?></label>
                    <select name="setting_contact_order" v-model="contactOrderBy" v-on:change="sendContactOrder()">
                        <option value="lastmsg"><?php p($l->t('Last message'));?></option>
                        <option value="label"><?php p($l->t('Label'));?></option>
                    </select>
                    <label for="setting_contact_order_reverse"><?php p($l->t('Reverse ?'));?></label>
                    <input type="checkbox" v-model="reverseContactOrder" v-on:change="sendContactOrder()" />
                </div>

                <div>
                    <label for="setting_notif"><?php p($l->t('Notification settings'));?></label>
                    <select name="setting_notif" v-model="enableNotifications" v-on:change="sendNotificationFlag()">
                        <option value="1"><?php p($l->t('Enable'));?></option>
                        <option value="0"><?php p($l->t('Disable'));?></option>
                    </select>
                </div>
                <div>
                    <button class="crit-button primary"
                            v-confirm="['<?php p($l->t('Are you sure you want to wipe all your messages ?'));?>', wipeAllMessages]">
						<?php p($l->t('Reset all messages'));?>
                    </button>
                </div>
            </div> <!-- app-settings-content -->
        </div>
	</div>

	<div id="app-content">
		<div id="app-content-loader" class="icon-loading" v-if="isConvLoading"></div>
		<div id="app-content-header" v-if="!isConvLoading && messages.length > 0" v-bind:style="{backgroundColor: getContactColor(selectedContact.uid) }">
			<div id="ocsms-contact-avatar">
				<img class="ocsms-plavatar-big" v-if="selectedContact.avatar !== undefined" :src="selectedContact.avatar" />
				<div class="ocsms-plavatar-big" v-if="selectedContact.avatar === undefined">{{ selectedContact.label | firstCharacter }}</div>
			</div>
			<div id="ocsms-contact-details">
				<div id="ocsms-phone-label">{{ selectedContact.label }} </div>
				<div id="ocsms-phone-opt-number">{{ selectedContact.opt_numbers }}</div>
				<div id="ocsms-phone-msg-nb"><?php p($l->t('%s messages shown of %s messages stored in database.', array( '{{ messages.length }}', '{{ totalMessageCount }}')));?></div>
			</div>
			<div id="ocsms-contact-actions">
				<div id="ocsms-conversation-removal" class="icon-delete icon-delete-white svn delete action" v-on:click="removeConversation();"></div>
			</div>

		</div>
		<div id="app-content-wrapper" v-if="!isConvLoading">
			<div v-if="messages.length === 0" id="ocsms-empty-conversation"><?php p($l->t('Please select a conversation from the list to load it.'));?></div>
			<div v-if="messages.length > 0" class="ocsms-messages-container">
				<div v-for="message in orderedMessages">
					<div v-bind:class="['msg-'+  message.type]">
						<div v-html="message.content"></div>
						<div style="display: block;" id="ocsms-message-removal" class="icon-delete svn delete action" v-on:click="removeConversationMessage(message.id);"></div>
						<div class="msg-date">{{ message.date | date('medium') }}</div>
					</div>
					<div class="msg-spacer"></div>
				</div>
			</div>
			<div v-if="selectedContact.nav" class="msg-sent msg-compose">
				<textarea id="ocsms-send-message" v-model="newMessage" :placeholder="'<?php p($l->t('Type a message...'));?>'" @keydown.enter.exact.prevent="sendMessage()"></textarea>
				<div class="msg-compose-actions">
					<button id="ocsms-send-button" class="primary" v-on:click="sendMessage()" :disabled="isSending || newMessage.trim() === ''">
						<span v-if="!isSending"><?php p($l->t('Send'));?></span>
						<span v-if="isSending" class="icon-loading-small"></span>
					</button>
				</div>
			</div>
			<div class="msg-spacer"></div>
		</div>
	</div>
</div>
