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

// Legacy log view placeholder - disabled for DoliZSynch leave storage.

require '../../../main.inc.php';

global $langs, $conf, $user;
$langs->loadLangs(array('main', 'holiday'));

if (empty($conf->dolizsynch->enabled)) accessforbidden();
if (empty($user->rights->dolizsynch->shift->read)) accessforbidden();

llxHeader('', $langs->trans("Holiday"));

print load_fiche_titre($langs->trans("NotAvailable"), '', 'holiday');
print '<div class="warning">Logs for core holiday module are not available. Please rely on schedule activity or database audit.</div>';
print '<div class="center"><a class="butAction" href="' . dol_buildpath('/kreaflow/leaves/list.php', 1) . '">' . $langs->trans("BackToList") . '</a></div>';

llxFooter();
$db->close();
