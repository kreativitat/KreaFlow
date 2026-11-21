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
 * Standalone Product Menu Builder (tab extract) for DoliZSynch.
 * URL: /custom/kreaflow/tools/product_tab_menu_builder.php?id=PRODUCT_ID
 */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

$langs->loadLangs(array('products', 'dolizsynch@kreaflow'));

$id = GETPOST('id', 'int');
if (empty($id)) {
	accessforbidden();
}

$product = new Product($db);
if ($product->fetch($id) <= 0) {
	accessforbidden();
}

// Load stored menu from zsproduct by fk_product or codigo
$menuJson = '[]';
$sql = "SELECT niveismenu FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
$sql .= " WHERE fk_product = " . ((int) $product->id);
$sql .= " ORDER BY rowid DESC " . $db->plimit(1);
$res = $db->query($sql);
if ($res && ($obj = $db->fetch_object($res))) {
	if (!empty($obj->niveismenu)) {
		$menuJson = $obj->niveismenu;
	}
}

// Fallback by codigo/ref if fk_product not found
if ($menuJson === '[]') {
	$code = $db->escape($product->ref);
	$sql = "SELECT niveismenu FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
	$sql .= " WHERE codigo = '" . $code . "'";
	$sql .= " ORDER BY rowid DESC " . $db->plimit(1);
	$res = $db->query($sql);
	if ($res && ($obj = $db->fetch_object($res))) {
		if (!empty($obj->niveismenu)) {
			$menuJson = $obj->niveismenu;
		}
	}
}

// Family options for dropdown (current store if available)
$familyOptions = array();
$sqlFamilies = "SELECT family_id, family_name, subfamily_id, subfamily_name FROM " . MAIN_DB_PREFIX . "dolizsynch_zsfamily";
$sqlFamilies .= " ORDER BY family_name ASC, subfamily_name ASC";
$resFam = $db->query($sqlFamilies);
$zapiOptionsFamilies = array(); // keep for legacy reference
if ($resFam) {
	while ($obj = $db->fetch_object($resFam)) {
		$label = ($obj->subfamily_name == '-' || $obj->subfamily_name == '') ? $obj->family_name : $obj->family_name . ' >> ' . $obj->subfamily_name;
		$familyOptions[] = array('value' => $obj->family_id . '_' . $obj->subfamily_id, 'label' => $label);
	}
}

