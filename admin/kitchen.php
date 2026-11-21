<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] === $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)).'/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)).'/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}


require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/kreaflow.lib.php';
require_once __DIR__.'/../class/KreaFlowKitchenSettings.class.php';
$langs->loadLangs(array('admin', 'kreaflow@kreaflow'));
if (!$user->admin || !empty($user->socid)) { accessforbidden(); }
$settings = KreaFlowKitchenSettings::load($db, (int) $conf->entity);
$options = array('1' => 'MONTAGEM', '3' => 'CHAPA', '4' => 'BALCAO', '5' => 'PEDIDO COMPLETO', '6' => 'PRODUTOS DO DIA', '18001' => 'COZINHA - BF');
$action = GETPOST('action', 'aZ09');
if ($action === 'save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || GETPOSTINT('entity') !== (int) $conf->entity
        || !hash_equals((string) currentToken(), (string) GETPOST('token', 'alpha'))) { accessforbidden(); }
    $selected = GETPOSTISARRAY('stations') ? GETPOST('stations', 'array') : array();
    $ids = array();
    foreach ($selected as $id) {
        if (!is_scalar($id) || !isset($options[(string) $id])) { accessforbidden(); }
        $ids[] = (string) $id;
    }
    $ids = array_values(array_unique($ids));
    $db->begin();
    if (dolibarr_set_const($db, 'KREAFLOW_KITCHEN_STATIONS', $ids ? implode(',', $ids) : '0', 'chaine', 0, '', (int) $conf->entity) > 0) {
        $db->commit();
        setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
    $db->rollback();
    setEventMessages($langs->trans('Error'), null, 'errors');
}
llxHeader('', $langs->trans('KitchenSettings'));
print load_fiche_titre($langs->trans('KitchenSettings'), '', 'title_setup');
print dol_get_fiche_head(kreaflowAdminPrepareHead(), 'kitchen', $langs->trans('KreaFlow'), -1, 'phoning_mobile');
print '<p>'.$langs->trans('KitchenStationsHelp').'</p>';
print '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save"><input type="hidden" name="entity" value="'.((int) $conf->entity).'">';
print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('KitchenStations').'</th></tr>';
foreach ($options as $id => $name) {
    print '<tr class="oddeven"><td><label><input type="checkbox" name="stations[]" value="'.$id.'"'.(in_array((string) $id, $settings['ids'], true) ? ' checked' : '').'> '.dol_escape_htmltag($name).' ('.$id.')</label></td></tr>';
}
print '</table></div><div class="center"><input class="button button-save" type="submit" value="'.$langs->trans('Save').'"></div></form>';
print dol_get_fiche_end();
llxFooter();
$db->close();
