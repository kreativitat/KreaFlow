<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    kreaflow/mobile.php
 * \brief   Entry point for KreaFlow mobile frontend.
 *
 * This file serves:
 * - The React frontend shell with server-side session bootstrap.
 * - Lightweight data actions behind Dolibarr session auth (`kf_action`).
 */

$requestedAction = '';
if (isset($_REQUEST['kf_action']) && is_string($_REQUEST['kf_action'])) {
	$requestedAction = preg_replace('/[^a-z0-9_]/i', '', $_REQUEST['kf_action']);
}
if ($requestedAction === 'eats_analysis' && !defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}
$requestedGoogleLoginStart = !empty($_REQUEST['kf_google_login']);
$requestedGoogleOAuthReturn = !empty($_REQUEST['kf_google_oauth_return']);

if (!defined('NOREDIRECTBYMAINTOLOGIN')) {
	define('NOREDIRECTBYMAINTOLOGIN', '1');
}

if (($requestedGoogleLoginStart || $requestedGoogleOAuthReturn) && !defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (($requestedGoogleLoginStart || $requestedGoogleOAuthReturn) && !defined('DOLENTITY') && !empty($_REQUEST['entity']) && ctype_digit((string) $_REQUEST['entity']) && (int) $_REQUEST['entity'] > 0) {
	define('DOLENTITY', (int) $_REQUEST['entity']);
}

if ($requestedAction !== '' || $requestedGoogleLoginStart || $requestedGoogleOAuthReturn) {
	if (!defined('NOREQUIREMENU')) {
		define('NOREQUIREMENU', '1');
	}
	if (!defined('NOREQUIREHTML')) {
		define('NOREQUIREHTML', '1');
	}
	if (!defined('NOREQUIREAJAX')) {
		define('NOREQUIREAJAX', '1');
	}
	if (!defined('NOCSRFCHECK')) {
		define('NOCSRFCHECK', '1');
	}
	if (!defined('NOREDIRECTBYMAINTOLOGIN')) {
		define('NOREDIRECTBYMAINTOLOGIN', '1');
	}
	if (ob_get_level() === 0) {
		ob_start();
	}
}

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
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
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

$langs->loadLangs(array('main', 'errors', 'kreaflow@kreaflow'));

$isPublicAuthAction = in_array($requestedAction, array('login_meta', 'login'), true);
$isPublicShellRequest = ($requestedAction === '' && empty($user->id));

if (!isModEnabled('kreaflow') && !$isPublicAuthAction && !$isPublicShellRequest) {
	accessforbidden($langs->trans('ErrorModuleNotEnabled', 'KreaFlow'));
}

require_once __DIR__.'/class/KreaFlowMobileApi.class.php';

$api = new KreaFlowMobileApi($db, $user, $langs, $conf);

if ($requestedGoogleLoginStart) {
	try {
		$api->requireMethod('POST');
		$requestToken = GETPOST('token', 'alpha');
		$validTokens = array_filter(array(
			newToken(),
			currentToken(),
		));
		if ($requestToken === '' || !in_array($requestToken, $validTokens, true)) {
			throw new KreaFlowApiException($langs->trans('ErrorBadOrExpiredToken'), 403);
		}
		if (GETPOST('actionlogin', 'aZ09') !== 'login' || GETPOST('beforeoauthloginredirect', 'alpha') !== 'google') {
			throw new KreaFlowApiException($langs->trans('ErrorBadParameters'), 400);
		}

		$backToKreaFlow = dol_buildpath('/kreaflow/mobile.php', 1).'?kf_google_oauth_return=1';
		$redirectUrl = $api->buildGoogleOAuthLoginRedirectUrl(GETPOSTINT('entity'), GETPOST('username', 'alphanohtml'), $backToKreaFlow);

		header('Location: '.$redirectUrl);
		$db->close();
		exit;
	} catch (KreaFlowApiException $e) {
		$_SESSION["dol_loginmesg"] = $e->getMessage();
		dol_syslog('kreaflow/mobile.php refused Google login start: '.$e->getMessage(), LOG_WARNING);
		header('Location: '.dol_buildpath('/kreaflow/mobile.php', 1));
		$db->close();
		exit;
	} catch (Exception $e) {
		$_SESSION["dol_loginmesg"] = $e->getMessage();
		dol_syslog('kreaflow/mobile.php failed Google login start: '.$e->getMessage(), LOG_ERR);
		header('Location: '.dol_buildpath('/kreaflow/mobile.php', 1));
		$db->close();
		exit;
	}
}

if ($requestedGoogleOAuthReturn) {
	try {
		$username = GETPOST('username', 'alphanohtml');
		if ($username !== '' && GETPOST('actionlogin', 'aZ09') === 'login' && GETPOST('afteroauthloginreturn', 'alpha') === 'google') {
			$api->loginWithGoogleOAuthReturn($username, GETPOSTINT('entity'));
		}
	} catch (KreaFlowApiException $e) {
		$_SESSION["dol_loginmesg"] = $e->getMessage();
		dol_syslog('kreaflow/mobile.php refused Google OAuth return: '.$e->getMessage(), LOG_WARNING);
	} catch (Exception $e) {
		$_SESSION["dol_loginmesg"] = $e->getMessage();
		dol_syslog('kreaflow/mobile.php failed Google OAuth return: '.$e->getMessage(), LOG_ERR);
	}

	header('Location: '.dol_buildpath('/kreaflow/mobile.php', 1));
	$db->close();
	exit;
}

if ($requestedAction !== '') {
	try {
		if ($requestedAction === 'login_meta') {
			$api->requireMethod('GET');
			$api->respond(array(
				'success' => true,
				'data' => $api->getLoginMeta(),
			));
		}

		if ($requestedAction === 'login') {
			$api->requireMethod('POST');
			$payload = $api->getRequestJsonBody();
			$username = isset($payload['username']) ? (string) $payload['username'] : '';
			$password = isset($payload['password']) ? (string) $payload['password'] : '';
			$entity = isset($payload['entity']) ? (int) $payload['entity'] : GETPOSTINT('entity');
			$totpcode = isset($payload['totp_code']) ? (string) $payload['totp_code'] : GETPOST('totp_code', 'alphanohtml');
			$api->respond(array(
				'success' => true,
				'data' => $api->login($username, $password, $entity, $totpcode),
			));
		}

		if ($requestedAction === 'logout') {
			$api->requireMethod('POST');
			$api->respond(array(
				'success' => true,
				'data' => $api->logout(),
			));
		}

		if ($requestedAction === 'whoami') {
			$api->requireMethod('GET');
			$api->requireReadAccess();
			$api->respond(array(
				'success' => true,
				'data' => $api->getWhoAmI(),
			));
		}

		if ($requestedAction === 'dashboard') {
			$api->requireMethod('GET');
			$api->requireReadAccess();
			$api->respond(array(
				'success' => true,
				'data' => $api->getSalesDashboard(
					GETPOST('date_start', 'alpha'),
					GETPOST('date_end', 'alpha'),
					GETPOSTINT('top_products_page')
				),
			));
		}

        if ($requestedAction === 'eats_analysis') {
            $api->requireMethod('POST');
            $payload = $api->getRequestJsonBody();
            $api->respond(array('success'=>true, 'data'=>$api->analyseEatsWeek(
                (string) ($payload['week'] ?? ''), (int) ($payload['entity'] ?? 0), (string) ($payload['token'] ?? ''))));
        }

        if ($requestedAction === 'kitchen') {
            $api->requireMethod('GET');
            header('Cache-Control: no-store');
            $api->respond(array('success'=>true, 'data'=>$api->getKitchenDashboard()));
        }

        if ($requestedAction === 'eats_week') {
            $api->requireMethod('GET');
            $api->requireReadAccess();
            $api->respond(array('success'=>true, 'data'=>$api->getEatsWeek(GETPOST('week', 'alpha'), GETPOST('metric', 'alpha'))));
        }

		if ($requestedAction === 'sales_forecast') {
			$api->requireMethod('GET');
			$api->requireReadAccess();
			$api->respond(array(
				'success' => true,
				'data' => $api->getSalesForecast(
					GETPOST('date_start', 'alpha'),
					GETPOST('date_end', 'alpha')
				),
			));
		}

		if ($requestedAction === 'stock_forecast') {
			$api->requireMethod('GET');
			$api->requireReadAccess();
			$api->respond(array(
				'success' => true,
				'data' => $api->getStockForecast(
					GETPOST('date_start', 'alpha'),
					GETPOST('date_end', 'alpha')
				),
			));
		}

		if ($requestedAction === 'workforce') {
			$api->requireMethod('GET');
			$api->requireReadAccess();
			$api->respond(array(
				'success' => true,
				'data' => $api->getWorkforceOverview(
					GETPOST('week_start', 'alpha'),
					GETPOST('day', 'alpha'),
					GETPOST('punch_date', 'alpha')
				),
			));
		}

		if ($requestedAction === 'product_categories') {
			$api->requireMethod('GET');
			$api->requireReadAccess();
			$api->respond(array(
				'success' => true,
				'data' => $api->listProductCategories(),
			));
		}

		if ($requestedAction === 'products') {
			$api->requireMethod('GET');
			$api->requireReadAccess();
			$api->respond(array(
				'success' => true,
				'data' => $api->searchProducts(
					GETPOST('q', 'restricthtml'),
					GETPOSTINT('limit'),
					GETPOSTINT('category_id')
				),
			));
		}

		if ($requestedAction === 'product_detail') {
			$api->requireMethod('GET');
			$api->requireReadAccess();
			$api->respond(array(
				'success' => true,
				'data' => $api->getProductDetails(GETPOSTINT('id')),
			));
		}

		if ($requestedAction === 'fechofinanceiro') {
			$api->requireMethod('GET');
			$api->requireReadAccess();
			$api->respond(array(
				'success' => true,
				'data' => $api->listFechoFinanceiro(
					GETPOST('date_start', 'alpha'),
					GETPOST('date_end', 'alpha'),
					GETPOSTINT('status'),
					GETPOST('search', 'restricthtml'),
					GETPOSTINT('limit')
				),
			));
		}

		if ($requestedAction === 'fecho_meta') {
			$api->requireMethod('GET');
			$api->requireReadAccess();
			$api->respond(array(
				'success' => true,
				'data' => $api->getFechoFinanceiroMeta(),
			));
		}

		if ($requestedAction === 'fechofinanceiro_detail') {
			$api->requireMethod('GET');
			$api->requireReadAccess();
			$api->respond(array(
				'success' => true,
				'data' => $api->getFechoFinanceiroDetail(GETPOSTINT('id')),
			));
		}

		if ($requestedAction === 'fechofinanceiro_create') {
			$api->requireMethod('POST');
			$api->requireReadAccess();
			$api->respond(array(
				'success' => true,
				'data' => $api->createFechoFinanceiro($api->getRequestJsonBody()),
			));
		}

		if ($requestedAction === 'fechofinanceiro_update') {
			$api->requireMethod('POST');
			$api->requireReadAccess();
			$payload = $api->getRequestJsonBody();
			$payloadId = isset($payload['id']) ? (int) $payload['id'] : 0;
			if ($payloadId <= 0) {
				$payloadId = GETPOSTINT('id');
			}
			$api->respond(array(
				'success' => true,
				'data' => $api->updateFechoFinanceiro($payloadId, $payload),
			));
		}

		$api->respondError('Unknown action.', 404);
	} catch (KreaFlowApiException $e) {
		$api->respondError($e->getMessage(), $e->httpCode);
	} catch (Exception $e) {
		$api->respondError($e->getMessage(), 500);
	}
}

$requestedSwitchEntity = GETPOSTINT('kf_switch_entity');
if ($requestedSwitchEntity > 0 && $requestedSwitchEntity !== (int) $conf->entity) {
	if (empty($user->id)) {
		accessforbidden($langs->trans('ErrorNotLogged'));
	}
	if (!$user->hasRight('kreaflow', 'mobile', 'read')) {
		accessforbidden();
	}

	$requestToken = GETPOST('token', 'alpha');
	$validTokens = array_filter(array(
		newToken(),
		currentToken(),
	));
	if ($requestToken === '' || !in_array($requestToken, $validTokens, true)) {
		accessforbidden($langs->trans('ErrorBadOrExpiredToken'));
	}

	if (!isModEnabled('multicompany')) {
		accessforbidden($langs->trans('ErrorForbidden'));
	}

	dol_include_once('/multicompany/class/actions_multicompany.class.php', 'ActionsMulticompany');
	if (!class_exists('ActionsMulticompany')) {
		accessforbidden($langs->trans('ErrorForbidden'));
	}

	$actionsMulticompany = new ActionsMulticompany($db);
	$hasRight = $actionsMulticompany->checkRight((int) $user->id, $requestedSwitchEntity);
	if ($hasRight <= 0) {
		accessforbidden($langs->trans('ErrorForbidden'));
	}

	$switchResult = $actionsMulticompany->switchEntity($requestedSwitchEntity, (int) $user->id);
	if ($switchResult <= 0) {
		accessforbidden($langs->trans('ErrorForbidden'));
	}

	header('Location: '.dol_buildpath('/kreaflow/mobile.php', 1));
	$db->close();
	exit;
}

$bootstrapSession = null;
if (!empty($user->id)) {
	if (!$user->hasRight('kreaflow', 'mobile', 'read')) {
		accessforbidden();
	}
	$bootstrapSession = $api->getWhoAmI();
}

$frontendIndex = __DIR__.'/frontend/index.html';
if (!is_readable($frontendIndex)) {
	llxHeader('', $langs->trans('KreaFlowMobileApp'));
	print load_fiche_titre($langs->trans('KreaFlowMobileApp'), '', 'kreaflow.png@kreaflow');
	print '<div class="opacitymedium">'.$langs->trans('KreaFlowFrontendBuildMissing').'</div>';
	print '<pre>cd '.dol_escape_htmltag(__DIR__).'/app && npm install && npm run build:module</pre>';
	llxFooter();
	$db->close();
	exit;
}

$frontendHtml = file_get_contents($frontendIndex);
if ($frontendHtml === false) {
	llxHeader('', $langs->trans('KreaFlowMobileApp'));
	print load_fiche_titre($langs->trans('KreaFlowMobileApp'), '', 'kreaflow.png@kreaflow');
	print '<div class="opacitymedium">'.$langs->trans('ErrorFileNotFound').'</div>';
	llxFooter();
	$db->close();
	exit;
}

$bootstrapPayload = array(
	'session' => $bootstrapSession,
	'data_url' => dol_buildpath('/kreaflow/mobile.php', 1),
);
$bootstrapScript = '<script>';
$bootstrapScript .= 'window.__KREAFLOW_BOOTSTRAP__ = '.json_encode($bootstrapPayload['session'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).';';
$bootstrapScript .= 'window.__KREAFLOW_DATA_URL__ = '.json_encode($bootstrapPayload['data_url'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).';';
$bootstrapScript .= '</script>';

$injectedHtml = preg_replace('/<\/head>/i', $bootstrapScript.'</head>', $frontendHtml, 1);
if (is_string($injectedHtml) && $injectedHtml !== '') {
	$frontendHtml = $injectedHtml;
} else {
	$frontendHtml = $bootstrapScript.$frontendHtml;
}

header('Content-Type: text/html; charset=UTF-8');
print $frontendHtml;
$db->close();
exit;