llxHeader('', 'KreaFlow - Menu Builder (Tab extract)');
?>
<style>
	.mb-builder-empty { padding: 8px; color: #555; border: 1px dashed #ccc; border-radius: 6px; background: #fafafa; }
	.mb-card { border: 1px solid #dfe7f3; border-radius: 8px; padding: 10px; margin-bottom: 10px; background: #f9fbff; }
	.mb-head { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
	.mb-card-title { font-weight: 700; color: #111827; margin-right: 6px; }
	.mb-items { margin-top: 8px; }
	.mb-item { display: grid; grid-template-columns: 140px 1fr 110px 120px 110px 90px 70px; gap: 6px; align-items: center; margin-top: 6px; }
	@media (max-width: 1100px) { .mb-item { grid-template-columns: 120px 1fr 100px 120px 100px 90px 70px; } }
	.mb-item input[type="text"], .mb-item input[type="number"], .mb-item select { width: 100%; box-sizing: border-box; }
	.mb-prod-picker { display: grid; grid-template-columns: 120px 1fr; gap: 6px; align-items: center; }
	.mb-family-picker select { width: 100%; }
	.mb-remove { display: flex; justify-content: flex-end; gap: 4px; }
	.mb-remove .mb-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 26px; border: 1px solid transparent; border-radius: 4px; background: transparent; cursor: pointer; }
	.mb-remove .mb-icon-btn:disabled { opacity: 0.3; cursor: not-allowed; }
	.mb-remove .mb-icon-btn .fas { font-size: 13px; }
</style>

<h2>KreaFlow - Menu Builder (Product #<?php echo (int) $product->id; ?> / <?php echo dol_escape_htmltag($product->ref); ?>)</h2>
<p class="opacitymedium">Edição isolada do builder do separador. Não grava automaticamente.</p>

<input type="hidden" name="DOLIZSYNCH_ZS_NIVEISMENU" id="DOLIZSYNCH_ZS_NIVEISMENU" value="<?php echo dol_escape_htmltag($menuJson); ?>">

<div class="mb-actions" style="margin-top:4px; display:flex; gap:8px; align-items:center;">
	<button type="button" class="button smallpaddingimp" id="mb-add-level">Adicionar nível</button>
	<button type="button" class="button smallpaddingimp" id="mb-clear">Limpar tudo</button>
	<button type="button" class="button smallpaddingimp" id="mb-copy">Copiar JSON</button>
	<span class="opacitymedium">Use o JSON para colar no campo niveismenu.</span>
</div>
<div id="mb-builder" style="margin-top:8px;"></div>

<script>
(function() {
	const builder = document.getElementById("mb-builder");
	const btnAddLevel = document.getElementById("mb-add-level");
	const btnClear = document.getElementById("mb-clear");
	const btnCopy = document.getElementById("mb-copy");
	const hidden = document.getElementById("DOLIZSYNCH_ZS_NIVEISMENU");
	const productMenuCode = <?php echo json_encode($product->ref ? $product->ref : $product->id); ?>;
	const familyOptions = <?php echo json_encode($familyOptions); ?>;
	const productSearchUrl = "<?php echo DOL_URL_ROOT; ?>/product/ajax/products.php?mode=0&outjson=1&term=";
	let state = [];
	let searchTimers = new WeakMap();

	function escapeHtml(str){ if(str===null||str===undefined) return ""; return String(str).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c])); }
	function familyLabelFor(code){ if(!code) return ""; const c=String(code); const found=familyOptions.find(opt=>String(opt.value)===c || String(opt.value).startsWith(c+"_")); return found?found.label:""; }

	function normalizeLevel(level, idx){
		const safe = Object.assign({descricao:"", menu: productMenuCode, nivel: idx+1, obrigatorio:0, ordem:0, niveismenuext:[], _collapsed:0}, level||{});
		safe.nivel = idx+1;
		safe.obrigatorio = safe.nivel===1 ? 1 : (safe.obrigatorio ? 1 : 0);
		safe.ordem = parseInt(safe.ordem,10); if(isNaN(safe.ordem)) safe.ordem = 0;
		if(!Array.isArray(safe.niveismenuext)) safe.niveismenuext = [];
		return safe;
	}
	function normalizeItem(item, level){
		const safe = Object.assign({codigo:"", descricao:"", preco:"", fixo:1, opcaopvp:0, valcomplementos:1, produtodefault:0, menu:level.menu, nivel:level.nivel}, item||{});
		safe.fixo = parseInt(safe.fixo,10); if(isNaN(safe.fixo)||[1,2].indexOf(safe.fixo)===-1) safe.fixo=1;
		safe.opcaopvp = parseInt(safe.opcaopvp,10); if(isNaN(safe.opcaopvp)) safe.opcaopvp=0;
		safe.valcomplementos = safe.valcomplementos ? 1 : 0;
		safe.produtodefault = safe.produtodefault ? 1 : 0;
		return safe;
	}

	function cleanedState(){
		return state.map((lvl, idx)=>{
			const L = normalizeLevel(lvl, idx);
			const items = [];
			L.niveismenuext.forEach(it=>{
				const n = normalizeItem(it, L);
				if(n.codigo==="") return;
				let pvpNum = null;
				if(!isNaN(parseInt(n.opcaopvp,10)) && parseInt(n.opcaopvp,10)>=1 && parseInt(n.opcaopvp,10)<=10) pvpNum=parseInt(n.opcaopvp,10);
				else if(n.opcaopvp && !isNaN(parseInt(n.preco,10)) && parseInt(n.preco,10)>=1 && parseInt(n.preco,10)<=10) pvpNum=parseInt(n.preco,10);
				if(pvpNum!==null){ n.opcaopvp=1; n.preco=pvpNum; } else { n.opcaopvp=0; const euro=parseFloat(n.preco); n.preco=(n.preco===""||isNaN(euro))?"":euro; }
				items.push(n);
			});
			return {descricao:L.descricao, menu:L.menu, nivel:L.nivel, obrigatorio:L.obrigatorio, ordem:L.ordem, niveismenuext: items};
		}).filter(l=>l.descricao!=="" || (l.niveismenuext && l.niveismenuext.length));
	}
	function syncHidden(){ if(hidden) hidden.value = JSON.stringify(cleanedState()); }

	function fetchProductLabel(code, idx, iIdx, row){
		if(!code) return;
		fetch(productSearchUrl + encodeURIComponent(code))
			.then(r=>r.json())
			.then(list=>{
				if(!Array.isArray(list) || !list.length) return;
				const first = list.find(it => it && (it.ref===code || (it.label && it.label.indexOf(code)>=0))) || list[0];
				const label = first && (first.label || first.value) ? (first.label || first.value) : "";
				if(!label) return;
				if(state[idx] && state[idx].niveismenuext[iIdx]) state[idx].niveismenuext[iIdx].descricao = label;
				if(row){ const descInput = row.querySelector('input[data-role="descricao"]'); if(descInput) descInput.value = label; }
				syncHidden();
			})
			.catch(()=>{});
	}

	function render(){
		builder.innerHTML = "";
		if(!state.length){
			const empty = document.createElement("div"); empty.className="mb-builder-empty"; empty.textContent="Sem níveis definidos. Adicione um nível para começar."; builder.appendChild(empty); syncHidden(); return;
		}
		state.forEach((lvl, idx)=>{
			const L = normalizeLevel(lvl, idx); state[idx]=L;
			const card = document.createElement("div"); card.className="mb-card"; card.dataset.levelIndex=String(idx);
			const head = document.createElement("div"); head.className="mb-head";
			head.innerHTML = '<span class="mb-card-title">Nível #' + escapeHtml(L.nivel) + '</span>'
				+ '<input type="text" class="flat" data-role="level-descricao" value="' + escapeHtml(L.descricao) + '" placeholder="Descrição do nível">'
				+ '<label><input type="checkbox" data-role="obrigatorio"' + (L.obrigatorio ? ' checked' : '') + (L.nivel===1 ? ' disabled' : '') + '> Obrigatório</label>'
				+ '<label style="display:flex; align-items:center; gap:4px;">Ordem <input type="number" data-role="ordem" class="flat" value="' + escapeHtml(L.ordem) + '" style="width:70px;"></label>'
				+ '<button type="button" class="button smallpaddingimp" data-role="add-item">+ Item</button>'
				+ '<button type="button" class="button smallpaddingimp" data-role="remove-level" style="margin-left:auto;">Remover nível</button>';
			card.appendChild(head);

			const itemsWrap = document.createElement("div"); itemsWrap.className="mb-items";
			L.niveismenuext.forEach((it,iIdx)=>{
				const I = normalizeItem(it, L); L.niveismenuext[iIdx]=I;
				let pvpNum=""; if(!isNaN(parseInt(I.opcaopvp,10)) && parseInt(I.opcaopvp,10)>=1 && parseInt(I.opcaopvp,10)<=10) pvpNum=parseInt(I.opcaopvp,10);
				else if(I.opcaopvp && !isNaN(parseInt(I.preco,10)) && parseInt(I.preco,10)>=1 && parseInt(I.preco,10)<=10) pvpNum=parseInt(I.preco,10);
				const priceDisabled = pvpNum ? " disabled" : "";
				const priceValue = pvpNum ? "" : (I.preco!==null && I.preco!==undefined ? escapeHtml(I.preco) : "");
				if(I.fixo===2 && (!I.descricao || I.descricao==="")){ const lbl=familyLabelFor(I.codigo); if(lbl) I.descricao=lbl; }
				const familySelect = '<select data-role="family-select" class="flat">' + familyOptions.map(opt=>'<option value="' + escapeHtml(opt.value) + '"' + ((String(I.codigo)===String(opt.value)||String(opt.value).startsWith(String(I.codigo)+'_'))?' selected':'') + '>' + escapeHtml(opt.label) + '</option>').join("") + '</select>';
				const prodPickerDisplay = I.fixo===1 ? "grid" : "none";
				const familyPickerDisplay = I.fixo===1 ? "none" : "block";

				const row = document.createElement("div"); row.className="mb-item"; row.dataset.itemIndex=iIdx.toString();
				row.innerHTML =
					'<select data-role="fixo" class="flat" title="Tipo">' +
					'<option value="1"' + (I.fixo===1 ? ' selected' : '') + '>Produto</option>' +
					'<option value="2"' + (I.fixo===2 ? ' selected' : '') + '>Família</option>' +
					'</select>' +
					'<div class="mb-picker">' +
						'<div class="mb-prod-picker" style="display:' + prodPickerDisplay + ';">' +
							'<input type="text" class="flat" data-role="codigo" value="' + escapeHtml(I.codigo) + '" placeholder="Código">' +
							'<div class="mb-desc-wrap">' +
								'<input type="text" class="flat mb-search" data-role="descricao" value="' + escapeHtml(I.descricao) + '" placeholder="Descrição (buscar ref/label)">' +
								'<div class="mb-suggest"></div>' +
							'</div>' +
						'</div>' +
						'<div class="mb-family-picker" style="display:' + familyPickerDisplay + ';">' + familySelect + '</div>' +
					'</div>' +
					'<input type="number" step="0.01" class="flat" data-role="preco" value="' + priceValue + '" placeholder="Preço (€)"' + priceDisabled + '>' +
					'<select data-role="pvp" class="flat" title="Tabela PVP">' +
						'<option value=""' + (pvpNum==='' ? ' selected' : '') + '>PVP nenhum</option>' +
						'<option value="1"' + (pvpNum===1 ? ' selected' : '') + '>PVP 1</option>' +
						'<option value="2"' + (pvpNum===2 ? ' selected' : '') + '>PVP 2</option>' +
						'<option value="3"' + (pvpNum===3 ? ' selected' : '') + '>PVP 3</option>' +
						'<option value="4"' + (pvpNum===4 ? ' selected' : '') + '>PVP 4</option>' +
						'<option value="5"' + (pvpNum===5 ? ' selected' : '') + '>PVP 5</option>' +
						'<option value="6"' + (pvpNum===6 ? ' selected' : '') + '>PVP 6</option>' +
						'<option value="7"' + (pvpNum===7 ? ' selected' : '') + '>PVP 7</option>' +
						'<option value="8"' + (pvpNum===8 ? ' selected' : '') + '>PVP 8</option>' +
						'<option value="9"' + (pvpNum===9 ? ' selected' : '') + '>PVP 9</option>' +
						'<option value="10"' + (pvpNum===10 ? ' selected' : '') + '>PVP 10</option>' +
					'</select>' +
					'<label title="Permite complementos"><input type="checkbox" data-role="valcomplementos"' + (I.valcomplementos ? ' checked' : '') + '> Compl.</label>' +
					'<label title="Item predefinido"><input type="checkbox" data-role="produtodefault"' + (I.produtodefault ? ' checked' : '') + '> Predef.</label>' +
					'<div class="mb-remove">' +
						'<button type="button" class="mb-icon-btn" data-role="dup-item" title="Duplicar item"><span class="fas fa-plus" style="color:#28a745;"></span></button>' +
						'<button type="button" class="mb-icon-btn" data-role="remove-item" title="Remover item"><span class="fas fa-minus" style="color:#dc3545;"></span></button>' +
					'</div>';
				itemsWrap.appendChild(row);
				if (I.fixo === 1 && I.codigo && (!I.descricao || I.descricao === "")) fetchProductLabel(I.codigo, idx, iIdx, row);
			});
			card.appendChild(itemsWrap);
			builder.appendChild(card);
		});
		syncHidden();
	}

	function handleInputChange(ev){
		const role = ev.target.getAttribute("data-role"); if(!role) return;
		const card = ev.target.closest(".mb-card"); const idx = card ? parseInt(card.dataset.levelIndex||"-1",10) : -1; if(idx<0 || !state[idx]) return;
		if(role==="level-descricao") state[idx].descricao = ev.target.value;
		if(role==="obrigatorio"){ state[idx].obrigatorio = ev.target.checked ? 1 : 0; if(state[idx].nivel===1){ state[idx].obrigatorio=1; ev.target.checked=true; } }
		if(role==="ordem") state[idx].ordem = ev.target.value;
		const row = ev.target.closest(".mb-item");
		if(row){
			const iIdx = parseInt(row.dataset.itemIndex,10); const item = state[idx].niveismenuext[iIdx]; if(!item) return;
			if(role==="codigo"){ item.codigo = ev.target.value; if(item.fixo===1 && item.codigo && (!item.descricao || item.descricao.trim()==="")) fetchProductLabel(item.codigo, idx, iIdx, row); }
			if(role==="descricao") item.descricao = ev.target.value;
			if(role==="preco"){ item.preco = ev.target.value; item.opcaopvp=0; const pvpSel=row.querySelector('select[data-role="pvp"]'); if(pvpSel) pvpSel.value=""; ev.target.disabled=false; }
			if(role==="pvp"){ const v=parseInt(ev.target.value,10); const price=row.querySelector('input[data-role="preco"]'); if(!isNaN(v)&&v>=1&&v<=10){ item.opcaopvp=1; item.preco=v; if(price){price.value=""; price.disabled=true;} } else { item.opcaopvp=0; if(price){price.disabled=false;} } }
			if(role==="fixo"){ item.fixo=parseInt(ev.target.value,10)||1; const prod=row.querySelector(".mb-prod-picker"); const fam=row.querySelector(".mb-family-picker"); if(prod&&fam){ if(item.fixo===1){prod.style.display="grid"; fam.style.display="none";} else if(item.fixo===2){prod.style.display="none"; fam.style.display="block"; const lbl=familyLabelFor(item.codigo); if(lbl){ item.descricao=lbl; const desc=row.querySelector('input[data-role="descricao"]'); if(desc) desc.value=lbl; } } else {prod.style.display="grid"; fam.style.display="none";} } }
			if(role==="family-select"){ const sel=ev.target; const val=sel.value; const label=sel.options && sel.selectedIndex>=0 ? sel.options[sel.selectedIndex].text : ""; item.codigo=val; item.descricao=label; syncHidden(); return; }
			if(role==="valcomplementos") item.valcomplementos = ev.target.checked ? 1 : 0;
			if(role==="produtodefault"){ item.produtodefault = ev.target.checked ? 1 : 0; if(item.produtodefault){ state[idx].niveismenuext.forEach((it2,j)=>{ if(j!==iIdx) it2.produtodefault=0; }); render(); return; } }
		}
		syncHidden();
	}
	builder.addEventListener("input", handleInputChange);
	builder.addEventListener("change", handleInputChange);

	builder.addEventListener("click", function(ev){
		const roleEl = ev.target.closest("[data-role]"); const role = roleEl ? roleEl.getAttribute("data-role") : null; if(!role) return;
		const card = roleEl.closest(".mb-card"); const idx = card ? parseInt(card.dataset.levelIndex||"-1",10) : -1; if(idx<0 || !state[idx]) return;
		if(role==="add-item"){ state[idx].niveismenuext.push({codigo:"", descricao:"", preco:"", fixo:1, opcaopvp:0, valcomplementos:1, produtodefault:0}); render(); return; }
		if(role==="remove-level"){ state.splice(idx,1); render(); return; }
		if(role==="dup-item"){ const row=roleEl.closest(".mb-item"); if(!row) return; const iIdx=parseInt(row.dataset.itemIndex,10); const orig=state[idx].niveismenuext[iIdx]; if(!orig) return; const clone=JSON.parse(JSON.stringify(orig)); state[idx].niveismenuext.splice(iIdx+1,0,clone); render(); return; }
		if(role==="remove-item"){ const row=roleEl.closest(".mb-item"); if(!row) return; const iIdx=parseInt(row.dataset.itemIndex,10); state[idx].niveismenuext.splice(iIdx,1); render(); return; }
	});

	builder.addEventListener("input", function(ev){
		const role = ev.target.getAttribute("data-role"); if(role!=="descricao") return;
		const row = ev.target.closest(".mb-item"); if(!row) return;
		const itemIdx = parseInt(row.dataset.itemIndex,10);
		const card = ev.target.closest(".mb-card");
		const idx = card ? parseInt(card.dataset.levelIndex||"-1",10) : -1;
		const item = (idx>=0 && state[idx]) ? state[idx].niveismenuext[itemIdx] : null;
		const term = ev.target.value.trim();
		const suggestBox = row.querySelector(".mb-suggest");
		if(!suggestBox || !item || item.fixo!==1) return;
		if(term.length<2){ suggestBox.classList.remove("visible"); suggestBox.innerHTML=""; return; }
		if(searchTimers.has(ev.target)) clearTimeout(searchTimers.get(ev.target));
		const t = setTimeout(()=>{
			fetch(productSearchUrl + encodeURIComponent(term))
				.then(r=>r.json())
				.then(list=>{
					if(!Array.isArray(list)){ suggestBox.classList.remove("visible"); suggestBox.innerHTML=""; return; }
					let html=""; list.forEach(it=>{ const ref=it && it.ref ? String(it.ref) : ""; const desc=it && it.label ? String(it.label) : (it && it.value ? String(it.value) : ""); if(!ref && !desc) return; html += '<div class="mb-sug-item" data-ref="' + escapeHtml(ref) + '" data-desc="' + escapeHtml(desc) + '">' + escapeHtml(ref) + ' - ' + escapeHtml(desc) + '</div>'; });
					if(!html) html = '<div class="mb-sug-empty">Sem resultados</div>';
					suggestBox.innerHTML = html; suggestBox.classList.add("visible");
				})
				.catch(()=>{ suggestBox.innerHTML = '<div class="mb-sug-empty">Erro a procurar</div>'; suggestBox.classList.add("visible"); });
		},250);
		searchTimers.set(ev.target, t);
	});

	builder.addEventListener("click", function(ev){
		const sugItem = ev.target.closest(".mb-sug-item"); if(!sugItem) return;
		const rowEl = sugItem.closest(".mb-item"); if(!rowEl) return;
		const ref = sugItem.getAttribute("data-ref") || ""; const desc = sugItem.getAttribute("data-desc") || "";
		const codeInput = rowEl.querySelector('input[data-role="codigo"]'); const descInput = rowEl.querySelector('input[data-role="descricao"]'); const suggestBox = rowEl.querySelector(".mb-suggest");
		const card = rowEl.closest(".mb-card"); const idx = card ? parseInt(card.dataset.levelIndex||"-1",10) : -1; const iIdx = parseInt(rowEl.dataset.itemIndex,10);
		if(codeInput) codeInput.value = ref; if(descInput) descInput.value = desc; if(suggestBox){ suggestBox.classList.remove("visible"); suggestBox.innerHTML=""; }
		if(idx>=0 && state[idx] && !isNaN(iIdx)){ state[idx].niveismenuext[iIdx].codigo = ref; state[idx].niveismenuext[iIdx].descricao = desc; syncHidden(); }
	});

	if(btnAddLevel){ btnAddLevel.addEventListener("click", function(){ const nextIdx=state.length; state.push({descricao:"", obrigatorio:1, ordem:0, menu:productMenuCode, nivel: nextIdx+1, niveismenuext: [], _collapsed:0}); render(); }); }
	if(btnClear){ btnClear.addEventListener("click", function(){ if(confirm("Limpar todos os níveis?")){ state=[]; render(); } }); }
	if(btnCopy){ btnCopy.addEventListener("click", async function(){ try{ await navigator.clipboard.writeText(hidden ? hidden.value : ""); alert("JSON copiado."); } catch(e){ alert("Não foi possível copiar automaticamente. Copie manualmente."); } }); }

	if(hidden && hidden.value){
		try { const parsed=JSON.parse(hidden.value); if(Array.isArray(parsed)) state=parsed; }
		catch(e1){ try{ const repaired=hidden.value.replace(/&quot;/g, '"'); const parsed2=JSON.parse(repaired); if(Array.isArray(parsed2)) state=parsed2; } catch(e2){} }
	}

	render();
})();
</script>
