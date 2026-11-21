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

/**
 * One-time repair tool: Make Dolibarr navigation menus for module "dolizsynch" global (entity=0).
 *
 * Why: In MultiCompany, menus are filtered by entity (entity IN (0, $conf->entity)).
 * If menus were created per-entity, some entities may not show them or they may drift.
 *
 * URL: /custom/kreaflow/tools/migrate_dolizsynch_menus_global.php
 */

require '../../../main.inc.php';

$langs->loadLangs(array('admin', 'dolizsynch@kreaflow'));

if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

$title = 'KreaFlow - Repair Menus (entity=0)';

llxHeader('', $title);

print '<h2>' . dol_escape_htmltag($title) . '</h2>';
print '<p class="opacitymedium">Este utilitário converte os menus do módulo <b>dolizsynch</b> para <b>entity=0</b> (partilhados) e remove duplicados.</p>';

$table = MAIN_DB_PREFIX . 'menu';
$module = 'dolizsynch';

if ($action === 'run' && $confirm === 'yes') {
	// Group rows by the same signature used by Menubase::create anti-duplicate logic.
	$sql = "SELECT rowid, menu_handler, fk_menu, position, url, entity, tms";
	$sql .= " FROM " . $table;
	$sql .= " WHERE module = '" . $db->escape($module) . "'";
	$sql .= " AND menu_handler = 'all'";
	$sql .= " ORDER BY rowid ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		setEventMessages($db->lasterror(), null, 'errors');
	} else {
		$groups = array();
		while ($obj = $db->fetch_object($resql)) {
			$sig = $obj->menu_handler . '|' . $obj->fk_menu . '|' . $obj->position . '|' . $obj->url;
			if (!isset($groups[$sig])) {
				$groups[$sig] = array();
			}
			$groups[$sig][] = $obj;
		}

		$updated = 0;
		$deleted = 0;
		$kept = 0;

		$db->begin();
		foreach ($groups as $rows) {
			if (empty($rows)) {
				continue;
			}

			// Keep the most recent row (by tms), fallback to highest rowid.
			$keep = $rows[0];
			foreach ($rows as $row) {
				$keepTms = !empty($keep->tms) ? strtotime($keep->tms) : 0;
				$rowTms = !empty($row->tms) ? strtotime($row->tms) : 0;
				if ($rowTms > $keepTms || ($rowTms === $keepTms && (int) $row->rowid > (int) $keep->rowid)) {
					$keep = $row;
				}
			}

			$kept++;

			if ((int) $keep->entity !== 0) {
				$sqlu = "UPDATE " . $table . " SET entity = 0 WHERE rowid = " . ((int) $keep->rowid);
				if (!$db->query($sqlu)) {
					$db->rollback();
					setEventMessages('Erro ao atualizar menus: ' . $db->lasterror(), null, 'errors');
					llxFooter();
					exit;
				}
				$updated++;
			}

			foreach ($rows as $row) {
				if ((int) $row->rowid === (int) $keep->rowid) {
					continue;
				}
				$sqld = "DELETE FROM " . $table . " WHERE rowid = " . ((int) $row->rowid);
				if (!$db->query($sqld)) {
					$db->rollback();
					setEventMessages('Erro ao remover duplicados: ' . $db->lasterror(), null, 'errors');
					llxFooter();
					exit;
				}
				$deleted++;
			}
		}
		$db->commit();

		setEventMessages('OK: menus migrados. Kept=' . $kept . ', Updated=' . $updated . ', Deleted=' . $deleted, null, 'mesgs');
	}
}

print '<div class="tabsAction">';
print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=run&confirm=yes">Executar migração</a>';
print '</div>';

print '<hr>';
print '<p class="opacitymedium">Depois de executar, faça refresh na interface (e, se necessário, limpe a cache do browser).</p>';

llxFooter();

