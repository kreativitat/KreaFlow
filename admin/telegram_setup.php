<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = include '../../main.inc.php';
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = include '../../../main.inc.php';
}
if (!$res) {
	die("Main include failed");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once '../lib/dolizsynch.lib.php';

// Translations
$langs->loadLangs(array("admin", "dolizsynch@kreaflow"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

/*
 * Actions
 */

if ($action == 'setTelegramConfig') {
	$chatId = GETPOST('telegram_chat_id', 'alpha');
	$apiKey = GETPOST('telegram_api_key', 'alpha');
	$notifyHour = GETPOST('notify_hour', 'int');

	dolibarr_set_const($db, 'ZS_TELEGRAM_CHAT_ID', $chatId, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'ZS_TELEGRAM_API_KEY', $apiKey, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'ZS_PUNCH_HOUR_NOTIFY', $notifyHour, 'int', 0, '', $conf->entity);

	setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');

	header("Location: " . $_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'testTelegram') {
	try {
		require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zspunchclock_synch.class.php';

		$punchClockSynch = new ZSPunchClock_Synch($db);
		$testMessage = "🤖 Teste de configuração Telegram\n\n";
		$testMessage .= "✅ Bot configurado com sucesso!\n";
		$testMessage .= "📅 Data: " . dol_print_date(dol_now(), 'dayhour') . "\n\n";
		$testMessage .= "Esta é uma mensagem de teste do módulo KreaFlow.";

		$result = $punchClockSynch->sendMessageToTelegram($testMessage);

		if ($result) {
			setEventMessages($langs->trans("TelegramTestSuccess"), null, 'mesgs');
		} else {
			setEventMessages($langs->trans("TelegramTestFailed"), null, 'errors');
		}
	} catch (Exception $e) {
		dol_syslog("Error testing Telegram: " . $e->getMessage(), LOG_ERR);
		setEventMessages("Error: " . $e->getMessage(), null, 'errors');
	}

	header("Location: " . $_SERVER["PHP_SELF"]);
	exit;
}

/*
 * View
 */

$page_name = "TelegramSetup";
llxHeader('', $langs->trans($page_name));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = dolizsynchAdminPrepareHead();
print dol_get_fiche_head($head, 'telegram_setup', $langs->trans("Module156558Name"), -1, 'dolizsynch@kreaflow');

// Current configuration
$chatId = $conf->global->ZS_TELEGRAM_CHAT_ID ?? '';
$apiKey = $conf->global->ZS_TELEGRAM_API_KEY ?? '';
$notifyHour = $conf->global->ZS_PUNCH_HOUR_NOTIFY ?? 18;

$isConfigured = !empty($chatId) && !empty($apiKey);

// Instructions section
print '<div class="info">';
print '<h3>' . $langs->trans("TelegramSetupInstructions") . '</h3>';
print '<ol>';
print '<li><strong>' . $langs->trans("TelegramStep1") . '</strong><br>';
print $langs->trans("TelegramStep1Details") . '<br>';
print '<code>@BotFather</code> → <code>/start</code> → <code>/newbot</code>';
print '</li><br>';

print '<li><strong>' . $langs->trans("TelegramStep2") . '</strong><br>';
print $langs->trans("TelegramStep2Details") . '<br>';
print '<div style="background: #f5f5f5; padding: 10px; margin: 5px 0; border-radius: 5px; font-family: monospace; font-size: 12px;">';
print 'https://api.telegram.org/bot<span style="color: #d63384;">YOUR_BOT_TOKEN</span>/getUpdates';
print '</div>';
print $langs->trans("TelegramStep2Alternative") . ' <code>@userinfobot</code>';
print '</li><br>';

print '<li><strong>' . $langs->trans("TelegramStep3") . '</strong><br>';
print $langs->trans("TelegramStep3Details");
print '</li>';
print '</ol>';

// Group chat instructions
print '<div class="info" style="background: #e7f3ff; border-left: 4px solid #2196F3; margin-top: 15px;">';
print '<h4 style="margin-top: 10px;">📢 ' . $langs->trans("TelegramGroupChatTitle") . '</h4>';
print '<p>' . $langs->trans("TelegramGroupChatIntro") . '</p>';
print '<ol style="margin-left: 20px;">';
print '<li>' . $langs->trans("TelegramGroupStep1") . '</li>';
print '<li>' . $langs->trans("TelegramGroupStep2") . '</li>';
print '<li>' . $langs->trans("TelegramGroupStep3") . '<br>';
print '<div style="background: #f5f5f5; padding: 10px; margin: 5px 0; border-radius: 5px; font-family: monospace; font-size: 12px;">';
print 'https://api.telegram.org/bot<span style="color: #d63384;">YOUR_BOT_TOKEN</span>/getUpdates';
print '</div>';
print $langs->trans("TelegramGroupStep3Details");
print '</li>';
print '<li>' . $langs->trans("TelegramGroupStep4") . '</li>';
print '</ol>';
print '<p class="opacitymedium"><strong>' . $langs->trans("Note") . ':</strong> ' . $langs->trans("TelegramGroupNote") . '</p>';
print '</div>';

print '</div>';

print '<br>';

// Configuration form
print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="setTelegramConfig">';

print '<table class="border centpercent">';

// Chat ID
print '<tr class="oddeven">';
print '<td class="fieldrequired" width="30%">' . $langs->trans("ZS_TELEGRAM_CHAT_ID") . '</td>';
print '<td>';
print '<input type="text" name="telegram_chat_id" class="minwidth300" value="' . dol_escape_htmltag($chatId) . '" placeholder="' . $langs->trans("TelegramChatIdPlaceholder") . '">';
print '</td>';
print '</tr>';

// API Key
print '<tr class="oddeven">';
print '<td class="fieldrequired">' . $langs->trans("ZS_TELEGRAM_API_KEY") . '</td>';
print '<td>';
print '<input type="text" name="telegram_api_key" class="minwidth500" value="' . dol_escape_htmltag($apiKey) . '" placeholder="' . $langs->trans("TelegramApiKeyPlaceholder") . '">';
print '</td>';
print '</tr>';

// Notify hour
print '<tr class="oddeven">';
print '<td>' . $langs->trans("ZS_PUNCH_HOUR_NOTIFY") . '</td>';
print '<td>';
print '<input type="number" name="notify_hour" min="0" max="23" value="' . $notifyHour . '"> ' . $langs->trans("hours");
print ' <span class="opacitymedium">(' . $langs->trans("TelegramNotifyHourHelp") . ')</span>';
print '</td>';
print '</tr>';

print '</table>';

print '<div class="center" style="margin-top: 20px;">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';

print '</form>';

// Test button
if ($isConfigured) {
	print '<br><hr><br>';
	print '<div class="center">';
	print '<h3>' . $langs->trans("TelegramTestSection") . '</h3>';
	print '<p class="opacitymedium">' . $langs->trans("TelegramTestDescription") . '</p>';
	print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="testTelegram">';
	print '<input type="submit" class="button" value="' . $langs->trans("SendTestMessage") . '">';
	print '</form>';
	print '</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
