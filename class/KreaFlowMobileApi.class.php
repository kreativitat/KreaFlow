<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

/**
 * API exception wrapper with HTTP status code.
 */
class KreaFlowApiException extends Exception
{
	/** @var int */
	public $httpCode;

	/**
	 * @param string $message
	 * @param int $httpCode
	 */
	public function __construct($message, $httpCode = 400)
	{
		parent::__construct($message);
		$this->httpCode = (int) $httpCode;
	}
}

/**
 * KreaFlow mobile API service layer.
 */
class KreaFlowMobileApi
{
	/** @var DoliDB */
	private $db;

	/** @var User */
	private $user;

	/** @var Translate */
	private $langs;

	/** @var Conf */
	private $conf;

	/**
	 * @param DoliDB $db
	 * @param User $user
	 * @param Translate $langs
	 * @param Conf $conf
	 */
	public function __construct($db, $user, $langs, $conf)
	{
		$this->db = $db;
		$this->user = $user;
		$this->langs = $langs;
		$this->conf = $conf;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param int $httpCode
	 * @return never
	 */
	public function respond(array $payload, $httpCode = 200)
	{
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		http_response_code((int) $httpCode);
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode($payload);
		exit;
	}

	/**
	 * @param string $message
	 * @param int $httpCode
	 * @param array<string, mixed> $details
	 * @return never
	 */
	public function respondError($message, $httpCode = 400, array $details = array())
	{
		$payload = array(
			'success' => false,
			'error' => array(
				'message' => (string) $message,
			),
		);

		if (!empty($details)) {
			$payload['error']['details'] = $details;
		}

		$this->respond($payload, (int) $httpCode);
	}

	/**
	 * Decode JSON request body into an associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function getRequestJsonBody()
	{
		$raw = file_get_contents('php://input');
		if ($raw === false || trim($raw) === '') {
			return array();
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			throw new KreaFlowApiException('Invalid JSON payload.', 400);
		}

		return $decoded;
	}

	/**
	 * @param string $method
	 * @return void
	 */
	public function requireMethod($method)
	{
		$currentMethod = empty($_SERVER['REQUEST_METHOD']) ? 'GET' : strtoupper($_SERVER['REQUEST_METHOD']);
		if ($currentMethod !== strtoupper($method)) {
			throw new KreaFlowApiException($this->langs->trans('ErrorBadMethod'), 405);
		}
	}

	/**
	 * @return void
	 */
	public function requireReadAccess()
	{
		$this->ensureApiContext();
		if (!$this->user->hasRight('kreaflow', 'mobile', 'read')) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}
	}

	/**
	 * @return bool
	 */
	private function canReadDashboardMenu()
	{
		return $this->user->hasRight('kreaflow', 'dashboard', 'read');
	}

    /** Kitchen access is independent of financial/product menus and checked before network access. */
    private function canReadKitchen()
    {
        return $this->user->hasRight('kreaflow', 'mobile', 'read')
            && $this->canReadDashboardMenu()
            && $this->user->hasRight('kreaflow', 'kitchen', 'read');
    }

    public function getKitchenDashboard()
    {
        $this->requireReadAccess();
        if (!$this->canReadKitchen()) {
            throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
        }
        require_once __DIR__.'/KreaFlowKitchenClient.class.php';
        $client = new KreaFlowKitchenClient((int) $this->conf->entity);
        require_once __DIR__.'/KreaFlowKitchenSettings.class.php';
        $window = KreaFlowKitchenSettings::workingDay();
        $settings = KreaFlowKitchenSettings::load($this->db, (int) $this->conf->entity);
        $window['stations'] = $settings['ids'] ? implode(',', $settings['ids']) : '0';
        $data = $client->read($this->canReadBeyondHistoryLimit() ? '' : $this->getHistoryEarliestDate(), $window);
        return KreaFlowKitchenSettings::scope($data, $settings['ids']);
    }

    /** Match KreaEats native access requirements before reading any report data. */
    private function canReadEatsDashboard()
    {
        return $this->user->hasRight('kreaflow', 'mobile', 'read')
            && $this->canReadDashboardMenu() && isModEnabled('kreaeats')
            && $this->user->hasRight('kreaeats', 'read')
            && $this->user->hasRight('facture', 'lire')
            && $this->user->hasRight('fournisseur', 'facture', 'lire')
            && $this->user->hasRight('banque', 'lire');
    }

    public function getEatsWeek($date, $metric = 'amount')
    {
        if (!$this->canReadEatsDashboard()) {
            throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
        }
        if ($date !== '' && !$this->isDateYmd($date)) {
            throw new KreaFlowApiException('Invalid week date', 400);
        }
        $date = $date ?: date('Y-m-d', strtotime('-7 days'));
        $monday = date('Y-m-d', strtotime($date.' -'.((int) date('N', strtotime($date)) - 1).' days'));
        $sunday = date('Y-m-d', strtotime($monday.' +6 days'));
        $range = $this->normalizeDateRange($monday, $sunday, false, false);
        // Reuse KreaEats 1.14+ cost semantics, never silently serve the old formula.
        dol_include_once('/kreaeats/class/kreaeatsservice.class.php');
        require_once __DIR__.'/KreaFlowEatsDashboard.class.php';
        $service = new KreaEatsService($this->db, $this->user, (int) $this->conf->entity);
        $view = new KreaFlowEatsDashboard($service);
        $result = $view->week($range['start_date'], $range['end_date'], $metric === 'quantity' ? 'quantity' : 'amount');
        $result['period']['limited'] = $range['start_date'] !== $monday || $range['end_date'] !== $sunday;
        $result['period']['previous'] = $this->canReadBeyondHistoryLimit() || $range['start_date'] > $this->getHistoryEarliestDate();
        $result['period']['next'] = $range['end_date'] < date('Y-m-d');
        require_once __DIR__.'/KreaFlowEatsAnalysis.class.php';
        $result['analysis_access'] = true;
        $result['analysis_available'] = $result['analysis_access'] && KreaFlowEatsAnalysis::available();
        $result['entity'] = (int) $this->conf->entity;
        $result['token'] = newToken();
        return $result;
    }

    public function analyseEatsWeek($date, $entity, $token)
    {
        $this->requireReadAccess();
        if (!$this->canReadEatsDashboard() || (int) $entity !== (int) $this->conf->entity) {
            throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
        }
        if ($token === '' || !in_array($token, array_filter(array(currentToken(), newToken())), true)) {
            throw new KreaFlowApiException($this->langs->trans('ErrorBadOrExpiredToken'), 403);
        }
        require_once __DIR__.'/KreaFlowEatsAnalysis.class.php';
        if (!KreaFlowEatsAnalysis::available()) { throw new KreaFlowApiException('Análise de IA desativada.', 403); }
        $week = $this->getEatsWeek($date);
        if (!$week['analysis_available']) { throw new KreaFlowApiException('Configure o serviço de IA nas definições do KreaProducts.', 503); }
        if (!$week['economics']['has_data']) { throw new KreaFlowApiException('Ainda não existem relatórios Uber nesta semana para analisar.', 409); }
        $previous = null;
        $previousDate = date('Y-m-d', strtotime($week['period']['start'].' -7 days'));
        if (!$week['period']['limited'] && ($this->canReadBeyondHistoryLimit() || $previousDate >= $this->getHistoryEarliestDate())) {
            $previousWeek = $this->getEatsWeek($previousDate);
            if (!$previousWeek['period']['limited']) { $previous = KreaFlowEatsAnalysis::facts($previousWeek); }
        }
        require_once __DIR__.'/KreaFlowEatsAnalysisRunner.class.php';
        try {
            return KreaFlowEatsAnalysisRunner::run($this->db, (int) $entity, (int) $this->user->id,
                $this->getEntityLabelById((int) $entity), $week, $previous);
        } catch (RuntimeException $e) {
            throw new KreaFlowApiException($e->getMessage(), $e->getCode());
        }
    }

	/**
	 * Check access to the KreaProducts-backed stock forecast report.
	 *
	 * @return bool
	 */
	private function canReadStockForecast()
	{
		return $this->isStockForecastEnabledForEntity((int) $this->conf->entity)
			&& $this->canReadDashboardMenu()
			&& isModEnabled('kreaproducts')
			&& $this->user->hasRight('kreaproducts', 'stockmobile', 'read')
			&& $this->user->hasRight('kreaproducts', 'inventory', 'expected')
			&& $this->user->hasRight('stock', 'lire');
	}

	/**
	 * Check the global setup allowlist for the active entity.
	 * An unconfigured installation has no enabled companies.
	 *
	 * @param int $entityId Entity ID
	 * @return bool
	 */
	private function isStockForecastEnabledForEntity($entityId)
	{
		$entityId = (int) $entityId;
		if ($entityId <= 0) {
			return false;
		}

		$configuredEntities = $this->getStockForecastEntityConfig();
		if ($configuredEntities === '') {
			return false;
		}

		foreach (explode(',', $configuredEntities) as $configuredEntity) {
			if ((int) trim((string) $configuredEntity) === $entityId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read the site-wide stock-forecast entity allowlist from entity 0.
	 *
	 * @return string
	 */
	private function getStockForecastEntityConfig()
	{
		$sql = 'SELECT value';
		$sql .= ' FROM '.$this->db->prefix().'const';
		$sql .= " WHERE name = 'KREAFLOW_STOCK_FORECAST_ENTITIES'";
		$sql .= ' AND entity = 0';
		$sql .= ' LIMIT 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('KreaFlow could not read stock forecast entity configuration: '.$this->db->lasterror(), LOG_WARNING);
			return '';
		}
		$object = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $object && isset($object->value) ? trim((string) $object->value) : '';
	}

	/**
	 * @return bool
	 */
	private function canReadProductsMenu()
	{
		return $this->user->hasRight('kreaflow', 'products', 'read');
	}

	/**
	 * @return bool
	 */
	private function canReadWorkforceScheduleMenu()
	{
		return $this->user->hasRight('kreaflow', 'workforce_schedule', 'read');
	}

	/**
	 * @return bool
	 */
	private function canReadWorkforcePunchMenu()
	{
		return $this->user->hasRight('kreaflow', 'workforce_punch', 'read');
	}

	/**
	 * @return bool
	 */
	private function canReadFechoMenu()
	{
		return $this->user->hasRight('kreaflow', 'fecho', 'read');
	}

	/**
	 * @return bool
	 */
	private function canCreateFechoMenu()
	{
		return $this->user->hasRight('kreaflow', 'fecho', 'create');
	}

	/**
	 * @return bool
	 */
	private function canUpdateFechoMenu()
	{
		return $this->user->hasRight('kreaflow', 'fecho', 'update');
	}

	/**
	 * @return bool
	 */
	private function canUpdateValidatedFechoMenu()
	{
		return $this->user->hasRight('kreaflow', 'fecho', 'updatevalidated');
	}

	/**
	 * @return bool
	 */
	private function canUpdateOldFechoMenu()
	{
		return $this->user->hasRight('kreaflow', 'fecho', 'updateold');
	}

	/**
	 * @return bool
	 */
	private function canReadBeyondHistoryLimit()
	{
		return $this->user->hasRight('kreaflow', 'history', 'readall');
	}

	/**
	 * Authenticate a user with Dolibarr credentials and open a KreaFlow session.
	 *
	 * @param string $username
	 * @param string $password
	 * @param int $entity
	 * @param string $totpCode
	 * @return array<string, mixed>
	 */
	public function login($username, $password, $entity, $totpCode = '')
	{
		$username = trim((string) $username);
		$password = (string) $password;
		$entity = (int) $entity;
		$totpCode = $this->normalizeTotpCode($totpCode);

		if ($username === '') {
			throw new KreaFlowApiException('Username is required.', 400);
		}
		if ($password === '') {
			throw new KreaFlowApiException('Password is required.', 400);
		}
		if ($entity <= 0) {
			$entity = !empty($this->conf->entity) ? (int) $this->conf->entity : 1;
		}
		if (!$this->isModuleEnabledForEntity('kreaflow', $entity)) {
			throw new KreaFlowApiException($this->langs->trans('ErrorModuleNotEnabled', 'KreaFlow'), 403);
		}

		include_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';

		$this->prepareTotpCodeForAuthProvider($totpCode);

		$authModes = $this->resolveAuthModes();
		$login = checkLoginPassEntity($username, $password, $entity, $authModes, '');
		if ($login === '--bad-login-validity--') {
			$login = '';
		}
		if (empty($login)) {
			throw new KreaFlowApiException($this->langs->trans('ErrorBadLoginPassword'), 401);
		}

		$authenticatedUser = new User($this->db);
		$resultFetchUser = $authenticatedUser->fetch(0, $login, '', 1, ($entity > 0 ? $entity : -1));
		if ($resultFetchUser <= 0 || $authenticatedUser->isNotIntoValidityDateRange()) {
			throw new KreaFlowApiException($this->langs->trans('ErrorBadLoginPassword'), 401);
		}
		if (!empty($authenticatedUser->socid)) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$authMode = !empty($this->conf->authmode) ? (string) $this->conf->authmode : 'dolibarr';

		return $this->initializeAuthenticatedSession($authenticatedUser, $entity, $authMode, 'KreaFlow API login');
	}

	/**
	 * Complete a KreaFlow Google OAuth login after Krea2FactorAuth validates the Google callback.
	 *
	 * @param string $username
	 * @param int $entity
	 * @return array<string, mixed>
	 */
	public function loginWithGoogleOAuthReturn($username, $entity)
	{
		$username = trim((string) $username);
		$entity = (int) $entity;

		if ($username === '') {
			throw new KreaFlowApiException('Username is required.', 400);
		}
		if ($entity <= 0) {
			$entity = !empty($this->conf->entity) ? (int) $this->conf->entity : 1;
		}
		if (!$this->isModuleEnabledForEntity('kreaflow', $entity)) {
			throw new KreaFlowApiException($this->langs->trans('ErrorModuleNotEnabled', 'KreaFlow'), 403);
		}

		$manager = $this->getKrea2FactorAuthManager();
		$this->langs->load('krea2factorauth@krea2factorauth');
		if (!is_object($manager)
			|| !method_exists($manager, 'isGoogleLoginEnabled')
			|| !method_exists($manager, 'isGoogleOAuthConfigured')
			|| !method_exists($manager, 'isModuleActiveForEntity')
			|| !$manager->isGoogleLoginEnabled()
			|| !$manager->isGoogleOAuthConfigured()
			|| !$manager->isModuleActiveForEntity($entity)
		) {
			throw new KreaFlowApiException($this->langs->trans('Krea2FactorAuthGoogleLoginUnavailable'), 403);
		}

		include_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';

		$authModes = $this->resolveAuthModes();
		if (!in_array('krea2factorauth', $authModes, true)) {
			array_unshift($authModes, 'krea2factorauth');
		}
		$authModes = array_values(array_unique($authModes));

		$login = checkLoginPassEntity($username, '', $entity, $authModes, '');
		if ($login === '--bad-login-validity--') {
			$login = '';
		}
		if (empty($login)) {
			throw new KreaFlowApiException($this->langs->trans('ErrorBadLoginPassword'), 401);
		}

		$authenticatedUser = new User($this->db);
		$resultFetchUser = $authenticatedUser->fetch(0, $login, '', 1, ($entity > 0 ? $entity : -1));
		if ($resultFetchUser <= 0 || $authenticatedUser->isNotIntoValidityDateRange()) {
			throw new KreaFlowApiException($this->langs->trans('ErrorBadLoginPassword'), 401);
		}
		if (!empty($authenticatedUser->socid)) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$authMode = !empty($this->conf->authmode) ? (string) $this->conf->authmode : 'krea2factorauth';

		return $this->initializeAuthenticatedSession($authenticatedUser, $entity, $authMode, 'KreaFlow Google OAuth login');
	}

	/**
	 * Build the KreaFlow-owned redirect to Krea2FactorAuth's Google OAuth callback.
	 *
	 * @param int $entity
	 * @param string $username
	 * @param string $backToKreaFlowUrl
	 * @return string
	 */
	public function buildGoogleOAuthLoginRedirectUrl($entity, $username, $backToKreaFlowUrl)
	{
		$entity = (int) $entity;
		$username = trim((string) $username);
		$backToKreaFlowUrl = trim((string) $backToKreaFlowUrl);

		if ($entity <= 0) {
			$entity = !empty($this->conf->entity) ? (int) $this->conf->entity : 1;
		}
		if ($backToKreaFlowUrl === '') {
			$backToKreaFlowUrl = dol_buildpath('/kreaflow/mobile.php', 1).'?kf_google_oauth_return=1';
		}
		if (!$this->isModuleEnabledForEntity('kreaflow', $entity)) {
			throw new KreaFlowApiException($this->langs->trans('ErrorModuleNotEnabled', 'KreaFlow'), 403);
		}

		$manager = $this->getKrea2FactorAuthManager();
		$this->langs->load('krea2factorauth@krea2factorauth');
		if (!is_object($manager)
			|| !method_exists($manager, 'isGoogleLoginEnabled')
			|| !method_exists($manager, 'isGoogleOAuthConfigured')
			|| !method_exists($manager, 'isModuleActiveForEntity')
			|| !method_exists($manager, 'getGoogleOAuthLoginCallbackUrl')
			|| !$manager->isGoogleLoginEnabled()
			|| !$manager->isGoogleOAuthConfigured()
			|| !$manager->isModuleActiveForEntity($entity)
		) {
			throw new KreaFlowApiException($this->langs->trans('Krea2FactorAuthGoogleLoginUnavailable'), 403);
		}

		$_SESSION['datafromloginform'] = array(
			'entity' => $entity,
			'backtopage' => GETPOST('backtopage'),
			'tz' => GETPOST('tz'),
			'tz_string' => GETPOST('tz_string'),
			'dst_observed' => GETPOST('dst_observed'),
			'dst_first' => GETPOST('dst_first'),
			'dst_second' => GETPOST('dst_second'),
			'dol_screenwidth' => GETPOST('screenwidth'),
			'dol_screenheight' => GETPOST('screenheight'),
			'dol_hide_topmenu' => GETPOST('dol_hide_topmenu'),
			'dol_hide_leftmenu' => GETPOST('dol_hide_leftmenu'),
			'dol_optimize_smallscreen' => GETPOST('dol_optimize_smallscreen'),
			'dol_no_mouse_hover' => GETPOST('dol_no_mouse_hover'),
			'dol_use_jmobile' => GETPOST('dol_use_jmobile'),
		);

		if (method_exists($manager, 'clearGoogleLoginPendingChallenge')) {
			$manager->clearGoogleLoginPendingChallenge();
		}

		$shortscope = Krea2FactorAuthManager::GOOGLE_OAUTH_SCOPES;
		$oauthstateanticsrf = bin2hex(random_bytes(16));
		$url = $manager->getGoogleOAuthLoginCallbackUrl();
		$separator = (strpos($url, '?') === false) ? '?' : '&';
		$url .= $separator.'shortscope='.urlencode($shortscope);
		$url .= '&state='.urlencode('forlogin-'.$shortscope.'-entity'.$entity.'-'.$oauthstateanticsrf);
		$url .= '&backtourl='.urlencode($backToKreaFlowUrl);
		$url .= '&entity='.$entity;

		if ($username !== '') {
			$url .= '&username='.urlencode($username);
		}

		return $url;
	}

	/**
	 * Return public metadata for the custom login form.
	 *
	 * @return array<string, mixed>
	 */
	public function getLoginMeta()
	{
		$krea2FactorAuthManager = $this->getKrea2FactorAuthManager();
		if (is_object($krea2FactorAuthManager)) {
			$this->langs->load('krea2factorauth@krea2factorauth');
		}
		$entities = array();
		if ($this->tableExists('entity') && $this->tableExists('const')) {
			$sql = "SELECT e.rowid as id";
			$sql .= ", COALESCE(NULLIF(TRIM(e.label), ''), CONCAT('Entity ', e.rowid)) as label";
			$sql .= " FROM ".$this->db->prefix()."entity as e";
			$sql .= " INNER JOIN ".$this->db->prefix()."const as c";
			$sql .= " ON c.entity = e.rowid";
			$sql .= " AND c.name = 'MAIN_MODULE_KREAFLOW'";
			$sql .= " AND (c.value = '1' OR UPPER(c.value) = 'TRUE')";
			$sql .= " WHERE e.rowid > 0";
			if ($this->tableColumnExists('entity', 'active')) {
				$sql .= " AND COALESCE(e.active, 1) = 1";
			}
			$sql .= " GROUP BY e.rowid, e.label";
			$sql .= " ORDER BY e.rowid ASC";

			$rows = $this->fetchRowsOptional($sql);
			foreach ($rows as $row) {
				$entityId = isset($row['id']) ? (int) $row['id'] : 0;
				if ($entityId <= 0) {
					continue;
				}
				$entities[] = array(
					'id' => $entityId,
					'label' => isset($row['label']) ? (string) $row['label'] : 'Entity '.$entityId,
				);
			}
		}

		if (empty($entities)) {
			$defaultEntity = !empty($this->conf->entity) ? (int) $this->conf->entity : 1;
			if ($defaultEntity > 0 && $this->isModuleEnabledForEntity('kreaflow', $defaultEntity)) {
				$defaultLabel = $this->getEntityLabelById($defaultEntity);
				if ($defaultLabel === '') {
					$defaultLabel = 'Entity '.$defaultEntity;
				}
				$entities[] = array(
					'id' => $defaultEntity,
					'label' => $defaultLabel,
				);
			}
		}

		if (empty($entities)) {
			$defaultEntity = 0;
		} else {
			$defaultEntity = (int) $entities[0]['id'];
			$currentEntity = !empty($this->conf->entity) ? (int) $this->conf->entity : 0;
			if ($currentEntity > 0) {
				foreach ($entities as $entityRow) {
					if ((int) $entityRow['id'] === $currentEntity) {
						$defaultEntity = $currentEntity;
						break;
					}
				}
			}
		}

		foreach ($entities as &$entityRow) {
			$entityRow['krea2factorauth'] = $this->buildKrea2FactorAuthEntityLoginMeta($krea2FactorAuthManager, (int) $entityRow['id']);
		}
		unset($entityRow);

		return array(
			'default_entity' => $defaultEntity,
			'entities' => $entities,
			'krea2factorauth' => array(
				'available' => is_object($krea2FactorAuthManager) ? 1 : 0,
				'non_web_enforced' => (is_object($krea2FactorAuthManager) && $krea2FactorAuthManager->isNonWebEnforcementEnabled()) ? 1 : 0,
				'google_login_token' => function_exists('newToken') ? newToken() : '',
				'labels' => array(
					'login_code' => is_object($krea2FactorAuthManager) ? $this->langs->transnoentitiesnoconv('Krea2FactorAuthLoginCode') : 'Authenticator code',
					'login_hint' => is_object($krea2FactorAuthManager) ? $this->langs->transnoentitiesnoconv('Krea2FactorAuthLoginHint') : 'Enter the 6-digit code from your authenticator app.',
					'login_button' => is_object($krea2FactorAuthManager) ? $this->langs->transnoentitiesnoconv('Krea2FactorAuthLoginButton') : 'Verify and sign in',
					'login_or' => is_object($krea2FactorAuthManager) ? $this->langs->transnoentitiesnoconv('Krea2FactorAuthLoginOr') : 'Or',
					'login_with_google' => is_object($krea2FactorAuthManager) ? $this->langs->transnoentitiesnoconv('Krea2FactorAuthLoginWithGoogle') : 'Sign in with Google',
				),
			),
		);
	}

	/**
	 * Terminate current Dolibarr session.
	 *
	 * @return array<string, int>
	 */
	public function logout()
	{
		if (!empty($this->user->id)) {
			$this->user->call_trigger('USER_LOGOUT', $this->user);
		}

		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}

		unset($_SESSION['dol_login'], $_SESSION['dol_entity'], $_SESSION['urlfrom']);

		return array('logged_out' => 1);
	}

	/**
	 * Switch user session to another Dolibarr entity.
	 *
	 * @param int $entityId
	 * @return array<string, mixed>
	 */
	public function switchEntity($entityId)
	{
		$this->requireReadAccess();

		$entityId = (int) $entityId;
		if ($entityId <= 0) {
			throw new KreaFlowApiException('Entity id is required.', 400);
		}

		$currentEntityId = (int) $this->conf->entity;
		if ($entityId === $currentEntityId) {
			return $this->getWhoAmI();
		}

		if (!isModEnabled('multicompany')) {
			throw new KreaFlowApiException('Entity switch is unavailable because MultiCompany is not enabled.', 403);
		}

		dol_include_once('/multicompany/class/actions_multicompany.class.php', 'ActionsMulticompany');
		if (!class_exists('ActionsMulticompany')) {
			throw new KreaFlowApiException('MultiCompany classes are not available on this installation.', 500);
		}

		$actionsMulticompany = new ActionsMulticompany($this->db);
		$checkResult = $actionsMulticompany->checkRight((int) $this->user->id, $entityId);
		if ($checkResult <= 0) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$switchResult = $actionsMulticompany->switchEntity($entityId, (int) $this->user->id);
		$usedFallbackSwitch = false;
		if ($switchResult <= 0) {
			// Some MultiCompany setups deny switchEntity() API usage even when rights are valid.
			// Fallback to explicit entity context switch after positive checkRight().
			$_SESSION['dol_entity'] = $entityId;
			$this->conf->entity = $entityId;
			if (method_exists($this->conf, 'setValues')) {
				$this->conf->setValues($this->db);
			}
			$usedFallbackSwitch = true;
		}

		$reloadResult = $this->reloadSessionUser($entityId);
		if ($reloadResult <= 0) {
			if (!$usedFallbackSwitch) {
				$actionsMulticompany->switchEntity($currentEntityId, (int) $this->user->id);
			}
			$_SESSION['dol_entity'] = $currentEntityId;
			$this->conf->entity = $currentEntityId;
			if (method_exists($this->conf, 'setValues')) {
				$this->conf->setValues($this->db);
			}
			$this->reloadSessionUser($currentEntityId);
			throw new KreaFlowApiException('Failed to reload user session after entity switch.', 500);
		}

		if (!$this->user->hasRight('kreaflow', 'mobile', 'read')) {
			if (!$usedFallbackSwitch) {
				$actionsMulticompany->switchEntity($currentEntityId, (int) $this->user->id);
			}
			$_SESSION['dol_entity'] = $currentEntityId;
			$this->conf->entity = $currentEntityId;
			if (method_exists($this->conf, 'setValues')) {
				$this->conf->setValues($this->db);
			}
			$this->reloadSessionUser($currentEntityId);
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		return $this->getWhoAmI();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getWhoAmI()
	{
		$entities = $this->getAccessibleEntities();
		$currentEntityId = (int) $this->conf->entity;
		$currentEntityLabel = $this->resolveCurrentEntityLabel($entities, $currentEntityId);
		$moduleInfo = $this->getModuleInfo();

		return array(
			'user' => array(
				'id' => (int) $this->user->id,
				'login' => (string) $this->user->login,
				'fullname' => (string) dolGetFirstLastname($this->user->firstname, $this->user->lastname),
				'email' => (string) $this->user->email,
			),
			'entity' => $currentEntityId,
			'entity_label' => $currentEntityLabel,
			'can_switch_entity' => count($entities) > 1 ? 1 : 0,
			'available_entities' => $entities,
			'rights' => array(
				'api_read' => $this->user->hasRight('kreaflow', 'mobile', 'read') ? 1 : 0,
				'dashboard_read' => $this->canReadDashboardMenu() ? 1 : 0,
                'kitchen_read' => $this->canReadKitchen() ? 1 : 0,
                'eats_read' => $this->canReadEatsDashboard() ? 1 : 0,
				'stock_forecast_read' => $this->canReadStockForecast() ? 1 : 0,
				'products_read' => $this->canReadProductsMenu() ? 1 : 0,
				'workforce_schedule_read' => $this->canReadWorkforceScheduleMenu() ? 1 : 0,
				'workforce_punch_read' => $this->canReadWorkforcePunchMenu() ? 1 : 0,
				'fecho_read' => $this->canReadFechoMenu() ? 1 : 0,
				'fecho_create' => $this->canCreateFechoMenu() ? 1 : 0,
				'fecho_update' => $this->canUpdateFechoMenu() ? 1 : 0,
				'fecho_update_validated' => $this->canUpdateValidatedFechoMenu() ? 1 : 0,
				'fecho_update_old' => $this->canUpdateOldFechoMenu() ? 1 : 0,
				'fecho_edit_limit_days' => $this->getFechoEditLimitDays(),
				'history_readall' => $this->canReadBeyondHistoryLimit() ? 1 : 0,
				'history_limit_days' => $this->getHistoryLimitDays(),
				'facture_read' => $this->user->hasRight('facture', 'lire') ? 1 : 0,
				'product_read' => ($this->user->hasRight('produit', 'lire') || $this->user->hasRight('service', 'lire')) ? 1 : 0,
			),
			'module_info' => $moduleInfo,
			'token' => newToken(),
			);
	}

	/**
	 * @return array<string, string>
	 */
	private function getModuleInfo()
	{
		$info = array(
			'gpl_notice' => 'GPLv3 or later. See COPYING.',
			'module' => 'KreaFlow',
			'version' => '',
			'editor' => 'Kreativität Works',
			'license' => 'GPL-3.0-or-later',
			'support' => 'mail@kreativitat.com',
			'website' => 'http://kreativitat.com',
		);

		$descriptorPath = dirname(__DIR__).'/core/modules/modKreaFlow.class.php';
		if (!is_readable($descriptorPath)) {
			return $info;
		}

		require_once $descriptorPath;
		if (!class_exists('modKreaFlow')) {
			return $info;
		}

		try {
			$moduleDescriptor = new modKreaFlow($this->db);
			if (isset($moduleDescriptor->name) && trim((string) $moduleDescriptor->name) !== '') {
				$info['module'] = trim((string) $moduleDescriptor->name);
			}
			if (isset($moduleDescriptor->version) && trim((string) $moduleDescriptor->version) !== '') {
				$info['version'] = trim((string) $moduleDescriptor->version);
			}
			if (isset($moduleDescriptor->editor_name) && trim((string) $moduleDescriptor->editor_name) !== '') {
				$info['editor'] = trim((string) $moduleDescriptor->editor_name);
			}
			if (isset($moduleDescriptor->license) && trim((string) $moduleDescriptor->license) !== '') {
				$info['license'] = trim((string) $moduleDescriptor->license);
			}
			if (isset($moduleDescriptor->editor_email) && trim((string) $moduleDescriptor->editor_email) !== '') {
				$info['support'] = trim((string) $moduleDescriptor->editor_email);
			}
			if (isset($moduleDescriptor->editor_url) && trim((string) $moduleDescriptor->editor_url) !== '') {
				$info['website'] = trim((string) $moduleDescriptor->editor_url);
			}
		} catch (Exception $e) {
			dol_syslog(__METHOD__.' module info fallback: '.$e->getMessage(), LOG_WARNING);
		}

		return $info;
	}

	/**
	 * @param string $search
	 * @param int $limit
	 * @param int $categoryId
	 * @return array<int, array<string, mixed>>
	 */
	public function searchProducts($search, $limit, $categoryId = 0)
	{
		if (!$this->canReadProductsMenu()) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}
		if (!$this->user->hasRight('produit', 'lire') && !$this->user->hasRight('service', 'lire')) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$search = trim((string) $search);
		$normalizedSearch = preg_replace('/\s+/', '', $search);
		$isNumericSearch = ($search !== '' && is_string($normalizedSearch) && preg_match('/^\d+$/', $normalizedSearch));
		$searchTokens = !$isNumericSearch ? $this->extractSearchTokens($search) : array();
		$limit = $this->sanitizeLimit($limit);
		$categoryId = (int) $categoryId;
		$configuredCategoryIds = $this->getConfiguredProductCategoryIds();
		$mustJoinCategory = ($categoryId > 0 || !empty($configuredCategoryIds));
		$toSellOnly = $this->mustFilterProductsToSell();
		$categoryIdListSql = !empty($configuredCategoryIds) ? implode(',', array_map('intval', $configuredCategoryIds)) : '';
		if ($categoryId > 0 && !empty($configuredCategoryIds) && !in_array($categoryId, $configuredCategoryIds, true)) {
			return array();
		}

		$sql = "SELECT DISTINCT p.rowid, p.ref, p.label, p.price, p.price_ttc, p.tva_tx";
		$sql .= " FROM ".$this->db->prefix()."product as p";
		if ($mustJoinCategory) {
			$sql .= " INNER JOIN ".$this->db->prefix()."categorie_product as cp ON cp.fk_product = p.rowid";
			$sql .= " INNER JOIN ".$this->db->prefix()."categorie as c ON c.rowid = cp.fk_categorie";
		}
		$sql .= " WHERE p.entity IN (".getEntity('product').")";
		if ($toSellOnly) {
			$sql .= " AND p.tosell = 1";
		}
		if ($mustJoinCategory) {
			$sql .= " AND c.entity IN (".getEntity('category').")";
			$sql .= " AND c.type = 0";
		}
		if ($categoryId > 0) {
			$sql .= " AND c.rowid = ".$categoryId;
		} elseif ($categoryIdListSql !== '') {
			$sql .= " AND c.rowid IN (".$categoryIdListSql.")";
		}
		if ($isNumericSearch) {
			$searchEscaped = '%'.$this->db->escape((string) $normalizedSearch).'%';
			$sql .= " AND UPPER(p.ref) LIKE UPPER('".$searchEscaped."')";
		} elseif (!empty($searchTokens)) {
			foreach ($searchTokens as $token) {
				$tokenLike = '%'.$this->db->escape($token).'%';
				$sql .= " AND UPPER(p.label) LIKE UPPER('".$tokenLike."')";
			}
		} elseif ($search !== '') {
			$searchEscaped = '%'.$this->db->escape($search).'%';
			$sql .= " AND UPPER(p.label) LIKE UPPER('".$searchEscaped."')";
		}
		if ($isNumericSearch) {
			$searchExact = $this->db->escape((string) $normalizedSearch);
			$searchPrefix = $searchExact.'%';
			$sql .= " ORDER BY (";
			$sql .= " CASE WHEN UPPER(p.ref) = UPPER('".$searchExact."') THEN 120 ELSE 0 END";
			$sql .= " + CASE WHEN UPPER(p.ref) LIKE UPPER('".$searchPrefix."') THEN 80 ELSE 0 END";
			$sql .= " + CASE WHEN UPPER(p.ref) LIKE UPPER('%".$searchExact."%') THEN 40 ELSE 0 END";
			$sql .= " ) DESC, p.ref ASC";
		} elseif (!empty($searchTokens)) {
			$searchExact = $this->db->escape($search);
			$searchPrefix = $this->db->escape($search).'%';
			$sql .= " ORDER BY (";
			$sql .= " CASE WHEN UPPER(p.label) = UPPER('".$searchExact."') THEN 120 ELSE 0 END";
			$sql .= " + CASE WHEN UPPER(p.label) LIKE UPPER('".$searchPrefix."') THEN 80 ELSE 0 END";
			$sql .= " ) DESC, p.label ASC, p.ref ASC";
		} else {
			$sql .= " ORDER BY p.label ASC, p.ref ASC";
		}
		$sql .= $this->db->plimit($limit, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaFlowApiException($this->db->lasterror(), 500);
		}

		$results = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$results[] = array(
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'label' => (string) $obj->label,
				'price_ht' => (float) price2num($obj->price),
				'price_ttc' => (float) price2num($obj->price_ttc),
				'tva_tx' => (float) price2num($obj->tva_tx),
			);
		}
		$this->db->free($resql);

		return $results;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listProductCategories()
	{
		if (!$this->canReadProductsMenu()) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}
		if (!$this->user->hasRight('produit', 'lire') && !$this->user->hasRight('service', 'lire')) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$langCode = $this->db->escape((string) $this->langs->defaultlang);
		$configuredCategoryIds = $this->getConfiguredProductCategoryIds();
		if (empty($configuredCategoryIds)) {
			return array();
		}
		$categoryIdListSql = !empty($configuredCategoryIds) ? implode(',', array_map('intval', $configuredCategoryIds)) : '';
		$toSellOnly = $this->mustFilterProductsToSell();

		$sql = "SELECT c.rowid as id";
		$sql .= ", COALESCE(cl.label, c.label) as label";
		$sql .= ", c.color";
		$sql .= ", COUNT(DISTINCT p.rowid) as product_count";
		$sql .= " FROM ".$this->db->prefix()."categorie as c";
		$sql .= " LEFT JOIN ".$this->db->prefix()."categorie_lang as cl";
		$sql .= " ON cl.fk_category = c.rowid AND cl.lang = '".$langCode."'";
		$sql .= " LEFT JOIN ".$this->db->prefix()."categorie_product as cp ON cp.fk_categorie = c.rowid";
		$sql .= " LEFT JOIN ".$this->db->prefix()."product as p";
		$sql .= " ON p.rowid = cp.fk_product";
		$sql .= " AND p.entity IN (".getEntity('product').")";
		if ($toSellOnly) {
			$sql .= " AND p.tosell = 1";
		}
		$sql .= " WHERE c.entity IN (".getEntity('category').")";
		$sql .= " AND c.type = 0";
		if ($categoryIdListSql !== '') {
			$sql .= " AND c.rowid IN (".$categoryIdListSql.")";
		}
		$sql .= " GROUP BY c.rowid, COALESCE(cl.label, c.label), c.color";
		$sql .= " ORDER BY ";
		$sql .= "CASE c.rowid";
		foreach ($configuredCategoryIds as $position => $configuredCategoryId) {
			$sql .= " WHEN ".((int) $configuredCategoryId)." THEN ".((int) $position + 1);
		}
		$sql .= " ELSE 999999 END, ";
		$sql .= "COALESCE(cl.label, c.label) ASC";

		$rows = $this->fetchRows($sql);
		$categories = array();
		foreach ($rows as $row) {
			$categories[] = array(
				'id' => (int) $row['id'],
				'label' => (string) $row['label'],
				'color' => (string) $row['color'],
				'product_count' => (int) $row['product_count'],
			);
		}

		return $categories;
	}

	/**
	 * @param int $productId
	 * @return array<string, mixed>
	 */
	public function getProductDetails($productId)
	{
		if (!$this->canReadProductsMenu()) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}
		if (!$this->user->hasRight('produit', 'lire') && !$this->user->hasRight('service', 'lire')) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$productId = (int) $productId;
		if ($productId <= 0) {
			throw new KreaFlowApiException('Product id is required.', 400);
		}

		$sql = "SELECT p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc, p.tva_tx, p.cost_price";
		$sql .= " FROM ".$this->db->prefix()."product as p";
		$sql .= " WHERE p.entity IN (".getEntity('product').")";
		$sql .= " AND p.rowid = ".$productId;
		$sql .= " LIMIT 1";

		$rows = $this->fetchRows($sql);
		if (empty($rows)) {
			throw new KreaFlowApiException('Product not found.', 404);
		}

		$row = $rows[0];
		$canViewSuppliers = $this->user->hasRight('fournisseur', 'lire') ? 1 : 0;
		$kreaproductsEnabled = isModEnabled('kreaproducts') ? 1 : 0;

		$nutrition = null;
		$allergens = array();
		if ($kreaproductsEnabled) {
			$nutrition = $this->fetchKreaProductsNutrition($productId);
			$allergens = $this->fetchKreaProductsAllergens($productId);
		}

		return array(
			'id' => (int) $row['rowid'],
			'ref' => (string) $row['ref'],
			'label' => (string) $row['label'],
			'description' => trim((string) $row['description']),
			'price_ht' => (float) price2num($row['price']),
			'price_ttc' => (float) price2num($row['price_ttc']),
			'tva_tx' => (float) price2num($row['tva_tx']),
			'cost_price' => $this->normalizeNullableNumeric(isset($row['cost_price']) ? $row['cost_price'] : null),
			'can_view_suppliers' => $canViewSuppliers,
			'suppliers' => $canViewSuppliers ? $this->fetchProductSuppliers($productId) : array(),
			'kreaproducts_enabled' => $kreaproductsEnabled,
			'nutrition' => $nutrition,
			'allergens' => $allergens,
		);
	}

	/**
	 * @param string $startDate
	 * @param string $endDate
	 * @param int $topProductsPage
	 * @return array<string, mixed>
	 */
	public function getSalesDashboard($startDate, $endDate, $topProductsPage = 1)
	{
		if (!$this->canReadDashboardMenu()) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$range = $this->normalizeDateRange($startDate, $endDate, false, false);
		$startDateTime = $range['start_date'].' 00:00:00';
		$endDateTime = $range['end_date'].' 23:59:59';
		$chartStartDate = $range['start_date'];
		if ((int) $range['day_count'] < 7) {
			$chartStartDate = date('Y-m-d', strtotime($range['end_date'].' -6 days'));
		}
		if (!$this->canReadBeyondHistoryLimit()) {
			$earliestAllowedDate = $this->getHistoryEarliestDate();
			if ($chartStartDate < $earliestAllowedDate) {
				$chartStartDate = $earliestAllowedDate;
			}
		}
		$chartStartDateTime = $chartStartDate.' 00:00:00';

		$dailyRows = $this->fetchDailySalesRows($startDateTime, $endDateTime, false);
		$dailySeries = $this->buildDailySeries($dailyRows, $range['start_date'], $range['end_date']);

		$dailyChartRows = $dailyRows;
		if ($chartStartDate < $range['start_date']) {
			$paddingEnd = date('Y-m-d', strtotime($range['start_date'].' -1 day')).' 23:59:59';
			$dailyChartRows = array_merge($this->fetchDailySalesRows($chartStartDateTime, $paddingEnd, false), $dailyRows);
		}
		$dailyChartSeries = $this->buildDailySeries($dailyChartRows, $chartStartDate, $range['end_date']);

		$totalRevenueHt = 0;
		$totalRevenueTtc = 0;
		$totalQty = 0;
		$totalInvoices = 0;
		$totalPeople = 0;
		foreach ($dailySeries as $row) {
			$totalRevenueHt += (float) $row['revenue_ht'];
			$totalRevenueTtc += (float) $row['revenue_ttc'];
			$totalQty += (float) $row['qty_sold'];
			$totalInvoices += (int) $row['invoice_count'];
			$totalPeople += (float) (isset($row['people_count']) ? $row['people_count'] : 0);
		}

		$days = max(1, (int) $range['day_count']);
		$avgPerDayHt = $totalRevenueHt / $days;
		$avgPerDayTtc = $totalRevenueTtc / $days;
		$ticketDivisor = $totalPeople > 0 ? $totalPeople : ($totalInvoices > 0 ? $totalInvoices : 0);
		$avgTicketHt = $ticketDivisor > 0 ? ($totalRevenueHt / $ticketDivisor) : 0;
		$avgTicketTtc = $ticketDivisor > 0 ? ($totalRevenueTtc / $ticketDivisor) : 0;
		$topProductsPage = $this->sanitizePositivePage($topProductsPage);
		$topProductsPageSize = 12;
		$topProductsData = $this->fetchTopProductsPageData($startDateTime, $endDateTime, $topProductsPage, $topProductsPageSize);

		return array(
			'period' => array(
				'date_start' => $range['start_date'],
				'date_end' => $range['end_date'],
				'day_count' => $days,
				'chart_start' => $chartStartDate,
			),
			'kpis' => array(
				'total_revenue_ht' => $totalRevenueHt,
				'total_revenue_ttc' => $totalRevenueTtc,
				'total_qty_sold' => $totalQty,
				'total_invoices' => $totalInvoices,
				'total_people' => (int) round($totalPeople),
				'avg_per_day_ht' => $avgPerDayHt,
				'avg_per_day_ttc' => $avgPerDayTtc,
				'avg_ticket_ht' => $avgTicketHt,
				'avg_ticket_ttc' => $avgTicketTtc,
			),
			'previous_year_sales' => $this->fetchPreviousYearSales($range['start_date'], $range['end_date']),
			'daily_sales' => $dailyChartSeries,
			'hourly_sales' => $this->fetchHourlySalesRows($startDateTime, $endDateTime),
			'sales_by_payment_type' => $this->fetchSalesByPaymentTypeRows($startDateTime, $endDateTime),
			'top_products' => isset($topProductsData['rows']) && is_array($topProductsData['rows']) ? $topProductsData['rows'] : array(),
			'top_products_total' => isset($topProductsData['total']) ? (int) $topProductsData['total'] : 0,
			'top_products_page' => isset($topProductsData['page']) ? (int) $topProductsData['page'] : 1,
			'top_products_page_size' => isset($topProductsData['page_size']) ? (int) $topProductsData['page_size'] : $topProductsPageSize,
			'top_products_pages' => isset($topProductsData['pages']) ? (int) $topProductsData['pages'] : 1,
			'sales_by_employee' => $this->fetchSalesByEmployeeRows($startDateTime, $endDateTime),
		);
	}

	/**
	 * Build weather-aware sales forecast payload for KreaFlow native frontend.
	 *
	 * @param string $startDate
	 * @param string $endDate
	 * @return array<string, mixed>
	 */
	public function getSalesForecast($startDate, $endDate)
	{
		if (!$this->canReadDashboardMenu()) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$displayRange = $this->normalizeDateRange($startDate, $endDate, false, true);
		$trainDays = max(60, min(365, (int) getDolGlobalInt('KREAFLOW_SF_TRAIN_DAYS', 365)));
		$trainingRange = $this->buildForecastTrainingRange(date('Y-m-d', strtotime('-1 day')), $trainDays);
		if ($displayRange['start_date'] < $trainingRange['start_date']) {
			$displayRange['start_date'] = $trainingRange['start_date'];
		}
		if ($displayRange['end_date'] > $trainingRange['end_date']) {
			$displayRange['end_date'] = $trainingRange['end_date'];
		}
		if ($displayRange['start_date'] > $displayRange['end_date']) {
			$displayRange['end_date'] = $trainingRange['end_date'];
			$displayRange['start_date'] = date('Y-m-d', strtotime($displayRange['end_date'].' -29 days'));
		}
		$displayRange['day_count'] = (int) floor(
			(strtotime($displayRange['end_date'].' 12:00:00') - strtotime($displayRange['start_date'].' 12:00:00')) / 86400
		) + 1;
		$startDateTime = $trainingRange['start_date'].' 00:00:00';
		$endDateTime = $trainingRange['end_date'].' 23:59:59';

		$dailyRows = $this->fetchDailyForecastRevenueRows($startDateTime, $endDateTime);
		$dailySeries = $this->buildDailySeries($dailyRows, $trainingRange['start_date'], $trainingRange['end_date']);
		$displaySeries = array_values(array_filter($dailySeries, static function ($row) use ($displayRange) {
			$dateValue = isset($row['sale_date']) ? (string) $row['sale_date'] : '';
			return $dateValue >= $displayRange['start_date'] && $dateValue <= $displayRange['end_date'];
		}));

		$totalRevenueHt = 0.0;
		$totalRevenueTtc = 0.0;
		foreach ($displaySeries as $row) {
			$totalRevenueHt += (float) $row['revenue_ht'];
			$totalRevenueTtc += (float) $row['revenue_ttc'];
		}

		$displayDayCount = max(1, (int) $displayRange['day_count']);
		$avgRevenueHt = $totalRevenueHt / $displayDayCount;
		$avgRevenueTtc = $totalRevenueTtc / $displayDayCount;
		$forecastDays = (int) getDolGlobalInt('KREAFLOW_SF_FORECAST_DAYS', 7);
		if ($forecastDays <= 0) {
			$forecastDays = 7;
		}
		if ($forecastDays > 10) {
			$forecastDays = 10;
		}

		$weatherCoordQuery = $this->getForecastWeatherCoordinates((int) $this->conf->entity);
		$weatherHistoryUrl = 'https://archive-api.open-meteo.com/v1/archive?';
		$weatherHistoryUrl .= $weatherCoordQuery;
		$weatherHistoryUrl .= '&start_date='.$trainingRange['start_date'];
		$weatherHistoryUrl .= '&end_date='.$trainingRange['end_date'];
		$weatherHistoryUrl .= '&daily=temperature_2m_mean,precipitation_sum&timezone=Europe%2FLisbon';

		$weatherHistoryPayload = $weatherCoordQuery !== '' ? $this->fetchJsonUrl($weatherHistoryUrl) : null;
		$weatherHistoryDaily = array();
		if (is_array($weatherHistoryPayload) && isset($weatherHistoryPayload['daily']) && is_array($weatherHistoryPayload['daily'])) {
			$weatherHistoryDaily = $weatherHistoryPayload['daily'];
		}
		$weatherHistoryByDate = $this->mapWeatherDailyByDate($weatherHistoryDaily);

		$weatherForecastUrl = 'https://api.open-meteo.com/v1/forecast?';
		$weatherForecastUrl .= $weatherCoordQuery;
		$weatherForecastUrl .= '&daily=temperature_2m_mean,precipitation_sum,precipitation_probability_max';
		$weatherForecastUrl .= '&forecast_days='.$forecastDays;
		$weatherForecastUrl .= '&timezone=Europe%2FLisbon';

		$weatherForecastPayload = $weatherCoordQuery !== '' ? $this->fetchJsonUrl($weatherForecastUrl) : null;
		$weatherForecastDaily = array();
		if (is_array($weatherForecastPayload) && isset($weatherForecastPayload['daily']) && is_array($weatherForecastPayload['daily'])) {
			$weatherForecastDaily = $weatherForecastPayload['daily'];
		}

		$years = array();
		foreach ($dailySeries as $row) {
			if (isset($row['sale_date']) && $this->isDateYmd((string) $row['sale_date'])) {
				$years[(int) substr((string) $row['sale_date'], 0, 4)] = true;
			}
		}
		if (isset($weatherForecastDaily['time']) && is_array($weatherForecastDaily['time'])) {
			foreach ($weatherForecastDaily['time'] as $dateValue) {
				$dateString = (string) $dateValue;
				if ($this->isDateYmd($dateString)) {
					$years[(int) substr($dateString, 0, 4)] = true;
				}
			}
		}
		if (empty($years)) {
			$years[(int) date('Y')] = true;
		}
		$holidaySet = $this->buildPortugueseHolidaySet(array_keys($years));

		$forecastModelTtc = $this->buildSalesForecastModel($dailySeries, $weatherHistoryByDate, $holidaySet, 'revenue_ttc');
		$forecastModelHt = $this->buildSalesForecastModel($dailySeries, $weatherHistoryByDate, $holidaySet, 'revenue_ht');
		$lastTimeIndex = max(0, count($dailySeries) - 1);
		$featureMode = !empty($weatherHistoryByDate) && !empty($weatherForecastDaily)
			? 'seasonal_weather_ridge'
			: 'seasonal_ridge';

		$forecastDates = array();
		$forecastTemps = array();
		$forecastPrecip = array();
		$forecastPrecipProbability = array();
		if (isset($weatherForecastDaily['time']) && is_array($weatherForecastDaily['time'])) {
			$forecastTimes = $weatherForecastDaily['time'];
			$forecastTempValues = isset($weatherForecastDaily['temperature_2m_mean']) && is_array($weatherForecastDaily['temperature_2m_mean'])
				? $weatherForecastDaily['temperature_2m_mean']
				: array();
			$forecastPrecipValues = isset($weatherForecastDaily['precipitation_sum']) && is_array($weatherForecastDaily['precipitation_sum'])
				? $weatherForecastDaily['precipitation_sum']
				: array();
			$forecastPrecipProbabilityValues = isset($weatherForecastDaily['precipitation_probability_max']) && is_array($weatherForecastDaily['precipitation_probability_max'])
				? $weatherForecastDaily['precipitation_probability_max']
				: array();
			foreach ($forecastTimes as $index => $dateValue) {
				$dateString = (string) $dateValue;
				if (!$this->isDateYmd($dateString)) {
					continue;
				}
				$forecastDates[] = $dateString;
				$forecastTemps[] = $this->normalizeNullableFiniteFloat(isset($forecastTempValues[$index]) ? $forecastTempValues[$index] : null);
				$forecastPrecip[] = $this->normalizeFiniteFloat(isset($forecastPrecipValues[$index]) ? $forecastPrecipValues[$index] : 0.0, 0.0);
				$forecastPrecipProbability[] = $this->normalizePrecipitationProbability(isset($forecastPrecipProbabilityValues[$index]) ? $forecastPrecipProbabilityValues[$index] : null);
				if (count($forecastDates) >= $forecastDays) {
					break;
				}
			}
		}

		if (empty($forecastDates)) {
			$cursorDate = date('Y-m-d');
			for ($index = 0; $index < $forecastDays; $index++) {
				$forecastDates[] = $cursorDate;
				$forecastTemps[] = null;
				$forecastPrecip[] = 0.0;
				$forecastPrecipProbability[] = null;
				$cursorDate = date('Y-m-d', strtotime($cursorDate.' +1 day'));
			}
		} elseif (count($forecastDates) < $forecastDays) {
			$cursorDate = $forecastDates[count($forecastDates) - 1];
			while (count($forecastDates) < $forecastDays) {
				$cursorDate = date('Y-m-d', strtotime($cursorDate.' +1 day'));
				$forecastDates[] = $cursorDate;
				$forecastTemps[] = null;
				$forecastPrecip[] = 0.0;
				$forecastPrecipProbability[] = null;
			}
		}

		$forecastRows = array();
		$forecastTotalHt = 0.0;
		$forecastTotalTtc = 0.0;
		for ($index = 0; $index < count($forecastDates); $index++) {
			$dateValue = $forecastDates[$index];
			$tempValue = isset($forecastTemps[$index]) ? $forecastTemps[$index] : null;
			$precipValue = isset($forecastPrecip[$index]) ? (float) $forecastPrecip[$index] : 0.0;
			$precipProbability = isset($forecastPrecipProbability[$index]) ? $forecastPrecipProbability[$index] : null;
			$holidayValue = isset($holidaySet[$dateValue]) ? 1.0 : 0.0;
			$futureTimeIndex = $lastTimeIndex + $index + 1;
			$predictionTtc = $this->predictSalesForecastValue(
				$forecastModelTtc,
				$dailySeries,
				$dateValue,
				$futureTimeIndex,
				$tempValue,
				$precipValue,
				$holidayValue
			);
			$predictionHt = $this->predictSalesForecastValue(
				$forecastModelHt,
				$dailySeries,
				$dateValue,
				$futureTimeIndex,
				$tempValue,
				$precipValue,
				$holidayValue
			);
			if ($predictionTtc < $predictionHt) {
				$predictionTtc = $predictionHt;
			}
			$precipitationLabels = $this->classifyPrecipitationLabels($precipValue);

			$forecastTotalTtc += $predictionTtc;
			$forecastTotalHt += $predictionHt;
			$forecastRows[] = array(
				'date' => $dateValue,
				'forecast_ht' => $predictionHt,
				'forecast_ttc' => $predictionTtc,
				'temp_mean' => $tempValue,
				'precip_mm' => $precipValue,
				'precip_intensity' => $precipitationLabels['intensity'],
				'precip_probability' => $precipitationLabels['probability'],
				'precip_probability_pct' => $precipProbability,
			);
		}

		$chartRows = array();
		$todayDate = date('Y-m-d');
		$chartStartDate = $displayRange['start_date'];
		foreach ($displaySeries as $row) {
			$saleDate = isset($row['sale_date']) ? (string) $row['sale_date'] : '';
			if ($saleDate === '' || $saleDate < $chartStartDate || $saleDate >= $todayDate) {
				continue;
			}
			$chartRows[] = array(
				'date' => $saleDate,
				'revenue_ttc' => isset($row['revenue_ttc']) ? (float) $row['revenue_ttc'] : 0.0,
				'series' => 'historical',
			);
		}
		foreach ($forecastRows as $row) {
			$chartRows[] = array(
				'date' => isset($row['date']) ? (string) $row['date'] : '',
				'revenue_ttc' => isset($row['forecast_ttc']) ? (float) $row['forecast_ttc'] : 0.0,
				'series' => 'forecast',
			);
		}
		usort($chartRows, function ($left, $right) {
			$leftDate = isset($left['date']) ? (string) $left['date'] : '';
			$rightDate = isset($right['date']) ? (string) $right['date'] : '';
			if ($leftDate === $rightDate) {
				$leftSeries = isset($left['series']) ? (string) $left['series'] : '';
				$rightSeries = isset($right['series']) ? (string) $right['series'] : '';
				return strcmp($leftSeries, $rightSeries);
			}
			return strcmp($leftDate, $rightDate);
		});

		$forecastCount = count($forecastRows);
		$forecastAvgHt = $forecastCount > 0 ? ($forecastTotalHt / $forecastCount) : 0.0;
		$forecastAvgTtc = $forecastCount > 0 ? ($forecastTotalTtc / $forecastCount) : 0.0;

		return array(
			'period' => array(
				'date_start' => $displayRange['start_date'],
				'date_end' => $displayRange['end_date'],
				'day_count' => $displayDayCount,
				'chart_start' => $chartStartDate,
				'chart_days' => $displayDayCount,
				'training_start' => $trainingRange['start_date'],
				'training_end' => $trainingRange['end_date'],
				'training_days' => $trainingRange['day_count'],
				'forecast_days' => $forecastCount,
			),
			'kpis' => array(
				'historical_total_ht' => $totalRevenueHt,
				'historical_total_ttc' => $totalRevenueTtc,
				'historical_avg_ht' => $avgRevenueHt,
				'historical_avg_ttc' => $avgRevenueTtc,
				'forecast_total_ht' => $forecastTotalHt,
				'forecast_total_ttc' => $forecastTotalTtc,
				'forecast_avg_ht' => $forecastAvgHt,
				'forecast_avg_ttc' => $forecastAvgTtc,
			),
			'chart_rows' => $chartRows,
			'forecast_rows' => $forecastRows,
			'accuracy' => $this->getForecastAccuracy($displayRange['start_date'], $displayRange['end_date']),
			'meta' => array(
				'model' => $featureMode,
				'has_weather_history' => !empty($weatherHistoryByDate) ? 1 : 0,
				'has_weather_forecast' => !empty($weatherForecastDaily) ? 1 : 0,
			),
		);
	}

    /** Use the shared archive evaluator; never expose history beyond mobile permissions. */
    public function getForecastAccuracy($startDate, $endDate)
    {
        if (!$this->canReadDashboardMenu()) {
            throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
        }
        $range = $this->normalizeDateRange($startDate, $endDate, false, true);
        if (!isModEnabled('kreareports')) {
            return array('status' => 'unavailable');
        }
        dol_include_once('/kreareports/class/salesforecastaccuracy.class.php');
        if (!class_exists('KreaReportsSalesForecastAccuracy')) {
            return array('status' => 'unavailable');
        }
        $service = new KreaReportsSalesForecastAccuracy($this->db, $this->user, $this->conf->entity);
        $result = $service->read($range['start_date'], $range['end_date']);
        $result['labels'] = KreaReportsSalesForecastAccuracy::labels($this->langs);
        return $result;
    }

	/**
	 * Build a read-only PATTIES stock requirement report from the latest
	 * KreaProducts inventory and the native sales forecast.
	 *
	 * @param string $startDate Historical sales start date
	 * @param string $endDate   Historical sales end date
	 * @return array<string, mixed>
	 */
	public function getStockForecast($startDate, $endDate)
	{
		if (!$this->canReadStockForecast()) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		dol_include_once('/kreaproducts/class/KreaProductsMobileInventoryService.class.php');
		if (!class_exists('KreaProductsMobileInventoryService')) {
			throw new KreaFlowApiException('KreaProducts inventory service is unavailable.', 503);
		}

		try {
			$inventoryService = new KreaProductsMobileInventoryService($this->db, $this->user, $this->langs, $this->conf);
			$inventoryList = $inventoryService->listInventories();
			$latestInventory = null;
			foreach ($inventoryList['inventories'] as $inventoryRow) {
				$categoryLabel = isset($inventoryRow['category_label']) ? (string) $inventoryRow['category_label'] : '';
				$normalizedLabel = strtoupper(trim((string) dol_string_unaccent($categoryLabel)));
				if ($normalizedLabel === 'PATTIES' && (int) $inventoryRow['status'] === 2) {
					$latestInventory = $inventoryRow;
					break;
				}
			}

			if (!is_array($latestInventory) || empty($latestInventory['id'])) {
				throw new KreaFlowApiException('No recorded PATTIES inventory is available for the active entity.', 404);
			}

			$inventory = $inventoryService->getInventory((int) $latestInventory['id']);
			$statistics = $inventoryService->getInventoryStatistics((int) $latestInventory['id'], 31);
		} catch (KreaFlowApiException $exception) {
			throw $exception;
		} catch (KreaProductsStockApiException $exception) {
			throw new KreaFlowApiException($exception->getMessage(), (int) $exception->httpCode);
		}

		$salesForecast = $this->getSalesForecast($startDate, $endDate);
		$statisticsStart = isset($statistics['period_start']) ? (string) $statistics['period_start'] : '';
		$statisticsEnd = isset($statistics['period_end']) ? (string) $statistics['period_end'] : '';
		if (!$this->isDateYmd($statisticsStart) || !$this->isDateYmd($statisticsEnd)) {
			throw new KreaFlowApiException('KreaProducts returned an invalid stock statistics period.', 500);
		}
		$completedHistoryEnd = (new DateTimeImmutable('today', new DateTimeZone('Europe/Lisbon')))
			->modify('-1 day')
			->format('Y-m-d');
		if ($statisticsEnd > $completedHistoryEnd) {
			$statisticsEnd = $completedHistoryEnd;
		}
		$historyDays = $statisticsStart <= $statisticsEnd
			? ((int) floor((strtotime($statisticsEnd.' 12:00:00') - strtotime($statisticsStart.' 12:00:00')) / 86400) + 1)
			: 0;

		$historicalRevenueRows = $this->fetchDailyForecastRevenueRows(
			$statisticsStart.' 00:00:00',
			$statisticsEnd.' 23:59:59'
		);
		$revenueByDate = array();
		foreach ($historicalRevenueRows as $row) {
			$dateValue = isset($row['sale_date']) ? (string) $row['sale_date'] : '';
			if ($this->isDateYmd($dateValue)) {
				$revenueByDate[$dateValue] = max(0.0, $this->normalizeFiniteFloat($row['revenue_ttc'], 0.0));
			}
		}

		$inventoryLinesByProduct = array();
		$productIds = array();
		foreach ($inventory['lines'] as $line) {
			$productId = isset($line['product_id']) ? (int) $line['product_id'] : 0;
			if ($productId <= 0) {
				continue;
			}
			if (!isset($inventoryLinesByProduct[$productId])) {
				$inventoryLinesByProduct[$productId] = array(
					'product_id' => $productId,
					'ref' => isset($line['ref']) ? (string) $line['ref'] : '',
					'label' => isset($line['label']) ? (string) $line['label'] : '',
					'counted_stock' => 0.0,
					'inventory_snapshot_stock' => 0.0,
				);
				$productIds[] = $productId;
			}
			if (isset($line['quantity']) && $line['quantity'] !== null) {
				$inventoryLinesByProduct[$productId]['counted_stock'] += (float) $line['quantity'];
			}
			if (isset($line['virtual_stock_at_business_close'])) {
				$inventoryLinesByProduct[$productId]['inventory_snapshot_stock'] += (float) $line['virtual_stock_at_business_close'];
			}
		}

		$currentStockByProduct = $this->fetchWarehouseStockByProduct(
			$productIds,
			isset($inventory['warehouse_id']) ? (int) $inventory['warehouse_id'] : 0
		);
		$statisticsByProduct = array();
		foreach ($statistics['products'] as $productStatistics) {
			$productId = isset($productStatistics['product_id']) ? (int) $productStatistics['product_id'] : 0;
			if ($productId > 0) {
				$statisticsByProduct[$productId] = $productStatistics;
			}
		}

		$forecastRows = isset($salesForecast['forecast_rows']) && is_array($salesForecast['forecast_rows'])
			? $salesForecast['forecast_rows']
			: array();
		$products = array();
		$atRiskProducts = 0;
		$horizonAtRiskProducts = 0;
		foreach ($inventoryLinesByProduct as $productId => $inventoryProduct) {
			$dailyConsumption = isset($statisticsByProduct[$productId]['daily']) && is_array($statisticsByProduct[$productId]['daily'])
				? $statisticsByProduct[$productId]['daily']
				: array();
			$demand = $this->calculateStockForecastDemand($dailyConsumption, $revenueByDate, $forecastRows);
			$currentStock = isset($currentStockByProduct[$productId]) ? (float) $currentStockByProduct[$productId] : 0.0;
			$forecastRequired = isset($demand['forecast_required']) ? (float) $demand['forecast_required'] : 0.0;
			$projectedStock = $currentStock - $forecastRequired;
			$shortage = max(0.0, -$projectedStock);
			$deliveryRequirements = $this->buildStockDeliveryRequirements(
				isset($demand['daily']) && is_array($demand['daily']) ? $demand['daily'] : array()
			);
			$nextDelivery = isset($deliveryRequirements['next_delivery']) && is_array($deliveryRequirements['next_delivery'])
				? $deliveryRequirements['next_delivery']
				: null;
			$nextDeliveryRequired = $nextDelivery !== null ? (float) $nextDelivery['required_quantity'] : 0.0;
			$nextDeliveryProjectedStock = $currentStock - $nextDeliveryRequired;
			$nextDeliveryShortage = max(0.0, -$nextDeliveryProjectedStock);
			if ($nextDeliveryShortage > 0.00001) {
				$atRiskProducts++;
			}
			if ($shortage > 0.00001) {
				$horizonAtRiskProducts++;
			}
			$products[] = array(
				'product_id' => (int) $productId,
				'ref' => (string) $inventoryProduct['ref'],
				'label' => (string) $inventoryProduct['label'],
				'counted_stock' => (float) $inventoryProduct['counted_stock'],
				'inventory_snapshot_stock' => (float) $inventoryProduct['inventory_snapshot_stock'],
				'current_stock' => $currentStock,
				'forecast_required' => $forecastRequired,
				'projected_stock' => $projectedStock,
				'shortage' => $shortage,
				'next_delivery_date' => $nextDelivery !== null ? (string) $nextDelivery['delivery_date'] : null,
				'next_delivery_required' => $nextDeliveryRequired,
				'next_delivery_projected_stock' => $nextDeliveryProjectedStock,
				'next_delivery_shortage' => $nextDeliveryShortage,
				'overall_units_per_euro' => isset($demand['overall_units_per_euro']) ? (float) $demand['overall_units_per_euro'] : 0.0,
				'daily' => isset($demand['daily']) ? $demand['daily'] : array(),
				'deliveries' => isset($deliveryRequirements['deliveries']) ? $deliveryRequirements['deliveries'] : array(),
			);
		}

		usort($products, static function ($left, $right) {
			$shortageOrder = ((float) $right['next_delivery_shortage']) <=> ((float) $left['next_delivery_shortage']);
			if ($shortageOrder !== 0) {
				return $shortageOrder;
			}
			return strnatcasecmp((string) $left['ref'], (string) $right['ref']);
		});

		return array(
			'category' => array(
				'id' => isset($inventory['category_id']) ? (int) $inventory['category_id'] : 0,
				'label' => isset($inventory['category_label']) ? (string) $inventory['category_label'] : 'PATTIES',
			),
			'inventory' => array(
				'id' => (int) $inventory['id'],
				'ref' => (string) $inventory['ref'],
				'date_inventory' => (int) $inventory['date_inventory'],
				'warehouse_id' => (int) $inventory['warehouse_id'],
				'warehouse_ref' => (string) $inventory['warehouse_ref'],
				'snapshot_time' => (string) $inventory['virtual_stock_snapshot_time'],
			),
			'period' => array(
				'history_start' => $statisticsStart,
				'history_end' => $statisticsEnd,
				'history_days' => $historyDays,
				'forecast_days' => isset($salesForecast['period']['forecast_days']) ? (int) $salesForecast['period']['forecast_days'] : count($forecastRows),
			),
			'summary' => array(
				'product_count' => count($products),
				'at_risk_products' => $atRiskProducts,
				'horizon_at_risk_products' => $horizonAtRiskProducts,
				'forecast_sales_ttc' => isset($salesForecast['kpis']['forecast_total_ttc']) ? (float) $salesForecast['kpis']['forecast_total_ttc'] : 0.0,
			),
			'products' => $products,
			'meta' => array(
				'model' => 'sales_scaled_weekday_consumption',
				'safety_stock_included' => 0,
				'planned_receipts_included' => 0,
				'delivery_weekdays' => array(1, 2, 4, 5, 6),
				'no_delivery_weekdays' => array(0, 3),
			),
		);
	}

	/**
	 * Calculate product demand from observed consumption per euro of sales.
	 * The matching weekday rate receives 70 percent weight when at least two
	 * comparable historical days exist; the all-days rate receives the balance.
	 *
	 * @param array<int, array<string, mixed>> $dailyConsumption Product daily movement statistics
	 * @param array<string, float>             $revenueByDate   Historical TTC revenue keyed by date
	 * @param array<int, array<string, mixed>> $forecastRows    Sales forecast rows
	 * @return array<string, mixed>
	 */
	private function calculateStockForecastDemand(array $dailyConsumption, array $revenueByDate, array $forecastRows)
	{
		$overallConsumption = 0.0;
		$overallRevenue = 0.0;
		$weekdayTotals = array();
		for ($weekday = 0; $weekday <= 6; $weekday++) {
			$weekdayTotals[$weekday] = array('consumption' => 0.0, 'revenue' => 0.0, 'days' => 0);
		}

		foreach ($dailyConsumption as $row) {
			$dateValue = isset($row['date']) ? (string) $row['date'] : '';
			if (!$this->isDateYmd($dateValue)) {
				continue;
			}
			$revenue = isset($revenueByDate[$dateValue]) ? max(0.0, (float) $revenueByDate[$dateValue]) : 0.0;
			if ($revenue <= 0.0) {
				continue;
			}
			$consumption = isset($row['consumption']) ? max(0.0, (float) $row['consumption']) : 0.0;
			$weekday = (int) date('w', strtotime($dateValue.' 12:00:00'));
			$overallConsumption += $consumption;
			$overallRevenue += $revenue;
			$weekdayTotals[$weekday]['consumption'] += $consumption;
			$weekdayTotals[$weekday]['revenue'] += $revenue;
			$weekdayTotals[$weekday]['days']++;
		}

		$overallRate = $overallRevenue > 0.0 ? $overallConsumption / $overallRevenue : 0.0;
		$requiredTotal = 0.0;
		$daily = array();
		foreach ($forecastRows as $forecastRow) {
			$dateValue = isset($forecastRow['date']) ? (string) $forecastRow['date'] : '';
			if (!$this->isDateYmd($dateValue)) {
				continue;
			}
			$forecastRevenue = isset($forecastRow['forecast_ttc']) ? max(0.0, (float) $forecastRow['forecast_ttc']) : 0.0;
			$weekday = (int) date('w', strtotime($dateValue.' 12:00:00'));
			$weekdayRate = $overallRate;
			if ($weekdayTotals[$weekday]['days'] >= 2 && $weekdayTotals[$weekday]['revenue'] > 0.0) {
				$matchingWeekdayRate = $weekdayTotals[$weekday]['consumption'] / $weekdayTotals[$weekday]['revenue'];
				$weekdayRate = ($matchingWeekdayRate * 0.7) + ($overallRate * 0.3);
			}
			$requiredQuantity = max(0.0, $forecastRevenue * $weekdayRate);
			$requiredTotal += $requiredQuantity;
			$daily[] = array(
				'date' => $dateValue,
				'forecast_sales_ttc' => $forecastRevenue,
				'units_per_euro' => $weekdayRate,
				'required_quantity' => $requiredQuantity,
			);
		}

		return array(
			'overall_units_per_euro' => $overallRate,
			'forecast_required' => $requiredTotal,
			'daily' => $daily,
		);
	}

	/**
	 * Convert daily demand into delivery-cycle quantities.
	 * Tuesday deliveries also cover Wednesday, and Saturday deliveries cover Sunday.
	 *
	 * @param array<int, array<string, mixed>> $dailyRows Daily product requirements
	 * @return array<string, mixed>
	 */
	private function buildStockDeliveryRequirements(array $dailyRows)
	{
		$deliveries = array();
		$rowCount = count($dailyRows);
		for ($index = 0; $index < $rowCount; $index++) {
			$dateValue = isset($dailyRows[$index]['date']) ? (string) $dailyRows[$index]['date'] : '';
			if (!$this->isDateYmd($dateValue) || !$this->isStockDeliveryDate($dateValue)) {
				continue;
			}

			$coveredDates = array($dateValue);
			$requiredQuantity = isset($dailyRows[$index]['required_quantity'])
				? max(0.0, (float) $dailyRows[$index]['required_quantity'])
				: 0.0;
			for ($nextIndex = $index + 1; $nextIndex < $rowCount; $nextIndex++) {
				$coveredDate = isset($dailyRows[$nextIndex]['date']) ? (string) $dailyRows[$nextIndex]['date'] : '';
				if (!$this->isDateYmd($coveredDate) || $this->isStockDeliveryDate($coveredDate)) {
					break;
				}
				$coveredDates[] = $coveredDate;
				$requiredQuantity += isset($dailyRows[$nextIndex]['required_quantity'])
					? max(0.0, (float) $dailyRows[$nextIndex]['required_quantity'])
					: 0.0;
			}

			$deliveries[] = array(
				'delivery_date' => $dateValue,
				'covered_dates' => $coveredDates,
				'required_quantity' => $requiredQuantity,
			);
		}

		return array(
			'deliveries' => $deliveries,
			'next_delivery' => !empty($deliveries) ? $deliveries[0] : null,
		);
	}

	/**
	 * Check whether a date is an operational delivery day.
	 *
	 * @param string $dateValue Date in Y-m-d format
	 * @return bool
	 */
	private function isStockDeliveryDate($dateValue)
	{
		$weekday = (int) date('w', strtotime((string) $dateValue.' 12:00:00'));
		return $weekday !== 0 && $weekday !== 3;
	}

	/**
	 * Load current physical stock for inventory products in one entity-owned warehouse.
	 *
	 * @param int[] $productIds Product IDs
	 * @param int   $warehouseId Warehouse ID
	 * @return array<int, float>
	 */
	private function fetchWarehouseStockByProduct(array $productIds, $warehouseId)
	{
		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
		$warehouseId = (int) $warehouseId;
		if (empty($productIds) || $warehouseId <= 0) {
			return array();
		}

		$sql = 'SELECT ps.fk_product, SUM(ps.reel) as current_stock';
		$sql .= ' FROM '.$this->db->prefix().'product_stock as ps';
		$sql .= ' INNER JOIN '.$this->db->prefix().'product as p ON p.rowid = ps.fk_product';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= ' INNER JOIN '.$this->db->prefix().'entrepot as e ON e.rowid = ps.fk_entrepot';
		$sql .= ' AND e.entity IN ('.getEntity('stock').')';
		$sql .= ' WHERE ps.fk_entrepot = '.$warehouseId;
		$sql .= ' AND ps.fk_product IN ('.implode(',', $productIds).')';
		$sql .= ' GROUP BY ps.fk_product';

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaFlowApiException($this->db->lasterror(), 500);
		}

		$stockByProduct = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$stockByProduct[(int) $obj->fk_product] = (float) $obj->current_stock;
		}
		$this->db->free($resql);

		return $stockByProduct;
	}

	/**
	 * Read-only workforce board payload based on DolizSynch schedule and punch-clock data.
	 *
	 * @param string $weekStartDate
	 * @param string $dayDate
	 * @param string $punchDate
	 * @return array<string, mixed>
	 */
	public function getWorkforceOverview($weekStartDate, $dayDate, $punchDate)
	{
		if (!isModEnabled('dolizsynch')) {
			throw new KreaFlowApiException('KreaFlow synchronization is not enabled.', 403);
		}

		$canReadSchedule = $this->canReadWorkforceScheduleMenu() && !empty($this->user->rights->dolizsynch->shift->read);
		$canReadPunchClock = $this->canReadWorkforcePunchMenu() && !empty($this->user->rights->dolizsynch->zspunchclock->read);
		if (!$canReadSchedule && !$canReadPunchClock) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$this->loadDolizSynchShiftPlannerLibrary();

		$weekStart = $this->normalizeWeekStartDate($weekStartDate);
		$day = $this->normalizeDateWithFallback($dayDate, 0);
		$punchDay = $this->normalizeDateWithFallback($punchDate, 0);
		if (!$this->canReadBeyondHistoryLimit()) {
			$earliestAllowedDate = $this->getHistoryEarliestDate();
			if ($weekStart < $earliestAllowedDate) {
				$weekStart = $earliestAllowedDate;
			}
			if ($day < $earliestAllowedDate) {
				$day = $earliestAllowedDate;
			}
			if ($punchDay < $earliestAllowedDate) {
				$punchDay = $earliestAllowedDate;
			}
		}
		$weekEnd = date('Y-m-d', strtotime($weekStart.' +6 days'));
		$dayWindow = $this->getShiftDayWindow();
		$includeForeign = 1;
		if (isset($this->conf->global->DOLIZSYNCH_SHIFT_INCLUDE_FOREIGN)) {
			$includeForeign = ((int) $this->conf->global->DOLIZSYNCH_SHIFT_INCLUDE_FOREIGN) === 0 ? 0 : 1;
		}

		$resources = $canReadSchedule ? $this->fetchWorkforceResources($weekStart, $weekEnd) : array();
		$events = $canReadSchedule ? $this->fetchWorkforceEvents($weekStart, $weekEnd, $resources, $includeForeign) : array();
		$availability = $canReadSchedule
			? $this->fetchWorkforceAvailability($weekStart, $weekEnd, $resources, $includeForeign)
			: array(
				'dayoffs' => array(),
				'leaves' => array(),
				'public_holidays' => array(),
			);
		$punchRows = $canReadPunchClock ? $this->fetchWorkforcePunchRows($punchDay) : array();

		return array(
			'period' => array(
				'week_start' => $weekStart,
				'week_end' => $weekEnd,
				'day' => $day,
				'punch_date' => $punchDay,
				'day_start_minutes' => (int) $dayWindow['start_minutes'],
				'day_end_minutes' => (int) $dayWindow['end_minutes'],
			),
			'permissions' => array(
				'schedule_read' => $canReadSchedule ? 1 : 0,
				'punch_read' => $canReadPunchClock ? 1 : 0,
			),
			'resources' => $resources,
			'events' => $events,
			'punch_rows' => $punchRows,
			'dayoffs' => isset($availability['dayoffs']) && is_array($availability['dayoffs']) ? $availability['dayoffs'] : array(),
			'leaves' => isset($availability['leaves']) && is_array($availability['leaves']) ? $availability['leaves'] : array(),
			'public_holidays' => isset($availability['public_holidays']) && is_array($availability['public_holidays']) ? $availability['public_holidays'] : array(),
		);
	}

	/**
	 * List financial closings inspired by degema/fechofinanceiro_list.php.
	 *
	 * @param string $startDate
	 * @param string $endDate
	 * @param int $status
	 * @param string $search
	 * @param int $limit
	 * @return array<string, mixed>
	 */
	public function listFechoFinanceiro($startDate, $endDate, $status, $search, $limit = 80)
	{
		$this->ensureFechoReadAccess();

		$range = $this->normalizeDateRange($startDate, $endDate, !empty($this->user->rights->degema->fechofinanceiro->viewalldates));
		$status = (int) $status;
		$limit = $this->sanitizeLimit($limit);
		$search = trim((string) $search);

		$whereParts = array();
		$whereParts[] = "t.entity = ".((int) $this->conf->entity);

		$dateLimitYmd = '';
		if (empty($this->user->rights->degema->fechofinanceiro->viewalldates) && !$this->canReadBeyondHistoryLimit()) {
			$limitDays = $this->getGlobalInt('DEGEMA_FECHOFINANCEIRO_DATE_LIMIT_DAYS', 3);
			$limitTimestamp = dol_now() - ($limitDays * 24 * 60 * 60);
			$dateLimitYmd = dol_print_date($limitTimestamp, '%Y-%m-%d');
			$whereParts[] = "t.datecx >= '".$this->db->escape($dateLimitYmd)."'";

			if ($range['start_date'] < $dateLimitYmd) {
				$range['start_date'] = $dateLimitYmd;
			}
			if ($range['end_date'] < $range['start_date']) {
				$range['end_date'] = $range['start_date'];
			}
		}

		$whereParts[] = "t.datecx >= '".$this->db->escape($range['start_date'])."'";
		$whereParts[] = "t.datecx <= '".$this->db->escape($range['end_date'])."'";

		if (in_array($status, array(0, 1, 9), true)) {
			$whereParts[] = "t.status = ".$status;
		}

		if ($search !== '') {
			$tokens = $this->extractSearchTokens($search);
			if (empty($tokens)) {
				$tokens = array($search);
			}

			foreach ($tokens as $token) {
				$tokenLike = '%'.$this->db->escape($token).'%';
				$tokenClause = "(UPPER(t.ref) LIKE UPPER('".$tokenLike."')";
				$tokenClause .= " OR UPPER(COALESCE(el.label, '')) LIKE UPPER('".$tokenLike."')";
				$tokenClause .= " OR UPPER(TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')))) LIKE UPPER('".$tokenLike."')";
				$tokenClause .= " OR UPPER(COALESCE(u.login, '')) LIKE UPPER('".$tokenLike."'))";
				$whereParts[] = $tokenClause;
			}
		}

		$whereSql = implode(' AND ', $whereParts);

		$fromSql = " FROM ".$this->db->prefix()."degema_fechofinanceiro as t";
		$fromSql .= " LEFT JOIN ".$this->db->prefix()."entity as el ON el.rowid = t.fk_loja";
		$fromSql .= " LEFT JOIN ".$this->db->prefix()."user as u ON u.rowid = t.responsavel";

		$sqlCount = "SELECT COUNT(t.rowid) as total_count";
		$sqlCount .= ", SUM(COALESCE(t.total_sem_iva, 0)) as total_sem_iva";
		$sqlCount .= ", SUM(COALESCE(t.val_talao_caixa, 0)) as total_talao_caixa";
		$sqlCount .= $fromSql;
		$sqlCount .= " WHERE ".$whereSql;

		$countRows = $this->fetchRows($sqlCount);
		$countRow = !empty($countRows) ? $countRows[0] : array();
		$totalCount = isset($countRow['total_count']) ? (int) $countRow['total_count'] : 0;

		$sql = "SELECT t.rowid as id";
		$sql .= ", t.ref";
		$sql .= ", t.fk_loja";
		$sql .= ", t.datecx";
		$sql .= ", t.responsavel";
		$sql .= ", t.total_sem_iva";
		$sql .= ", t.val_talao_caixa";
		$sql .= ", t.status";
		$sql .= ", COALESCE(NULLIF(TRIM(el.label), ''), CONCAT('Entity ', t.fk_loja)) as store_label";
		$sql .= ", COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))), ''), u.login, 'Sem utilizador') as responsavel_label";
		$sql .= $fromSql;
		$sql .= " WHERE ".$whereSql;
		$sql .= " ORDER BY t.datecx DESC, t.rowid DESC";
		$sql .= $this->db->plimit($limit, 0);

		$rows = $this->fetchRows($sql);
		$list = array();
		foreach ($rows as $row) {
			$statusValue = isset($row['status']) ? (int) $row['status'] : 0;
			$list[] = array(
				'id' => (int) $row['id'],
				'ref' => isset($row['ref']) ? (string) $row['ref'] : '',
				'datecx' => isset($row['datecx']) ? dol_print_date(dol_stringtotime($row['datecx']), '%Y-%m-%d') : '',
				'store_id' => isset($row['fk_loja']) ? (int) $row['fk_loja'] : 0,
				'store_label' => isset($row['store_label']) ? (string) $row['store_label'] : '',
				'responsavel_id' => isset($row['responsavel']) ? (int) $row['responsavel'] : 0,
				'responsavel_label' => isset($row['responsavel_label']) ? (string) $row['responsavel_label'] : '',
				'total_sem_iva' => isset($row['total_sem_iva']) ? (float) price2num($row['total_sem_iva']) : 0.0,
				'val_talao_caixa' => isset($row['val_talao_caixa']) ? (float) price2num($row['val_talao_caixa']) : 0.0,
				'status' => $statusValue,
				'status_label' => $this->getFechoStatusLabel($statusValue),
			);
		}

		return array(
			'period' => array(
				'date_start' => $range['start_date'],
				'date_end' => $range['end_date'],
				'limit_date' => $dateLimitYmd,
			),
			'count' => $totalCount,
			'totals' => array(
				'total_sem_iva' => isset($countRow['total_sem_iva']) ? (float) price2num($countRow['total_sem_iva']) : 0.0,
				'total_talao_caixa' => isset($countRow['total_talao_caixa']) ? (float) price2num($countRow['total_talao_caixa']) : 0.0,
			),
			'rows' => $list,
		);
	}

	/**
	 * Return metadata required by Fecho Financeiro create/edit forms.
	 *
	 * @return array<string, mixed>
	 */
	public function getFechoFinanceiroMeta()
	{
		$this->ensureFechoReadAccess();

		$stores = $this->getFechoStores();
		$users = $this->getFechoUsers();
		$currentEntity = (int) $this->conf->entity;
		$defaultUserId = 0;
		foreach ($users as $userRow) {
			if ((int) $userRow['id'] === (int) $this->user->id) {
				$defaultUserId = (int) $userRow['id'];
				break;
			}
		}
		if ($defaultUserId <= 0 && !empty($users)) {
			$defaultUserId = (int) $users[0]['id'];
		}

		return array(
			'permissions' => array(
				'can_create' => $this->canCreateFechoFinanceiro() ? 1 : 0,
				'can_update' => $this->canUpdateFechoFinanceiro() ? 1 : 0,
				'can_update_validated' => $this->canUpdateValidatedFechoFinanceiro() ? 1 : 0,
				'can_update_old' => $this->canUpdateOldFechoFinanceiro() ? 1 : 0,
				'edit_limit_days' => $this->getFechoEditLimitDays(),
				'can_write' => ($this->canCreateFechoFinanceiro() || $this->canUpdateFechoFinanceiro()) ? 1 : 0,
				'can_change_store' => $this->canChangeFechoStore() ? 1 : 0,
			),
			'defaults' => array(
				'datecx' => date('Y-m-d'),
				'fk_loja' => $currentEntity,
				'responsavel' => $defaultUserId,
			),
			'stores' => $stores,
			'users' => $users,
		);
	}

	/**
	 * Return full detail payload for one Fecho Financeiro record.
	 *
	 * @param int $id
	 * @return array<string, mixed>
	 */
	public function getFechoFinanceiroDetail($id)
	{
		$this->ensureFechoReadAccess();
		$id = (int) $id;
		if ($id <= 0) {
			throw new KreaFlowApiException('Fecho id is required.', 400);
		}

		$row = $this->fetchFechoRowById($id);
		if (empty($row)) {
			throw new KreaFlowApiException('Fecho not found.', 404);
		}
		$rowDate = isset($row['datecx']) ? dol_print_date(dol_stringtotime($row['datecx']), '%Y-%m-%d') : '';
		if (!$this->isHistoryDateAllowed($rowDate, !empty($this->user->rights->degema->fechofinanceiro->viewalldates))) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		return $this->buildFechoDetailFromRow($row);
	}

	/**
	 * Create a Fecho Financeiro in draft status.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function createFechoFinanceiro(array $payload)
	{
		$this->ensureFechoReadAccess();
		if (!$this->canCreateFechoFinanceiro()) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$this->ensureFechoClassLoaded();
		$this->ensureFechoValidationLibraryLoaded();

		$normalized = $this->normalizeFechoPayload($payload);
		$validation = degemaFechoFinanceiroValidatePostedTotals($normalized['values']);
		if (empty($validation['is_valid'])) {
			throw new KreaFlowApiException('Financial consistency validation failed.', 400);
		}

		$fecho = new FechoFinanceiro($this->db);
		$fecho->entity = (int) $this->conf->entity;
		$fecho->status = FechoFinanceiro::STATUS_DRAFT;
		$fecho->ref = '(PROV)';
		$fecho->datecx = dol_stringtotime($normalized['datecx'].' 00:00:00');
		$fecho->fk_loja = (int) $normalized['fk_loja'];
		$fecho->responsavel = (int) $normalized['responsavel'];
		$fecho->description = (string) $normalized['description'];

		foreach ($this->getFechoNumericFieldNames() as $field) {
			$fecho->{$field} = isset($normalized['values'][$field]) ? (float) $normalized['values'][$field] : 0.0;
		}
		$fecho->total_sem_iva = (float) $validation['metrics']['total_sem_iva_calc'];

		$result = $fecho->create($this->user);
		if ($result <= 0) {
			$errorMessage = !empty($fecho->error) ? $fecho->error : $this->db->lasterror();
			if ($errorMessage === '') {
				$errorMessage = 'Failed to create Fecho Financeiro.';
			}
			throw new KreaFlowApiException($errorMessage, 500);
		}

		return $this->getFechoFinanceiroDetail((int) $result);
	}

	/**
	 * Update a Fecho Financeiro record when status and age rules allow editing.
	 *
	 * @param int $id
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function updateFechoFinanceiro($id, array $payload)
	{
		$this->ensureFechoReadAccess();
		if (!$this->canUpdateFechoFinanceiro()) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$id = (int) $id;
		if ($id <= 0) {
			throw new KreaFlowApiException('Fecho id is required.', 400);
		}

		$this->ensureFechoClassLoaded();
		$this->ensureFechoValidationLibraryLoaded();

		$fecho = new FechoFinanceiro($this->db);
		$fetchResult = $fecho->fetch($id);
		if ($fetchResult <= 0) {
			throw new KreaFlowApiException('Fecho not found.', 404);
		}
		if ((int) $fecho->entity !== (int) $this->conf->entity) {
			throw new KreaFlowApiException('Fecho not found.', 404);
		}
		$fechoDate = '';
		if (!empty($fecho->datecx)) {
			$fechoDate = dol_print_date((int) $fecho->datecx, '%Y-%m-%d');
		}
		if (!$this->isHistoryDateAllowed($fechoDate, !empty($this->user->rights->degema->fechofinanceiro->viewalldates))) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}
		$status = (int) $fecho->status;
		if (!$this->canUpdateFechoStatus($status)) {
			if ($status === 1) {
				throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
			}
			throw new KreaFlowApiException('Only draft or validated Fecho records can be edited.', 409);
		}
		if (!$this->isFechoDateWithinEditLimit($fechoDate)) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$normalized = $this->normalizeFechoPayload($payload);
		$validation = degemaFechoFinanceiroValidatePostedTotals($normalized['values']);
		if (empty($validation['is_valid'])) {
			throw new KreaFlowApiException('Financial consistency validation failed.', 400);
		}

		$fecho->datecx = dol_stringtotime($normalized['datecx'].' 00:00:00');
		$fecho->fk_loja = (int) $normalized['fk_loja'];
		$fecho->responsavel = (int) $normalized['responsavel'];
		$fecho->description = (string) $normalized['description'];

		foreach ($this->getFechoNumericFieldNames() as $field) {
			$fecho->{$field} = isset($normalized['values'][$field]) ? (float) $normalized['values'][$field] : 0.0;
		}
		$fecho->total_sem_iva = (float) $validation['metrics']['total_sem_iva_calc'];

		$result = $fecho->update($this->user);
		if ($result <= 0) {
			$errorMessage = !empty($fecho->error) ? $fecho->error : $this->db->lasterror();
			if ($errorMessage === '') {
				$errorMessage = 'Failed to update Fecho Financeiro.';
			}
			throw new KreaFlowApiException($errorMessage, 500);
		}

		return $this->getFechoFinanceiroDetail($id);
	}

	/**
	 * @return void
	 */
	private function ensureApiContext()
	{
		$this->ensureModuleEnabled();

		if (empty($this->user->id)) {
			throw new KreaFlowApiException($this->langs->trans('ErrorNotLogged'), 401);
		}

		if (!empty($this->user->socid)) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}
	}

	/**
	 * @return void
	 */
	private function ensureModuleEnabled()
	{
		if (!isModEnabled('kreaflow')) {
			throw new KreaFlowApiException($this->langs->trans('ErrorModuleNotEnabled', 'KreaFlow'), 403);
		}
	}

	/**
	 * @param string $moduleName
	 * @param int $entityId
	 * @return bool
	 */
	private function isModuleEnabledForEntity($moduleName, $entityId)
	{
		$moduleName = strtoupper(trim((string) $moduleName));
		$entityId = (int) $entityId;
		if ($moduleName === '' || $entityId <= 0) {
			return false;
		}
		if (!$this->tableExists('const')) {
			return isModEnabled(strtolower($moduleName));
		}

		$constName = 'MAIN_MODULE_'.$moduleName;
		$sql = "SELECT c.value as value";
		$sql .= " FROM ".$this->db->prefix()."const as c";
		$sql .= " WHERE c.name = '".$this->db->escape($constName)."'";
		$sql .= " AND c.entity = ".((int) $entityId);
		$sql .= " ORDER BY c.rowid DESC";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj || !isset($obj->value)) {
			return false;
		}

		$value = strtoupper(trim((string) $obj->value));
		return in_array($value, array('1', 'TRUE', 'ON', 'YES'), true);
	}

	/**
	 * @param string $code
	 * @return string
	 */
	private function normalizeTotpCode($code)
	{
		$code = preg_replace('/\D+/', '', (string) $code);
		if (!is_string($code)) {
			return '';
		}

		return substr($code, 0, 6);
	}

	/**
	 * Make the JSON-submitted TOTP code visible to Dolibarr authentication providers.
	 *
	 * Krea2FactorAuth intentionally reads TOTP fields through GETPOST(), like the
	 * standard Dolibarr login form. KreaFlow posts JSON, so the value must be
	 * mirrored into request scope before checkLoginPassEntity() is called.
	 *
	 * @param string $code
	 * @return void
	 */
	private function prepareTotpCodeForAuthProvider($code)
	{
		$code = $this->normalizeTotpCode($code);
		$digits = str_split($code);

		$_POST['totp_code'] = $code;
		$_REQUEST['totp_code'] = $code;
		$_POST['totp_digit'] = $digits;
		$_REQUEST['totp_digit'] = $digits;
	}

	/**
	 * @return object|null
	 */
	private function getKrea2FactorAuthManager()
	{
		if (!function_exists('dol_include_once')) {
			return null;
		}

		if (!class_exists('Krea2FactorAuthManager')) {
			dol_include_once('/krea2factorauth/class/krea2factorauthmanager.class.php');
		}
		if (!class_exists('Krea2FactorAuthManager')) {
			return null;
		}

		return new Krea2FactorAuthManager($this->db);
	}

	/**
	 * @param object|null $manager
	 * @param int $entityId
	 * @return array<string, mixed>
	 */
	private function buildKrea2FactorAuthEntityLoginMeta($manager, $entityId)
	{
		$entityId = (int) $entityId;
		$enabled = 0;
		$logoUrl = '';
		$backgroundColor = '';

		if (is_object($manager) && method_exists($manager, 'isModuleActiveForEntity') && $manager->isModuleActiveForEntity($entityId)) {
			$enabled = 1;
			if (method_exists($manager, 'getLoginLogoUrl')) {
				$logoUrl = (string) $manager->getLoginLogoUrl($entityId);
			}
			if (method_exists($manager, 'getLoginBackgroundColorForEntity')) {
				$backgroundColor = (string) $manager->getLoginBackgroundColorForEntity($entityId);
			}
		}
		$googleLoginEnabled = 0;
		$googleLoginUrl = '';
		if ($enabled
			&& is_object($manager)
			&& method_exists($manager, 'isGoogleLoginEnabled')
			&& method_exists($manager, 'isGoogleOAuthConfigured')
			&& $manager->isGoogleLoginEnabled()
			&& $manager->isGoogleOAuthConfigured()
		) {
			$googleLoginEnabled = 1;
			$googleLoginUrl = dol_buildpath('/kreaflow/mobile.php', 1).'?kf_google_login=1';
		}

		return array(
			'enabled' => $enabled,
			'login_logo_url' => $logoUrl,
			'login_background_color' => $backgroundColor,
			'google_login_enabled' => $googleLoginEnabled,
			'google_login_url' => $googleLoginUrl,
		);
	}

	/**
	 * Initialize a Dolibarr web session for an authenticated internal user.
	 *
	 * @param User $authenticatedUser
	 * @param int $entity
	 * @param string $authMode
	 * @param string $auditLabel
	 * @return array<string, mixed>
	 */
	private function initializeAuthenticatedSession(User $authenticatedUser, $entity, $authMode, $auditLabel)
	{
		$entity = (int) $entity;
		$authMode = trim((string) $authMode);
		if ($authMode === '') {
			$authMode = 'dolibarr';
		}

		$authenticatedUser->loadRights();

		$this->user = $authenticatedUser;
		$this->conf->entity = $entity;

		if (!$this->user->hasRight('kreaflow', 'mobile', 'read')) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$_SESSION['dol_login'] = $this->user->login;
		$_SESSION['dol_logindate'] = dol_now('gmt');
		$_SESSION['dol_authmode'] = $authMode;
		$_SESSION['dol_company'] = getDolGlobalString('MAIN_INFO_SOCIETE_NOM');
		$_SESSION['dol_entity'] = $entity;

		global $user;
		$user = $this->user;

		$this->db->begin();
		$error = 0;
		if ($this->user->update_last_login_date() < 0) {
			$error++;
		}

		$this->user->context['audit'] = $auditLabel.' - authmode='.$authMode.' - entity='.$entity;
		$this->user->context['authentication_method'] = $authMode;
		$triggerResult = $this->user->call_trigger('USER_LOGIN', $this->user);
		if ($triggerResult < 0) {
			$error++;
		}

		if ($error > 0) {
			$this->db->rollback();
			throw new KreaFlowApiException('Failed to initialize login session.', 500);
		}

		$this->db->commit();

		return $this->getWhoAmI();
	}

	/**
	 * @return array<int, string>
	 */
	private function resolveAuthModes()
	{
		global $dolibarr_main_authentication, $dolibarr_auto_user;

		if (defined('MAIN_AUTHENTICATION_MODE')) {
			$dolibarrMainAuthentication = (string) constant('MAIN_AUTHENTICATION_MODE');
		} else {
			$dolibarrMainAuthentication = (string) $dolibarr_main_authentication;
			if ($dolibarrMainAuthentication === '') {
				$dolibarrMainAuthentication = 'dolibarr';
			}
			if ($dolibarrMainAuthentication === 'forceuser' && empty($dolibarr_auto_user)) {
				$dolibarr_auto_user = 'auto';
			}
		}

		$authModes = explode(',', $dolibarrMainAuthentication);

		return array_values(array_filter(array_map('trim', $authModes), static function ($mode) {
			return $mode !== '';
		}));
	}

	/**
	 * @param int $entityId
	 * @return int
	 */
	private function reloadSessionUser($entityId)
	{
		$entityId = (int) $entityId;
		$login = !empty($_SESSION['dol_login']) ? (string) $_SESSION['dol_login'] : (string) $this->user->login;
		if ($login === '') {
			return -1;
		}

		$reloadedUser = new User($this->db);
		$fetchResult = $reloadedUser->fetch(0, $login, '', 1, ($entityId > 0 ? $entityId : -1));
		if ($fetchResult <= 0 || !empty($reloadedUser->socid)) {
			return -1;
		}

		$this->user = $reloadedUser;
		$this->conf->entity = $entityId;
		$_SESSION['dol_entity'] = $entityId;

		global $user;
		$user = $this->user;

		return 1;
	}

	/**
	 * @param int $entityId
	 * @return string
	 */
	private function getEntityLabelById($entityId)
	{
		$entityId = (int) $entityId;
		if ($entityId <= 0) {
			return '';
		}

		$sql = "SELECT label FROM ".$this->db->prefix()."entity";
		$sql .= " WHERE rowid = ".$entityId;
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return '';
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!is_object($obj) || !isset($obj->label)) {
			return '';
		}

		return trim((string) $obj->label);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function getAccessibleEntities()
	{
		$currentEntityId = (int) $this->conf->entity;
		$currentEntityLabel = $this->getEntityLabelById($currentEntityId);
		$entitiesById = array();

		if (isModEnabled('multicompany')) {
			dol_include_once('/multicompany/class/actions_multicompany.class.php', 'ActionsMulticompany');
			dol_include_once('/multicompany/class/dao_multicompany.class.php', 'DaoMulticompany');

			if (class_exists('ActionsMulticompany') && class_exists('DaoMulticompany')) {
				$actionsMulticompany = new ActionsMulticompany($this->db);
				$daoMulticompany = new DaoMulticompany($this->db);
				$fetchEntitiesResult = $daoMulticompany->getEntities(false, false, true, true, false);

				if ($fetchEntitiesResult > 0 && is_array($daoMulticompany->entities)) {
					foreach ($daoMulticompany->entities as $entityObject) {
						if (!is_object($entityObject) || empty($entityObject->id)) {
							continue;
						}

						$entityId = (int) $entityObject->id;
						$isCurrentEntity = ($entityId === $currentEntityId);
						$canAccess = $isCurrentEntity ? 1 : (int) $actionsMulticompany->checkRight((int) $this->user->id, $entityId);
						if ($canAccess <= 0) {
							continue;
						}

						$label = trim((string) $entityObject->label);
						if ($label === '') {
							$label = 'Entity '.$entityId;
						}

						$entitiesById[$entityId] = array(
							'id' => $entityId,
							'label' => $label,
							'current' => $isCurrentEntity ? 1 : 0,
						);
					}
				}
			}
		}

		if (!isset($entitiesById[$currentEntityId])) {
			$entitiesById[$currentEntityId] = array(
				'id' => $currentEntityId,
				'label' => $currentEntityLabel !== '' ? $currentEntityLabel : 'Entity '.$currentEntityId,
				'current' => 1,
			);
		} else {
			$entitiesById[$currentEntityId]['current'] = 1;
		}

		$enabledEntityIds = $this->getKreaFlowEnabledEntityIds(array_keys($entitiesById));
		$enabledLookup = array();
		foreach ($enabledEntityIds as $enabledEntityId) {
			$enabledLookup[(int) $enabledEntityId] = true;
		}

		foreach (array_keys($entitiesById) as $entityId) {
			if (!isset($enabledLookup[(int) $entityId])) {
				unset($entitiesById[(int) $entityId]);
			}
		}

		if (empty($entitiesById)) {
			$entitiesById[$currentEntityId] = array(
				'id' => $currentEntityId,
				'label' => $currentEntityLabel !== '' ? $currentEntityLabel : 'Entity '.$currentEntityId,
				'current' => 1,
			);
		}

		ksort($entitiesById, SORT_NUMERIC);

		return array_values($entitiesById);
	}

	/**
	 * @param array<int, int|string> $entityIds
	 * @return array<int, int>
	 */
	private function getKreaFlowEnabledEntityIds(array $entityIds)
	{
		$sanitized = array();
		foreach ($entityIds as $entityId) {
			$entityId = (int) $entityId;
			if ($entityId <= 0 || isset($sanitized[$entityId])) {
				continue;
			}
			$sanitized[$entityId] = $entityId;
		}
		if (empty($sanitized)) {
			return array();
		}

		$sql = "SELECT c.entity";
		$sql .= " FROM ".$this->db->prefix()."const as c";
		$sql .= " WHERE c.name = 'MAIN_MODULE_KREAFLOW'";
		$sql .= " AND c.entity IN (".implode(',', array_map('intval', array_values($sanitized))).")";
		$sql .= " AND (c.value = '1' OR UPPER(c.value) = 'TRUE')";
		$sql .= " GROUP BY c.entity";

		$rows = $this->fetchRowsOptional($sql);
		$enabled = array();
		foreach ($rows as $row) {
			$entityId = isset($row['entity']) ? (int) $row['entity'] : 0;
			if ($entityId <= 0 || isset($enabled[$entityId])) {
				continue;
			}
			$enabled[$entityId] = $entityId;
		}

		return array_values($enabled);
	}

	/**
	 * @param array<int, array<string, mixed>> $entities
	 * @param int $currentEntityId
	 * @return string
	 */
	private function resolveCurrentEntityLabel(array $entities, $currentEntityId)
	{
		foreach ($entities as $entity) {
			if ((int) $entity['id'] === (int) $currentEntityId) {
				return (string) $entity['label'];
			}
		}

		$fallback = $this->getEntityLabelById($currentEntityId);
		if ($fallback !== '') {
			return $fallback;
		}

		return 'Entity '.(int) $currentEntityId;
	}

	/**
	 * @return array<int, int>
	 */
	private function getConfiguredProductCategoryIds()
	{
		$rawValue = trim((string) getDolGlobalString('KREAFLOW_PRODUCTLIST_CATEGORIES'));
		if ($rawValue === '') {
			return array();
		}

		$parts = explode(',', $rawValue);
		$ids = array();
		foreach ($parts as $part) {
			$id = (int) trim((string) $part);
			if ($id <= 0 || isset($ids[$id])) {
				continue;
			}
			$ids[$id] = $id;
		}

		return array_values($ids);
	}

	/**
	 * @return bool
	 */
	private function mustFilterProductsToSell()
	{
		$rawValue = getDolGlobalString('KREAFLOW_PRODUCTLIST_FILTER_TOSELL');
		if ($rawValue === '') {
			return true;
		}

		return ((int) $rawValue) === 1;
	}

	/**
	 * @param int $limit
	 * @return int
	 */
	private function sanitizeLimit($limit)
	{
		$limit = (int) $limit;
		if ($limit <= 0) {
			$limit = 30;
		}
		if ($limit > 200) {
			$limit = 200;
		}

		return $limit;
	}

	/**
	 * @param int $page
	 * @return int
	 */
	private function sanitizePositivePage($page)
	{
		$page = (int) $page;
		if ($page <= 0) {
			$page = 1;
		}
		if ($page > 100000) {
			$page = 100000;
		}

		return $page;
	}

	/**
	 * @param string $key
	 * @param int $default
	 * @return int
	 */
	private function getGlobalInt($key, $default = 0)
	{
		$key = (string) $key;
		$default = (int) $default;
		if ($key === '' || empty($this->conf->global) || !isset($this->conf->global->{$key})) {
			return $default;
		}

		$value = (int) $this->conf->global->{$key};
		if ($value <= 0) {
			return $default;
		}

		return $value;
	}

	/**
	 * @return void
	 */
	private function loadDolizSynchShiftPlannerLibrary()
	{
		if (function_exists('shiftplanner_get_day_window')) {
			return;
		}

		$libraryPath = DOL_DOCUMENT_ROOT.'/custom/kreaflow/lib/shiftplanner.lib.php';
		if (is_readable($libraryPath)) {
			require_once $libraryPath;
		}
	}

	/**
	 * @param string $dateValue
	 * @return string
	 */
	private function normalizeWeekStartDate($dateValue)
	{
		$dateValue = trim((string) $dateValue);
		if (function_exists('shiftplanner_resolve_week_start_date')) {
			return (string) shiftplanner_resolve_week_start_date($dateValue);
		}

		$resolved = $this->normalizeDateWithFallback($dateValue, 0);
		$resolvedTs = strtotime($resolved.' 00:00:00');
		if ($resolvedTs === false) {
			$resolvedTs = dol_now();
		}

		$weekDay = (int) date('w', $resolvedTs); // 0=Sunday ... 6=Saturday
		$offset = ($weekDay + 6) % 7; // Monday-based offset
		if ($offset > 0) {
			$resolvedTs = strtotime('-'.$offset.' days', $resolvedTs);
		}

		return date('Y-m-d', $resolvedTs);
	}

	/**
	 * @param string $dateValue
	 * @param int $fallbackDaysOffset
	 * @return string
	 */
	private function normalizeDateWithFallback($dateValue, $fallbackDaysOffset = -1)
	{
		$dateValue = trim((string) $dateValue);
		if ($this->isDateYmd($dateValue)) {
			return $dateValue;
		}

		$fallbackTs = strtotime(((int) $fallbackDaysOffset).' day');
		if ($fallbackTs === false) {
			$fallbackTs = dol_now();
		}

		return date('Y-m-d', $fallbackTs);
	}

	/**
	 * @return array<int, int>
	 */
	private function getShiftEmployeeGroupIds()
	{
		$raw = trim((string) getDolGlobalString('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS', ''));
		$ids = array();
		if ($raw !== '') {
			$parts = preg_split('/\s*,\s*/', $raw);
			if (is_array($parts)) {
				foreach ($parts as $part) {
					$id = (int) $part;
					if ($id > 0) {
						$ids[$id] = $id;
					}
				}
			}
		}
		if (empty($ids)) {
			$single = (int) getDolGlobalInt('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUP', 0);
			if ($single > 0) {
				$ids[$single] = $single;
			}
		}

		return array_values($ids);
	}

	/**
	 * @return array<string, int|string>
	 */
	private function getShiftDayWindow()
	{
		$window = array(
			'start_minutes' => 7 * 60,
			'end_minutes' => 24 * 60,
		);

		if (function_exists('shiftplanner_get_day_window')) {
			$resolved = shiftplanner_get_day_window();
			if (is_array($resolved)) {
				if (isset($resolved['start_minutes'])) {
					$window['start_minutes'] = (int) $resolved['start_minutes'];
				}
				if (isset($resolved['end_minutes'])) {
					$window['end_minutes'] = (int) $resolved['end_minutes'];
				}
			}
		}

		if ((int) $window['end_minutes'] <= (int) $window['start_minutes']) {
			$window['end_minutes'] = (int) $window['end_minutes'] + (24 * 60);
		}

		return $window;
	}

	/**
	 * @param string $weekStart
	 * @param string $weekEnd
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchWorkforceResources($weekStart, $weekEnd)
	{
		if (!$this->tableExists('user') || !$this->tableExists('usergroup_user')) {
			return array();
		}

		$groupIds = $this->getShiftEmployeeGroupIds();
		$groupIdSql = '';
		if (!empty($groupIds)) {
			$groupIdSql = implode(',', array_map('intval', $groupIds));
		}
		$hasUserExtrafields = $this->tableExists('user_extrafields');
		$hasHideFlag = $hasUserExtrafields && $this->tableColumnExists('user_extrafields', 'zs_user_hide');
		$hasHorasEfect = $hasUserExtrafields && $this->tableColumnExists('user_extrafields', 'horasefect');
		$hasMainTask = $hasUserExtrafields && $this->tableColumnExists('user_extrafields', 'zs_main_task');
		$hasTaskTable = $hasMainTask && $this->tableExists('dolizsynch_shift_task');

		$sql = "SELECT DISTINCT u.rowid";
		$sql .= ", u.firstname";
		$sql .= ", u.lastname";
		$sql .= ", u.login";
		$sql .= ", u.weeklyhours";
		$sql .= ", u.dateemployment";
		$sql .= ", u.dateemploymentend";
		$sql .= $hasHorasEfect ? ", ue.horasefect" : ", NULL as horasefect";
		$sql .= $hasMainTask ? ", ue.zs_main_task" : ", NULL as zs_main_task";
		$sql .= $hasHideFlag ? ", COALESCE(ue.zs_user_hide, 0) as hideflag" : ", 0 as hideflag";
		$sql .= $hasTaskTable ? ", mt.label as main_task_label" : ", '' as main_task_label";
		$sql .= " FROM ".$this->db->prefix()."user as u";
		$sql .= " LEFT JOIN ".$this->db->prefix()."usergroup_user as ugu";
		$sql .= " ON ugu.fk_user = u.rowid";
		if (!empty($groupIdSql)) {
			$sql .= " AND ugu.fk_usergroup IN (".$groupIdSql.")";
		}
		$sql .= " AND ugu.entity = ".((int) $this->conf->entity);
		if ($hasUserExtrafields) {
			$sql .= " LEFT JOIN ".$this->db->prefix()."user_extrafields as ue ON ue.fk_object = u.rowid";
		}
		if ($hasTaskTable) {
			$sql .= " LEFT JOIN ".$this->db->prefix()."dolizsynch_shift_task as mt ON mt.rowid = ue.zs_main_task";
		}
		$sql .= " WHERE ugu.fk_user IS NOT NULL";
		if ($hasHideFlag) {
			$sql .= " AND COALESCE(ue.zs_user_hide, 0) = 0";
		}
		$sql .= " AND (u.dateemployment IS NULL OR u.dateemployment = '0000-00-00' OR DATE(u.dateemployment) <= '".$this->db->escape($weekEnd)."')";
		$sql .= " AND (u.dateemploymentend IS NULL OR u.dateemploymentend = '0000-00-00' OR DATE(u.dateemploymentend) > '".$this->db->escape($weekStart)."')";
		$sql .= " ORDER BY u.lastname ASC, u.firstname ASC, u.rowid ASC";

		$rows = $this->fetchRowsOptional($sql);
		$resources = array();
		foreach ($rows as $row) {
			$id = isset($row['rowid']) ? (int) $row['rowid'] : 0;
			if ($id <= 0) {
				continue;
			}

			$fullName = trim((isset($row['firstname']) ? (string) $row['firstname'] : '').' '.(isset($row['lastname']) ? (string) $row['lastname'] : ''));
			if ($fullName === '') {
				$fullName = isset($row['login']) && trim((string) $row['login']) !== '' ? (string) $row['login'] : 'User #'.$id;
			}

			$expectedHours = null;
			if (function_exists('shiftplanner_resolve_contracted_hours')) {
				$expectedHours = shiftplanner_resolve_contracted_hours(
					isset($row['horasefect']) ? $row['horasefect'] : null,
					isset($row['weeklyhours']) ? $row['weeklyhours'] : null
				);
			}
			if ($expectedHours === null) {
				$expectedHours = $this->normalizeNullableHours(isset($row['horasefect']) ? $row['horasefect'] : null);
				if ($expectedHours === null) {
					$expectedHours = $this->normalizeNullableHours(isset($row['weeklyhours']) ? $row['weeklyhours'] : null);
				}
			}

			$mainTaskId = null;
			if (isset($row['zs_main_task']) && is_numeric($row['zs_main_task']) && (int) $row['zs_main_task'] > 0) {
				$mainTaskId = (int) $row['zs_main_task'];
			}

			$resources[] = array(
				'id' => $id,
				'title' => $fullName,
				'expected_hours' => $expectedHours !== null ? (float) $expectedHours : null,
				'weekly_hours' => $this->normalizeNullableHours(isset($row['weeklyhours']) ? $row['weeklyhours'] : null),
				'date_employment' => $this->normalizeDateFromDbValue(isset($row['dateemployment']) ? $row['dateemployment'] : null),
				'date_employment_end' => $this->normalizeDateFromDbValue(isset($row['dateemploymentend']) ? $row['dateemploymentend'] : null),
				'main_task_id' => $mainTaskId,
				'main_task_label' => isset($row['main_task_label']) ? trim((string) $row['main_task_label']) : '',
			);
		}

		return $resources;
	}

	/**
	 * @param array<int, array<string, mixed>> $resources
	 * @return array<int, int>
	 */
	private function extractWorkforceResourceIds(array $resources)
	{
		$resourceIds = array();
		foreach ($resources as $resource) {
			$resourceId = isset($resource['id']) ? (int) $resource['id'] : 0;
			if ($resourceId > 0) {
				$resourceIds[$resourceId] = $resourceId;
			}
		}

		return array_values($resourceIds);
	}

	/**
	 * @param string $weekStart
	 * @param string $weekEnd
	 * @param array<int, array<string, mixed>> $resources
	 * @param int $includeForeign
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchWorkforceEvents($weekStart, $weekEnd, array $resources, $includeForeign = 1)
	{
		if (!$this->tableExists('dolizsynch_shift')) {
			return array();
		}

		$resourceIds = array();
		foreach ($resources as $resource) {
			$resourceId = isset($resource['id']) ? (int) $resource['id'] : 0;
			if ($resourceId > 0) {
				$resourceIds[$resourceId] = $resourceId;
			}
		}
		if (empty($resourceIds)) {
			return array();
		}

		$resourceIdSql = implode(',', array_map('intval', array_values($resourceIds)));
		$taskTableExists = $this->tableExists('dolizsynch_shift_task');
		$includeForeign = ((int) $includeForeign) === 0 ? 0 : 1;

		$rangeStart = $weekStart.' 00:00:00';
		$rangeEnd = date('Y-m-d', strtotime($weekEnd.' +1 day')).' 00:00:00';

		$sql = "SELECT s.rowid";
		$sql .= ", s.fk_user";
		$sql .= ", s.fk_task";
		$sql .= ", s.label";
		$sql .= ", s.date_start";
		$sql .= ", s.date_end";
		$sql .= ", s.color";
		$sql .= ", s.entity as shift_entity";
		$sql .= $taskTableExists ? ", t.label as task_label, t.color as task_color" : ", '' as task_label, '' as task_color";
		$sql .= ", e.label as entity_label";
		$sql .= " FROM ".$this->db->prefix()."dolizsynch_shift as s";
		if ($taskTableExists) {
			$sql .= " LEFT JOIN ".$this->db->prefix()."dolizsynch_shift_task as t ON t.rowid = s.fk_task";
		}
		$sql .= " LEFT JOIN ".$this->db->prefix()."entity as e ON e.rowid = s.entity";
		$sql .= " WHERE s.fk_user IN (".$resourceIdSql.")";
		$sql .= " AND s.date_start < '".$this->db->escape($rangeEnd)."'";
		$sql .= " AND s.date_end > '".$this->db->escape($rangeStart)."'";
		if (empty($includeForeign)) {
			$sql .= " AND s.entity = ".((int) $this->conf->entity);
		}
		$sql .= " ORDER BY s.date_start ASC, s.rowid ASC";

		$rows = $this->fetchRowsOptional($sql);
		$events = array();
		foreach ($rows as $row) {
			$eventStart = $this->normalizeDateTimeFromDbValue(isset($row['date_start']) ? $row['date_start'] : null);
			$eventEnd = $this->normalizeDateTimeFromDbValue(isset($row['date_end']) ? $row['date_end'] : null);
			if ($eventStart === '' || $eventEnd === '') {
				continue;
			}

			$entityId = isset($row['shift_entity']) ? (int) $row['shift_entity'] : (int) $this->conf->entity;
			$entityLabel = isset($row['entity_label']) ? trim((string) $row['entity_label']) : '';
			if ($entityLabel === '') {
				$entityLabel = 'Entity '.$entityId;
			}

			$title = isset($row['label']) ? trim((string) $row['label']) : '';
			$taskLabel = isset($row['task_label']) ? trim((string) $row['task_label']) : '';
			if ($title === '') {
				$title = $taskLabel !== '' ? $taskLabel : 'Shift #'.((int) $row['rowid']);
			}

			$color = isset($row['color']) ? trim((string) $row['color']) : '';
			if ($color === '' && isset($row['task_color'])) {
				$color = trim((string) $row['task_color']);
			}

			$events[] = array(
				'id' => isset($row['rowid']) ? (int) $row['rowid'] : 0,
				'resource_id' => isset($row['fk_user']) ? (int) $row['fk_user'] : 0,
				'fk_task' => isset($row['fk_task']) && is_numeric($row['fk_task']) ? (int) $row['fk_task'] : null,
				'task_label' => $taskLabel,
				'title' => $title,
				'start' => $eventStart,
				'end' => $eventEnd,
				'color' => $color,
				'entity' => $entityId,
				'entity_label' => $entityLabel,
				'foreign_entity' => $entityId !== (int) $this->conf->entity ? 1 : 0,
			);
		}

		return $events;
	}

	/**
	 * Read day-off / leave / public-holiday information for the selected workforce week.
	 *
	 * @param string $weekStart
	 * @param string $weekEnd
	 * @param array<int, array<string, mixed>> $resources
	 * @param int $includeForeign
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function fetchWorkforceAvailability($weekStart, $weekEnd, array $resources, $includeForeign = 1)
	{
		$includeForeign = ((int) $includeForeign) === 0 ? 0 : 1;
		$resourceIds = $this->extractWorkforceResourceIds($resources);
		if (empty($resourceIds)) {
			return array(
				'dayoffs' => array(),
				'leaves' => array(),
				'public_holidays' => $this->fetchWorkforcePublicHolidays($weekStart, $weekEnd),
			);
		}

		return array(
			'dayoffs' => $this->fetchWorkforceDayOffRows($weekStart, $weekEnd, $resourceIds, $includeForeign),
			'leaves' => $this->fetchWorkforceLeaveRows($weekStart, $weekEnd, $resourceIds, $includeForeign),
			'public_holidays' => $this->fetchWorkforcePublicHolidays($weekStart, $weekEnd),
		);
	}

	/**
	 * @param string $weekStart
	 * @param string $weekEnd
	 * @param array<int, int> $resourceIds
	 * @param int $includeForeign
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchWorkforceDayOffRows($weekStart, $weekEnd, array $resourceIds, $includeForeign = 1)
	{
		if (!$this->tableExists('dolizsynch_dayoff')) {
			return array();
		}
		if (empty($resourceIds)) {
			return array();
		}

		$includeForeign = ((int) $includeForeign) === 0 ? 0 : 1;
		$resourceIdSql = implode(',', array_map('intval', array_values($resourceIds)));
		$hasLockColumn = $this->tableColumnExists('dolizsynch_dayoff', 'is_lock');
		$hasEntityColumn = $this->tableColumnExists('dolizsynch_dayoff', 'entity');
		$weekStartTs = strtotime($weekStart.' 00:00:00');
		$weekEndTs = strtotime($weekEnd.' 00:00:00');
		if ($weekStartTs === false || $weekEndTs === false || $weekEndTs < $weekStartTs) {
			return array();
		}

		$sql = "SELECT d.rowid";
		$sql .= ", d.fk_user";
		$sql .= ", d.date_dayoff";
		$sql .= ", d.is_recurring";
		$sql .= ", d.description";
		$sql .= $hasLockColumn ? ", d.is_lock" : ", 0 as is_lock";
		if ($hasEntityColumn) {
			$sql .= ", d.entity as dayoff_entity";
			$sql .= ", e.label as entity_label";
		} else {
			$sql .= ", ".((int) $this->conf->entity)." as dayoff_entity";
			$sql .= ", '' as entity_label";
		}
		$sql .= " FROM ".$this->db->prefix()."dolizsynch_dayoff as d";
		if ($hasEntityColumn) {
			$sql .= " LEFT JOIN ".$this->db->prefix()."entity as e ON e.rowid = d.entity";
		}
		$sql .= " WHERE ((d.is_recurring = 0";
		$sql .= " AND d.date_dayoff >= '".$this->db->escape($weekStart)."'";
		$sql .= " AND d.date_dayoff <= '".$this->db->escape($weekEnd)."')";
		$sql .= " OR (d.is_recurring = 1 AND d.date_dayoff <= '".$this->db->escape($weekEnd)."'))";
		$sql .= " AND d.fk_user IN (".$resourceIdSql.")";
		if ($hasEntityColumn && empty($includeForeign)) {
			$sql .= " AND (d.entity IS NULL OR d.entity IN (0, ".((int) $this->conf->entity)."))";
		}
		$sql .= " ORDER BY d.date_dayoff ASC, d.fk_user ASC, d.rowid ASC";

		$rows = $this->fetchRowsOptional($sql);
		$result = array();
		foreach ($rows as $row) {
			$resourceId = isset($row['fk_user']) ? (int) $row['fk_user'] : 0;
			if ($resourceId <= 0) {
				continue;
			}

			$baseTs = isset($row['date_dayoff']) ? strtotime((string) $row['date_dayoff'].' 00:00:00') : false;
			if ($baseTs === false || $baseTs <= 0) {
				continue;
			}

			$entityId = isset($row['dayoff_entity']) ? (int) $row['dayoff_entity'] : (int) $this->conf->entity;
			if ($entityId <= 0) {
				$entityId = (int) $this->conf->entity;
			}
			$entityLabel = isset($row['entity_label']) ? trim((string) $row['entity_label']) : '';
			if ($entityLabel === '') {
				$entityLabel = $this->getEntityLabelById($entityId);
			}
			if ($entityLabel === '') {
				$entityLabel = 'Entity '.$entityId;
			}

			$isLock = isset($row['is_lock']) ? (int) $row['is_lock'] : 0;
			$entryType = $isLock === 1 ? 'lockday' : 'dayoff';
			$isRecurring = isset($row['is_recurring']) ? (int) $row['is_recurring'] : 0;
			$description = isset($row['description']) ? trim((string) $row['description']) : '';

			if ($isRecurring === 1) {
				$baseWeekDay = (int) date('w', $baseTs);
				for ($cursor = $weekStartTs; $cursor <= $weekEndTs; $cursor += 86400) {
					if ($cursor < $baseTs) {
						continue;
					}
					if ((int) date('w', $cursor) !== $baseWeekDay) {
						continue;
					}

					$result[] = array(
						'id' => isset($row['rowid']) ? (int) $row['rowid'] : 0,
						'resource_id' => $resourceId,
						'date_iso' => date('Y-m-d', $cursor),
						'is_recurring' => 1,
						'description' => $description,
						'entry_type' => $entryType,
						'is_lock' => $isLock === 1 ? 1 : 0,
						'entity' => $entityId,
						'entity_label' => $entityLabel,
						'foreign_entity' => $entityId !== (int) $this->conf->entity ? 1 : 0,
					);
				}
				continue;
			}

			if ($baseTs < $weekStartTs || $baseTs > $weekEndTs) {
				continue;
			}

			$result[] = array(
				'id' => isset($row['rowid']) ? (int) $row['rowid'] : 0,
				'resource_id' => $resourceId,
				'date_iso' => date('Y-m-d', $baseTs),
				'is_recurring' => 0,
				'description' => $description,
				'entry_type' => $entryType,
				'is_lock' => $isLock === 1 ? 1 : 0,
				'entity' => $entityId,
				'entity_label' => $entityLabel,
				'foreign_entity' => $entityId !== (int) $this->conf->entity ? 1 : 0,
			);
		}

		return $result;
	}

	/**
	 * @param string $weekStart
	 * @param string $weekEnd
	 * @param array<int, int> $resourceIds
	 * @param int $includeForeign
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchWorkforceLeaveRows($weekStart, $weekEnd, array $resourceIds, $includeForeign = 1)
	{
		if (!$this->tableExists('dolizsynch_leave')) {
			return array();
		}
		if (empty($resourceIds)) {
			return array();
		}

		$includeForeign = ((int) $includeForeign) === 0 ? 0 : 1;
		$resourceIdSql = implode(',', array_map('intval', array_values($resourceIds)));
		$hasEntityColumn = $this->tableColumnExists('dolizsynch_leave', 'entity');
		$hasHalfdayColumn = $this->tableColumnExists('dolizsynch_leave', 'halfday');
		$hasMinutesColumn = $this->tableColumnExists('dolizsynch_leave', 'minutes_total');
		$hasTypeColumn = $this->tableColumnExists('dolizsynch_leave', 'fk_type');
		$hasDescriptionColumn = $this->tableColumnExists('dolizsynch_leave', 'description');
		$hasHolidayTypeTable = $this->tableExists('c_holiday_types');
		$hasHolidayColor = $hasHolidayTypeTable && $this->tableColumnExists('c_holiday_types', 'color');
		$dayChangeHour = $this->getShiftDayChangeHour();

		$rangeStartSql = $weekStart.' 00:00:00';
		$rangeEndSql = date('Y-m-d', strtotime($weekEnd.' +1 day')).' 00:00:00';

		$sql = "SELECT l.rowid";
		$sql .= ", l.fk_user";
		$sql .= ", l.date_start";
		$sql .= ", l.date_end";
		$sql .= $hasHalfdayColumn ? ", l.halfday" : ", 0 as halfday";
		$sql .= $hasMinutesColumn ? ", l.minutes_total" : ", 0 as minutes_total";
		$sql .= $hasTypeColumn ? ", l.fk_type as type_id" : ", 0 as type_id";
		$sql .= $hasDescriptionColumn ? ", l.description" : ", '' as description";
		if ($hasHolidayTypeTable && $hasTypeColumn) {
			$sql .= ", ht.label as type_label";
			$sql .= ", ht.code as type_code";
			$sql .= $hasHolidayColor ? ", COALESCE(ht.color, '#FFA500') as leave_color" : ", '#FFA500' as leave_color";
		} else {
			$sql .= ", '' as type_label";
			$sql .= ", '' as type_code";
			$sql .= ", '#FFA500' as leave_color";
		}
		if ($hasEntityColumn) {
			$sql .= ", l.entity as leave_entity";
			$sql .= ", e.label as entity_label";
		} else {
			$sql .= ", ".((int) $this->conf->entity)." as leave_entity";
			$sql .= ", '' as entity_label";
		}
		$sql .= " FROM ".$this->db->prefix()."dolizsynch_leave as l";
		if ($hasHolidayTypeTable && $hasTypeColumn) {
			$sql .= " LEFT JOIN ".$this->db->prefix()."c_holiday_types as ht ON ht.rowid = l.fk_type";
		}
		if ($hasEntityColumn) {
			$sql .= " LEFT JOIN ".$this->db->prefix()."entity as e ON e.rowid = l.entity";
		}
		$sql .= " WHERE l.date_end > '".$this->db->escape($rangeStartSql)."'";
		$sql .= " AND l.date_start < '".$this->db->escape($rangeEndSql)."'";
		$sql .= " AND l.fk_user IN (".$resourceIdSql.")";
		if ($hasEntityColumn && empty($includeForeign)) {
			$sql .= " AND (l.entity IS NULL OR l.entity IN (0, ".((int) $this->conf->entity)."))";
		}
		$sql .= " ORDER BY l.date_start ASC, l.rowid ASC";

		$rows = $this->fetchRowsOptional($sql);
		$result = array();
		foreach ($rows as $row) {
			$resourceId = isset($row['fk_user']) ? (int) $row['fk_user'] : 0;
			if ($resourceId <= 0) {
				continue;
			}

			$leaveStartTs = $this->normalizeTimestampValue(isset($row['date_start']) ? $row['date_start'] : null);
			$leaveEndTs = $this->normalizeTimestampValue(isset($row['date_end']) ? $row['date_end'] : null);
			if ($leaveStartTs <= 0 || $leaveEndTs <= 0 || $leaveEndTs <= $leaveStartTs) {
				continue;
			}

			$minutesTotalStored = isset($row['minutes_total']) ? (int) $row['minutes_total'] : 0;
			$halfday = isset($row['halfday']) ? (int) $row['halfday'] : 0;
			$durationMinutes = (int) floor(($leaveEndTs - $leaveStartTs) / 60);
			$startBoundaryTs = $this->shiftplannerOperationalBoundaryForTimestamp($leaveStartTs, $dayChangeHour);

			$minutesOnly = false;
			if ($minutesTotalStored > 0) {
				$expectedEndTs = $leaveStartTs + ($minutesTotalStored * 60);
				if ($leaveStartTs === $startBoundaryTs && $leaveEndTs === $expectedEndTs) {
					$minutesOnly = true;
				}
			}

			$isHoursLeave = (!$minutesOnly && $durationMinutes > 0 && $durationMinutes < 1440);
			$isPartialLeave = $isHoursLeave || $minutesOnly || ($halfday !== 0);
			$hoursStartLabel = '';
			$hoursEndLabel = '';
			$hoursRangeLabel = '';
			$hoursTotalMinutes = 0;
			if ($isHoursLeave) {
				$hoursStartLabel = date('H:i', $leaveStartTs);
				$hoursEndLabel = date('H:i', $leaveEndTs);
				$hoursRangeLabel = $hoursStartLabel !== '' && $hoursEndLabel !== '' ? $hoursStartLabel.'-'.$hoursEndLabel : '';
				$hoursTotalMinutes = $durationMinutes;
			} elseif ($minutesOnly) {
				$hoursTotalMinutes = $minutesTotalStored;
			}

			if ($isPartialLeave) {
				$startIso = date('Y-m-d', $leaveStartTs);
				$endIso = date('Y-m-d', max($leaveEndTs - 1, $leaveStartTs));
			} else {
				$startBoundaryTs = $this->shiftplannerOperationalBoundaryForTimestamp($leaveStartTs, $dayChangeHour);
				$lastDayBoundaryTs = $this->shiftplannerOperationalBoundaryForTimestamp($leaveEndTs - 1, $dayChangeHour);
				$startIso = date('Y-m-d', $startBoundaryTs);
				$endIso = date('Y-m-d', $lastDayBoundaryTs);
			}

			$typeLabel = isset($row['type_label']) ? trim((string) $row['type_label']) : '';
			$typeCode = isset($row['type_code']) ? trim((string) $row['type_code']) : '';
			if ($typeLabel === '') {
				$typeLabel = $typeCode !== '' ? $typeCode : 'Leave';
			}

			$entityId = isset($row['leave_entity']) ? (int) $row['leave_entity'] : (int) $this->conf->entity;
			if ($entityId <= 0) {
				$entityId = (int) $this->conf->entity;
			}
			$entityLabel = isset($row['entity_label']) ? trim((string) $row['entity_label']) : '';
			if ($entityLabel === '') {
				$entityLabel = $this->getEntityLabelById($entityId);
			}
			if ($entityLabel === '') {
				$entityLabel = 'Entity '.$entityId;
			}

			$result[] = array(
				'id' => isset($row['rowid']) ? (int) $row['rowid'] : 0,
				'resource_id' => $resourceId,
				'type_id' => isset($row['type_id']) ? (int) $row['type_id'] : 0,
				'type_label' => $typeLabel,
				'type_code' => $typeCode,
				'color' => isset($row['leave_color']) ? (string) $row['leave_color'] : '#FFA500',
				'start_iso' => $startIso,
				'end_iso' => $endIso,
				'description' => isset($row['description']) ? trim((string) $row['description']) : '',
				'halfday' => $halfday,
				'is_hours' => $isHoursLeave ? 1 : 0,
				'minutes_only' => $minutesOnly ? 1 : 0,
				'hours_range' => $hoursRangeLabel,
				'hours_start' => $hoursStartLabel,
				'hours_end' => $hoursEndLabel,
				'hours_total_minutes' => $hoursTotalMinutes,
				'entity' => $entityId,
				'entity_label' => $entityLabel,
				'foreign_entity' => $entityId !== (int) $this->conf->entity ? 1 : 0,
			);
		}

		return $result;
	}

	/**
	 * @param string $weekStart
	 * @param string $weekEnd
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchWorkforcePublicHolidays($weekStart, $weekEnd)
	{
		if (!function_exists('shiftplanner_fetch_public_holidays')) {
			return array();
		}

		$countryId = (int) getDolGlobalInt('DOLIZSYNCH_PUBLIC_HOLIDAY_COUNTRY', 25);
		$rangeEndExclusive = date('Y-m-d', strtotime($weekEnd.' +1 day'));
		$rows = shiftplanner_fetch_public_holidays($this->db, $countryId, $weekStart, $rangeEndExclusive);
		if (!is_array($rows)) {
			return array();
		}

		$result = array();
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$dateIso = isset($row['date']) ? trim((string) $row['date']) : '';
			if (!$this->isDateYmd($dateIso)) {
				continue;
			}
			$result[] = array(
				'date' => $dateIso,
				'name' => isset($row['name']) ? trim((string) $row['name']) : '',
			);
		}

		return $result;
	}

	/**
	 * @return int
	 */
	private function getShiftDayChangeHour()
	{
		if (function_exists('shiftplanner_get_day_change_hour')) {
			$hour = (int) shiftplanner_get_day_change_hour();
		} else {
			$hour = isset($this->conf->global->ZS_PUNCH_DAY_CHANGE_HOUR) ? (int) $this->conf->global->ZS_PUNCH_DAY_CHANGE_HOUR : 0;
		}

		if ($hour < 0 || $hour > 23) {
			return 0;
		}

		return $hour;
	}

	/**
	 * @param int $timestamp
	 * @param int $dayChangeHour
	 * @return int
	 */
	private function shiftplannerOperationalBoundaryForTimestamp($timestamp, $dayChangeHour)
	{
		$timestamp = (int) $timestamp;
		$dayChangeHour = (int) $dayChangeHour;
		$boundary = mktime($dayChangeHour, 0, 0, (int) date('n', $timestamp), (int) date('j', $timestamp), (int) date('Y', $timestamp));
		if ($timestamp < $boundary) {
			$boundary -= 86400;
		}

		return (int) $boundary;
	}

	/**
	 * @param string $punchDate
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchWorkforcePunchRows($punchDate)
	{
		if ($this->shouldUsePunchSynchSource($punchDate)) {
			return $this->fetchWorkforcePunchRowsFromSynch($punchDate);
		}

		if (!$this->tableExists('dolizsynch_zspunchclock')) {
			return array();
		}

		$hasUserExtrafields = $this->tableExists('user_extrafields');
		$sql = "SELECT t.rowid";
		$sql .= ", t.empid_zs";
		$sql .= ", t.fk_user";
		$sql .= ", t.dataval";
		$sql .= ", t.datahora_in";
		$sql .= ", t.datahora_out";
		$sql .= ", t.duration";
		$sql .= ", t.real_entry";
		$sql .= ", COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))), ''), NULLIF(TRIM(u.login), ''), CONCAT('Emp #', t.empid_zs)) as employee_name";
		$sql .= " FROM ".$this->db->prefix()."dolizsynch_zspunchclock as t";
		if ($hasUserExtrafields) {
			$sql .= " LEFT JOIN ".$this->db->prefix()."user_extrafields as ue ON t.empid_zs = ue.zs_employee_id";
			$sql .= " LEFT JOIN ".$this->db->prefix()."user as u ON u.rowid = ue.fk_object";
		} else {
			$sql .= " LEFT JOIN ".$this->db->prefix()."user as u ON u.rowid = t.fk_user";
		}
		$sql .= " WHERE t.entity IN (".getEntity('dolizsynch_zspunchclock').")";
		$sql .= " AND DATE(t.dataval) = '".$this->db->escape($punchDate)."'";
		$sql .= " ORDER BY t.empid_zs ASC";
		$sql .= ", CASE WHEN t.datahora_in IS NULL THEN 1 ELSE 0 END ASC";
		$sql .= ", t.datahora_in ASC, t.rowid ASC";

		$rows = $this->fetchRowsOptional($sql);
		$result = array();
		foreach ($rows as $row) {
			$durationSeconds = isset($row['duration']) ? (int) $row['duration'] : 0;
			if ($durationSeconds <= 0) {
				$durationSeconds = $this->computeDurationSecondsFromDateTimes(
					isset($row['datahora_in']) ? $row['datahora_in'] : null,
					isset($row['datahora_out']) ? $row['datahora_out'] : null
				);
			}

			$result[] = array(
				'id' => isset($row['rowid']) ? (int) $row['rowid'] : 0,
				'empid_zs' => isset($row['empid_zs']) ? (int) $row['empid_zs'] : 0,
				'fk_user' => isset($row['fk_user']) ? (int) $row['fk_user'] : 0,
				'employee_name' => isset($row['employee_name']) ? (string) $row['employee_name'] : '',
				'dataval' => $this->normalizeDateFromDbValue(isset($row['dataval']) ? $row['dataval'] : null),
				'datahora_in' => $this->normalizeDateTimeFromDbValue(isset($row['datahora_in']) ? $row['datahora_in'] : null),
				'datahora_out' => $this->normalizeDateTimeFromDbValue(isset($row['datahora_out']) ? $row['datahora_out'] : null),
				'duration_seconds' => $durationSeconds,
				'duration_label' => $this->formatDurationHoursMinutes($durationSeconds),
				'real_entry' => isset($row['real_entry']) ? (int) $row['real_entry'] : 1,
			);
		}

		return $result;
	}

	/**
	 * @param string $punchDate
	 * @return bool
	 */
	private function shouldUsePunchSynchSource($punchDate)
	{
		if (!$this->tableExists('dolizsynch_zspunchclock_synch')) {
			return false;
		}

		$today = date('Y-m-d');

		return $this->isDateYmd($punchDate) && $punchDate === $today;
	}

	/**
	 * @param string $punchDate
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchWorkforcePunchRowsFromSynch($punchDate)
	{
		$hasUserExtrafields = $this->tableExists('user_extrafields');
		$sql = "SELECT t.rowid";
		$sql .= ", t.empid_zs";
		$sql .= ", t.dataval";
		$sql .= ", t.datahora";
		$sql .= ", t.sentido";
		$sql .= ", t.zsbms_synch";
		if ($hasUserExtrafields) {
			$sql .= ", COALESCE(ue.fk_object, 0) as fk_user";
			$sql .= ", COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))), ''), NULLIF(TRIM(u.login), ''), CONCAT('Emp #', t.empid_zs)) as employee_name";
		} else {
			$sql .= ", 0 as fk_user";
			$sql .= ", CONCAT('Emp #', t.empid_zs) as employee_name";
		}
		$sql .= " FROM ".$this->db->prefix()."dolizsynch_zspunchclock_synch as t";
		if ($hasUserExtrafields) {
			$sql .= " LEFT JOIN ".$this->db->prefix()."user_extrafields as ue ON t.empid_zs = ue.zs_employee_id";
			$sql .= " LEFT JOIN ".$this->db->prefix()."user as u ON u.rowid = ue.fk_object";
		}
		$sql .= " WHERE t.entity IN (".getEntity('dolizsynch_zspunchclock_synch').")";
		$sql .= " AND DATE(t.dataval) = '".$this->db->escape($punchDate)."'";
		$sql .= " AND t.synch NOT IN (3, 4)";
		$sql .= " ORDER BY employee_name ASC, t.datahora ASC, t.rowid ASC";

		$rows = $this->fetchRowsOptional($sql);
		$result = array();
		foreach ($rows as $row) {
			$direction = isset($row['sentido']) ? (int) $row['sentido'] : 0;
			$dateTime = $this->normalizeDateTimeFromDbValue(isset($row['datahora']) ? $row['datahora'] : null);

			$dataHoraIn = '';
			$dataHoraOut = '';
			if ($direction === 2) {
				$dataHoraOut = $dateTime;
			} else {
				$dataHoraIn = $dateTime;
			}

			$result[] = array(
				'id' => isset($row['rowid']) ? (int) $row['rowid'] : 0,
				'empid_zs' => isset($row['empid_zs']) ? (int) $row['empid_zs'] : 0,
				'fk_user' => isset($row['fk_user']) ? (int) $row['fk_user'] : 0,
				'employee_name' => isset($row['employee_name']) ? (string) $row['employee_name'] : '',
				'dataval' => $this->normalizeDateFromDbValue(isset($row['dataval']) ? $row['dataval'] : null),
				'datahora_in' => $dataHoraIn,
				'datahora_out' => $dataHoraOut,
				'duration_seconds' => 0,
				'duration_label' => '',
				'real_entry' => isset($row['zsbms_synch']) && (int) $row['zsbms_synch'] > 0 ? 0 : 1,
			);
		}

		return $result;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private function normalizeDateFromDbValue($value)
	{
		if ($value === null || $value === '') {
			return '';
		}

		$timestamp = is_numeric($value) ? (int) $value : dol_stringtotime((string) $value);
		if (empty($timestamp)) {
			$timestamp = strtotime((string) $value);
		}
		if ($timestamp === false || $timestamp <= 0) {
			return '';
		}

		return dol_print_date($timestamp, '%Y-%m-%d');
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private function normalizeDateTimeFromDbValue($value)
	{
		if ($value === null || $value === '') {
			return '';
		}

		$timestamp = is_numeric($value) ? (int) $value : dol_stringtotime((string) $value);
		if (empty($timestamp)) {
			$timestamp = strtotime((string) $value);
		}
		if ($timestamp === false || $timestamp <= 0) {
			return '';
		}

		return dol_print_date($timestamp, '%Y-%m-%dT%H:%M:%S');
	}

	/**
	 * @param mixed $value
	 * @return int
	 */
	private function normalizeTimestampValue($value)
	{
		if ($value === null || $value === '') {
			return 0;
		}
		if (is_numeric($value)) {
			return (int) $value;
		}

		$timestamp = dol_stringtotime((string) $value);
		if (empty($timestamp)) {
			$timestamp = strtotime((string) $value);
		}
		if ($timestamp === false || $timestamp <= 0) {
			return 0;
		}

		return (int) $timestamp;
	}

	/**
	 * @param mixed $value
	 * @return float|null
	 */
	private function normalizeNullableHours($value)
	{
		if ($value === null) {
			return null;
		}

		if (is_string($value)) {
			$value = trim($value);
			if ($value === '') {
				return null;
			}
			$value = str_replace(',', '.', $value);
		}

		if (!is_numeric($value)) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * @param mixed $startValue
	 * @param mixed $endValue
	 * @return int
	 */
	private function computeDurationSecondsFromDateTimes($startValue, $endValue)
	{
		$startTs = $startValue !== null && $startValue !== ''
			? (is_numeric($startValue) ? (int) $startValue : dol_stringtotime((string) $startValue))
			: 0;
		$endTs = $endValue !== null && $endValue !== ''
			? (is_numeric($endValue) ? (int) $endValue : dol_stringtotime((string) $endValue))
			: 0;

		if (empty($startTs) || empty($endTs)) {
			return 0;
		}
		if ($endTs < $startTs) {
			$endTs += 86400;
		}

		$diff = $endTs - $startTs;
		if ($diff <= 0) {
			return 0;
		}
		if ($diff > 86400) {
			return 86400;
		}

		return (int) $diff;
	}

	/**
	 * @param int $seconds
	 * @return string
	 */
	private function formatDurationHoursMinutes($seconds)
	{
		$seconds = (int) $seconds;
		if ($seconds < 0) {
			$seconds = 0;
		}

		$hours = (int) floor($seconds / 3600);
		$minutes = (int) floor(($seconds % 3600) / 60);

		return sprintf('%02d:%02d', $hours, $minutes);
	}

	/**
	 * @return void
	 */
	private function ensureFechoReadAccess()
	{
		if (!$this->canReadFechoMenu()) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}
		if (!isModEnabled('degema')) {
			throw new KreaFlowApiException('Module degema is not enabled.', 403);
		}
		if (empty($this->user->rights->degema->fechofinanceiro->read)) {
			throw new KreaFlowApiException($this->langs->trans('ErrorForbidden'), 403);
		}
	}

	/**
	 * @return bool
	 */
	private function canCreateFechoFinanceiro()
	{
		return $this->canCreateFechoMenu() && $this->canUseDegemaFechoWriteRight();
	}

	/**
	 * @return bool
	 */
	private function canUpdateFechoFinanceiro()
	{
		return $this->canUpdateFechoMenu() && $this->canUseDegemaFechoWriteRight();
	}

	/**
	 * @return bool
	 */
	private function canUpdateValidatedFechoFinanceiro()
	{
		return $this->canUpdateFechoFinanceiro() && $this->canUpdateValidatedFechoMenu();
	}

	/**
	 * @return bool
	 */
	private function canUpdateOldFechoFinanceiro()
	{
		return $this->canUpdateFechoFinanceiro() && $this->canUpdateOldFechoMenu();
	}

	/**
	 * @param int $status
	 * @return bool
	 */
	private function canUpdateFechoStatus($status)
	{
		$status = (int) $status;
		if ($status === 0) {
			return true;
		}

		return ($status === 1 && $this->canUpdateValidatedFechoFinanceiro());
	}

	/**
	 * @param string $dateYmd
	 * @return bool
	 */
	private function isFechoDateWithinEditLimit($dateYmd)
	{
		if (!$this->isDateYmd($dateYmd)) {
			return true;
		}
		if ($this->canUpdateOldFechoFinanceiro()) {
			return true;
		}

		return $dateYmd >= $this->getFechoEditEarliestDate();
	}

	/**
	 * @param int $status
	 * @param string $dateYmd
	 * @return bool
	 */
	private function canUpdateFechoRecord($status, $dateYmd)
	{
		if (!$this->canUpdateFechoFinanceiro()) {
			return false;
		}
		if (!$this->canUpdateFechoStatus((int) $status)) {
			return false;
		}

		return $this->isFechoDateWithinEditLimit($dateYmd);
	}

	/**
	 * Compatibility with existing DeGema cards:
	 * read users are allowed to create/edit when advanced permission checks are disabled.
	 *
	 * @return bool
	 */
	private function canUseDegemaFechoWriteRight()
	{
		if (!empty($this->user->rights->degema->fechofinanceiro->write)) {
			return true;
		}

		return !empty($this->user->rights->degema->fechofinanceiro->read);
	}

	/**
	 * @return bool
	 */
	private function canChangeFechoStore()
	{
		return !empty($this->user->rights->degema->fechofinanceiro->changeentity);
	}

	/**
	 * @return void
	 */
	private function ensureFechoClassLoaded()
	{
		if (!class_exists('FechoFinanceiro')) {
			dol_include_once('/degema/class/fechofinanceiro.class.php', 'FechoFinanceiro');
		}
		if (!class_exists('FechoFinanceiro')) {
			throw new KreaFlowApiException('FechoFinanceiro class is unavailable.', 500);
		}
	}

	/**
	 * @return void
	 */
	private function ensureFechoValidationLibraryLoaded()
	{
		if (!function_exists('degemaFechoFinanceiroValidatePostedTotals') || !function_exists('degemaFechoFinanceiroComputeMetrics')) {
			dol_include_once('/degema/lib/degema_fechofinanceiro.lib.php');
		}
		if (!function_exists('degemaFechoFinanceiroValidatePostedTotals') || !function_exists('degemaFechoFinanceiroComputeMetrics')) {
			throw new KreaFlowApiException('FechoFinanceiro validation library is unavailable.', 500);
		}
	}

	/**
	 * @return array<int, string>
	 */
	private function getFechoNumericFieldNames()
	{
		return array(
			'val_talao_caixa',
			'tal_numerario',
			'tal_mb',
			'tal_ubereats',
			'tal_glovo',
			'tal_comermelhor',
			'tal_factcredito',
			'tal_tickets',
			'tal_notacredito',
			'val_moedas',
			'val_005',
			'val_010',
			'val_020',
			'val_050',
			'val_100',
			'val_200',
			'val_500',
				'tpa_1',
				'tpa_2',
				'tpa_3',
				'tpa_4',
				'tpa_5',
				'tpa_6',
				'val_tickets',
				'tips',
				'despesas',
				'diferenca',
				'total_isen',
				'total_redu',
				'total_inte',
				'total_norm',
			'total_sem_iva',
		);
	}

	/**
	 * @param mixed $value
	 * @return float
	 */
	private function normalizeLocaleNumber($value)
	{
		if ($value === null || $value === '') {
			return 0.0;
		}
		if (is_int($value) || is_float($value)) {
			return (float) $value;
		}

		$normalized = trim((string) $value);
		if ($normalized === '') {
			return 0.0;
		}

		$normalized = preg_replace('/[^0-9,\.\-\+]/', '', $normalized);
		if (!is_string($normalized) || $normalized === '' || $normalized === '-' || $normalized === '+') {
			return 0.0;
		}

		if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
			$normalized = str_replace('.', '', $normalized);
			$normalized = str_replace(',', '.', $normalized);
		} elseif (strpos($normalized, ',') !== false) {
			$normalized = str_replace(',', '.', $normalized);
		}

		return (float) $normalized;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function normalizeFechoPayload(array $payload)
	{
		$datecx = isset($payload['datecx']) ? trim((string) $payload['datecx']) : '';
		if (!$this->isDateYmd($datecx)) {
			throw new KreaFlowApiException('Invalid datecx. Expected YYYY-MM-DD.', 400);
		}

		$fkLoja = isset($payload['fk_loja']) ? (int) $payload['fk_loja'] : 0;
		$responsavel = isset($payload['responsavel']) ? (int) $payload['responsavel'] : 0;
		if ($responsavel <= 0) {
			throw new KreaFlowApiException('Responsavel is required.', 400);
		}

		$allowedStoreIds = array();
		foreach ($this->getFechoStores() as $store) {
			$allowedStoreIds[] = (int) $store['id'];
		}
		if (empty($allowedStoreIds)) {
			throw new KreaFlowApiException('No available store for current context.', 400);
		}

		if (!$this->canChangeFechoStore()) {
			$fkLoja = (int) $this->conf->entity;
		}
		if ($fkLoja <= 0) {
			$fkLoja = (int) $this->conf->entity;
		}
		if (!in_array($fkLoja, $allowedStoreIds, true)) {
			throw new KreaFlowApiException('Invalid store for current user.', 403);
		}

		$userIds = array();
		foreach ($this->getFechoUsers() as $userRow) {
			$userIds[] = (int) $userRow['id'];
		}
		if (!in_array($responsavel, $userIds, true) && !$this->isValidFechoResponsavelUser($responsavel)) {
			throw new KreaFlowApiException('Invalid responsavel user.', 400);
		}

		$rawValues = array();
		if (isset($payload['values']) && is_array($payload['values'])) {
			$rawValues = $payload['values'];
		}

		$values = array();
		foreach ($this->getFechoNumericFieldNames() as $field) {
			$rawFieldValue = null;
			if (array_key_exists($field, $rawValues)) {
				$rawFieldValue = $rawValues[$field];
			} elseif (array_key_exists($field, $payload)) {
				$rawFieldValue = $payload[$field];
			}
			$values[$field] = $this->normalizeLocaleNumber($rawFieldValue);
		}

		$description = isset($payload['description']) ? trim((string) $payload['description']) : '';

		return array(
			'datecx' => $datecx,
			'fk_loja' => $fkLoja,
			'responsavel' => $responsavel,
			'description' => $description,
			'values' => $values,
		);
	}

	/**
	 * @param int $id
	 * @return array<string, mixed>
	 */
	private function fetchFechoRowById($id)
	{
		$id = (int) $id;
		if ($id <= 0) {
			return array();
		}

		$sql = "SELECT t.rowid as id";
		$sql .= ", t.ref";
		$sql .= ", t.entity";
		$sql .= ", t.status";
		$sql .= ", t.datecx";
		$sql .= ", t.fk_loja";
		$sql .= ", t.responsavel";
		$sql .= ", t.description";
		foreach ($this->getFechoNumericFieldNames() as $field) {
			$sql .= ", t.".$field;
		}
		$sql .= ", COALESCE(NULLIF(TRIM(el.label), ''), CONCAT('Entity ', t.fk_loja)) as store_label";
		$sql .= ", COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))), ''), u.login, 'Sem utilizador') as responsavel_label";
		$sql .= " FROM ".$this->db->prefix()."degema_fechofinanceiro as t";
		$sql .= " LEFT JOIN ".$this->db->prefix()."entity as el ON el.rowid = t.fk_loja";
		$sql .= " LEFT JOIN ".$this->db->prefix()."user as u ON u.rowid = t.responsavel";
		$sql .= " WHERE t.rowid = ".$id;
		$sql .= " AND t.entity = ".((int) $this->conf->entity);
		$sql .= " LIMIT 1";

		$rows = $this->fetchRows($sql);
		if (empty($rows)) {
			return array();
		}

		return $rows[0];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function buildFechoDetailFromRow(array $row)
	{
		$values = array();
		foreach ($this->getFechoNumericFieldNames() as $field) {
			$values[$field] = isset($row[$field]) ? (float) price2num($row[$field]) : 0.0;
		}

		$this->ensureFechoValidationLibraryLoaded();
		$metrics = degemaFechoFinanceiroComputeMetrics($values);

		$status = isset($row['status']) ? (int) $row['status'] : 0;
		$datecx = isset($row['datecx']) ? dol_print_date(dol_stringtotime($row['datecx']), '%Y-%m-%d') : '';

		return array(
			'id' => isset($row['id']) ? (int) $row['id'] : 0,
			'ref' => isset($row['ref']) ? (string) $row['ref'] : '',
			'status' => $status,
			'status_label' => $this->getFechoStatusLabel($status),
			'datecx' => $datecx,
			'fk_loja' => isset($row['fk_loja']) ? (int) $row['fk_loja'] : 0,
			'store_label' => isset($row['store_label']) ? (string) $row['store_label'] : '',
			'responsavel' => isset($row['responsavel']) ? (int) $row['responsavel'] : 0,
			'responsavel_label' => isset($row['responsavel_label']) ? (string) $row['responsavel_label'] : '',
			'description' => isset($row['description']) ? trim((string) $row['description']) : '',
			'values' => $values,
			'metrics' => $metrics,
			'can_edit' => $this->canUpdateFechoRecord($status, $datecx) ? 1 : 0,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function getFechoStores()
	{
		$currentEntityId = (int) $this->conf->entity;
		if (!$this->canChangeFechoStore()) {
			$currentEntityLabel = $this->getEntityLabelById($currentEntityId);
			return array(
				array(
					'id' => $currentEntityId,
					'label' => $this->buildEntityOptionLabel($currentEntityId, $currentEntityLabel),
				),
			);
		}

		$storesById = array();
		$this->ensureFechoClassLoaded();
		$fecho = new FechoFinanceiro($this->db);
		if (method_exists($fecho, 'getStoresList')) {
			$stores = $fecho->getStoresList();
			if (is_array($stores)) {
				foreach ($stores as $storeRow) {
					if (!is_array($storeRow)) {
						continue;
					}
					$storeId = (int) (isset($storeRow[0]) ? $storeRow[0] : 0);
					$storeLabel = isset($storeRow[1]) ? trim((string) $storeRow[1]) : '';
					if ($storeId <= 0) {
						continue;
					}
					if ($storeLabel === '') {
						$storeLabel = $this->getEntityLabelById($storeId);
					}
					$storesById[$storeId] = array(
						'id' => $storeId,
						'label' => $this->buildEntityOptionLabel($storeId, $storeLabel),
					);
				}
			}
		}

		if (empty($storesById)) {
			$sql = "SELECT e.rowid as id";
			$sql .= ", COALESCE(NULLIF(TRIM(e.label), ''), CONCAT('Entity ', e.rowid)) as label";
			$sql .= " FROM ".$this->db->prefix()."entity as e";
			$sql .= " ORDER BY e.rowid ASC";
			$rows = $this->fetchRows($sql);
			foreach ($rows as $row) {
				$storeId = isset($row['id']) ? (int) $row['id'] : 0;
				if ($storeId <= 0) {
					continue;
				}
				$storesById[$storeId] = array(
					'id' => $storeId,
					'label' => $this->buildEntityOptionLabel($storeId, isset($row['label']) ? (string) $row['label'] : ''),
				);
			}
		}

		$stores = array_values($storesById);
		usort($stores, function ($a, $b) {
			return strcmp((string) $a['label'], (string) $b['label']);
		});

		return $stores;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function getFechoUsers()
	{
		$usersById = array();
		$this->ensureFechoClassLoaded();
		$fecho = new FechoFinanceiro($this->db);
		if (method_exists($fecho, 'getResponsavelList')) {
			$responsavelRows = $fecho->getResponsavelList();
			if (is_array($responsavelRows)) {
				foreach ($responsavelRows as $row) {
					if (!is_array($row)) {
						continue;
					}
					$userId = (int) (isset($row[0]) ? $row[0] : 0);
					$userLabel = isset($row[1]) ? trim((string) $row[1]) : '';
					if ($userId <= 0) {
						continue;
					}
					$usersById[$userId] = array(
						'id' => $userId,
						'label' => $this->buildUserOptionLabel($userId, $userLabel),
					);
				}
			}
		}

		if (empty($usersById)) {
			$sql = "SELECT u.rowid as id";
			$sql .= ", TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))) as fullname";
			$sql .= ", COALESCE(u.login, '') as login";
			$sql .= " FROM ".$this->db->prefix()."user as u";
			$sql .= " WHERE u.fk_soc = 0";
			$sql .= " AND u.statut = 1";
			$sql .= " AND (u.entity IN (".getEntity('user').") OR u.entity = 0)";
			$sql .= " ORDER BY fullname ASC, login ASC, u.rowid ASC";
			$rows = $this->fetchRows($sql);
			foreach ($rows as $row) {
				$userId = isset($row['id']) ? (int) $row['id'] : 0;
				if ($userId <= 0) {
					continue;
				}
				$usersById[$userId] = array(
					'id' => $userId,
					'label' => $this->buildUserOptionLabel(
						$userId,
						isset($row['fullname']) ? (string) $row['fullname'] : '',
						isset($row['login']) ? (string) $row['login'] : ''
					),
				);
			}
		}

		$currentUserId = (int) $this->user->id;
		if ($currentUserId > 0 && !isset($usersById[$currentUserId])) {
			$usersById[$currentUserId] = array(
				'id' => $currentUserId,
				'label' => $this->buildUserOptionLabel($currentUserId, $this->user->getFullName($this->langs), $this->user->login),
			);
		}

		$users = array_values($usersById);
		usort($users, function ($a, $b) {
			return strcmp((string) $a['label'], (string) $b['label']);
		});

		return $users;
	}

	/**
	 * @param int $entityId
	 * @param string $label
	 * @return string
	 */
	private function buildEntityOptionLabel($entityId, $label)
	{
		$entityId = (int) $entityId;
		$label = trim((string) $label);
		if ($label === '') {
			$label = 'Entity '.$entityId;
		}

		return $label;
	}

	/**
	 * @param int $userId
	 * @param string $fullName
	 * @param string $login
	 * @return string
	 */
	private function buildUserOptionLabel($userId, $fullName, $login = '')
	{
		$userId = (int) $userId;
		$fullName = trim((string) $fullName);
		$login = trim((string) $login);

		if ($fullName === '' && $login === '') {
			$labelFromDb = $this->getUserLabelById($userId);
			$fullName = isset($labelFromDb['fullname']) ? trim((string) $labelFromDb['fullname']) : '';
			$login = isset($labelFromDb['login']) ? trim((string) $labelFromDb['login']) : '';
		}

		if ($fullName !== '' && $login !== '') {
			return $fullName.' ('.$login.')';
		}
		if ($fullName !== '') {
			return $fullName;
		}
		if ($login !== '') {
			return $login;
		}

		return 'User #'.$userId;
	}

	/**
	 * @param int $userId
	 * @return array<string, string>
	 */
	private function getUserLabelById($userId)
	{
		$userId = (int) $userId;
		if ($userId <= 0) {
			return array('fullname' => '', 'login' => '');
		}

		$sql = "SELECT TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))) as fullname";
		$sql .= ", COALESCE(u.login, '') as login";
		$sql .= " FROM ".$this->db->prefix()."user as u";
		$sql .= " WHERE u.rowid = ".$userId;
		$sql .= " LIMIT 1";
		$rows = $this->fetchRowsOptional($sql);
		if (empty($rows)) {
			return array('fullname' => '', 'login' => '');
		}

		$row = $rows[0];
		return array(
			'fullname' => isset($row['fullname']) ? (string) $row['fullname'] : '',
			'login' => isset($row['login']) ? (string) $row['login'] : '',
		);
	}

	/**
	 * @param int $userId
	 * @return bool
	 */
	private function isValidFechoResponsavelUser($userId)
	{
		$userId = (int) $userId;
		if ($userId <= 0) {
			return false;
		}

		$sql = "SELECT u.rowid as id";
		$sql .= " FROM ".$this->db->prefix()."user as u";
		$sql .= " WHERE u.rowid = ".$userId;
		$sql .= " AND u.fk_soc = 0";
		$sql .= " AND (u.entity IN (".getEntity('user').") OR u.entity = 0)";
		$sql .= " LIMIT 1";

		$rows = $this->fetchRowsOptional($sql);
		return !empty($rows);
	}

	/**
	 * @param int $status
	 * @return string
	 */
	private function getFechoStatusLabel($status)
	{
		if ((int) $status === 1) {
			return 'Validado';
		}
		if ((int) $status === 9) {
			return 'Cancelado';
		}

		return 'Rascunho';
	}

	/**
	 * @param int $productId
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchProductSuppliers($productId)
	{
		$productId = (int) $productId;

		$sql = "SELECT pfp.rowid as supplier_price_id";
		$sql .= ", pfp.fk_soc as supplier_id";
		$sql .= ", COALESCE(NULLIF(TRIM(s.nom), ''), CONCAT('Supplier ', pfp.fk_soc)) as supplier_name";
		$sql .= ", pfp.ref_fourn as supplier_ref";
		$sql .= ", pfp.quantity as min_qty";
		$sql .= ", pfp.unitprice as unit_price_ht";
		$sql .= ", pfp.price as total_price_ht";
		$sql .= ", pfp.tva_tx";
		$sql .= ", pfp.delivery_time_days";
		$sql .= " FROM ".$this->db->prefix()."product_fournisseur_price as pfp";
		$sql .= " INNER JOIN ".$this->db->prefix()."product as p ON p.rowid = pfp.fk_product";
		$sql .= " INNER JOIN ".$this->db->prefix()."societe as s ON s.rowid = pfp.fk_soc";
		$sql .= " WHERE pfp.fk_product = ".$productId;
		$sql .= " AND pfp.entity = ".((int) $this->conf->entity);
		$sql .= " AND p.entity IN (".getEntity('product').")";
		$sql .= " AND s.entity IN (".getEntity('societe').")";
		$sql .= " ORDER BY s.nom ASC, pfp.quantity ASC, pfp.rowid ASC";

		$rows = $this->fetchRows($sql);
		$suppliers = array();
		foreach ($rows as $row) {
			$suppliers[] = array(
				'id' => (int) $row['supplier_price_id'],
				'supplier_id' => (int) $row['supplier_id'],
				'supplier_name' => (string) $row['supplier_name'],
				'supplier_ref' => isset($row['supplier_ref']) ? (string) $row['supplier_ref'] : '',
				'min_qty' => (float) price2num($row['min_qty']),
				'unit_price_ht' => (float) price2num($row['unit_price_ht']),
				'total_price_ht' => (float) price2num($row['total_price_ht']),
				'tva_tx' => (float) price2num($row['tva_tx']),
				'delivery_time_days' => $row['delivery_time_days'] !== null ? (int) $row['delivery_time_days'] : null,
			);
		}

		return $suppliers;
	}

	/**
	 * @param int $productId
	 * @return array<string, mixed>|null
	 */
	private function fetchKreaProductsNutrition($productId)
	{
		if (!$this->tableExists('kreaproducts_nutritional')) {
			return null;
		}

		$productId = (int) $productId;
		$sql = "SELECT n.is_food, n.energy_kcal, n.energy_kj, n.fat, n.saturates";
		$sql .= ", n.carbohydrates, n.sugars, n.protein, n.salt, n.fiber";
		$sql .= " FROM ".$this->db->prefix()."kreaproducts_nutritional as n";
		$sql .= " WHERE n.fk_product = ".$productId;
		$sql .= " ORDER BY n.rowid DESC";
		$sql .= " LIMIT 1";

		$rows = $this->fetchRowsOptional($sql);
		if (empty($rows)) {
			return null;
		}

		$row = $rows[0];

		return array(
			'is_food' => isset($row['is_food']) ? (int) $row['is_food'] : 0,
			'energy_kcal' => $this->normalizeNullableNumeric(isset($row['energy_kcal']) ? $row['energy_kcal'] : null),
			'energy_kj' => $this->normalizeNullableNumeric(isset($row['energy_kj']) ? $row['energy_kj'] : null),
			'fat' => $this->normalizeNullableNumeric(isset($row['fat']) ? $row['fat'] : null),
			'saturates' => $this->normalizeNullableNumeric(isset($row['saturates']) ? $row['saturates'] : null),
			'carbohydrates' => $this->normalizeNullableNumeric(isset($row['carbohydrates']) ? $row['carbohydrates'] : null),
			'sugars' => $this->normalizeNullableNumeric(isset($row['sugars']) ? $row['sugars'] : null),
			'protein' => $this->normalizeNullableNumeric(isset($row['protein']) ? $row['protein'] : null),
			'salt' => $this->normalizeNullableNumeric(isset($row['salt']) ? $row['salt'] : null),
			'fiber' => $this->normalizeNullableNumeric(isset($row['fiber']) ? $row['fiber'] : null),
		);
	}

	/**
	 * @param int $productId
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchKreaProductsAllergens($productId)
	{
		if (!$this->tableExists('kreaproducts_productallergens') || !$this->tableExists('c_kreaproducts')) {
			return array();
		}

		$productId = (int) $productId;
		$sql = "SELECT pa.fk_allergen as allergen_id";
		$sql .= ", pa.traces";
		$sql .= ", COALESCE(a.code, '') as code";
		$sql .= ", COALESCE(a.label, '') as label";
		$sql .= ", COALESCE(a.icon, '') as icon";
		$sql .= " FROM ".$this->db->prefix()."kreaproducts_productallergens as pa";
		$sql .= " LEFT JOIN ".$this->db->prefix()."c_kreaproducts as a ON a.rowid = pa.fk_allergen";
		$sql .= " WHERE pa.fk_product = ".$productId;
		$sql .= " AND (a.active = 1 OR a.active IS NULL)";
		$sql .= " ORDER BY COALESCE(a.label, a.code) ASC";

		$rows = $this->fetchRowsOptional($sql);
		$allergens = array();
		foreach ($rows as $row) {
			$icon = isset($row['icon']) ? trim((string) $row['icon']) : '';
			$allergens[] = array(
				'id' => (int) $row['allergen_id'],
				'code' => isset($row['code']) ? (string) $row['code'] : '',
				'label' => isset($row['label']) ? (string) $row['label'] : '',
				'icon' => $icon,
				'icon_url' => $icon !== '' ? '/custom/kreaproducts/img/'.rawurlencode($icon) : '',
				'traces' => isset($row['traces']) ? (int) $row['traces'] : 0,
			);
		}

		return $allergens;
	}

	/**
	 * @param mixed $value
	 * @return float|null
	 */
	private function normalizeNullableNumeric($value)
	{
		if ($value === null || $value === '') {
			return null;
		}

		return (float) price2num($value);
	}

	/**
	 * @param string $tableNameWithoutPrefix
	 * @return bool
	 */
	private function tableExists($tableNameWithoutPrefix)
	{
		$sql = "SELECT 1 FROM ".$this->db->prefix().$tableNameWithoutPrefix;
		$sql .= " WHERE 1 = 0";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}

		$this->db->free($resql);

		return true;
	}

	/**
	 * @param string $tableNameWithoutPrefix
	 * @param string $columnName
	 * @return bool
	 */
	private function tableColumnExists($tableNameWithoutPrefix, $columnName)
	{
		$tableNameWithoutPrefix = trim((string) $tableNameWithoutPrefix);
		$columnName = trim((string) $columnName);
		if ($tableNameWithoutPrefix === '' || $columnName === '') {
			return false;
		}
		if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableNameWithoutPrefix) || !preg_match('/^[a-zA-Z0-9_]+$/', $columnName)) {
			return false;
		}

		$sql = "SELECT ".$columnName." FROM ".$this->db->prefix().$tableNameWithoutPrefix;
		$sql .= " WHERE 1 = 0";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}

		$this->db->free($resql);

		return true;
	}

	/**
	 * Optional query helper for non-core tables.
	 *
	 * @param string $sql
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchRowsOptional($sql)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' optional query failed: '.$this->db->lasterror(), LOG_WARNING);
			return array();
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = (array) $obj;
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * Split free text into usable terms for token-based product search.
	 *
	 * @param string $search
	 * @return array<int, string>
	 */
	private function extractSearchTokens($search)
	{
		$search = trim((string) $search);
		if ($search === '') {
			return array();
		}

		$parts = preg_split('/\s+/', $search);
		if (!is_array($parts)) {
			return array();
		}

		$tokens = array();
		foreach ($parts as $part) {
			$token = preg_replace('/^[^[:alnum:]]+|[^[:alnum:]]+$/u', '', (string) $part);
			$token = trim((string) $token);
			if ($token === '') {
				continue;
			}
			$tokens[$token] = $token;
			if (count($tokens) >= 6) {
				break;
			}
		}

		return array_values($tokens);
	}

	/**
	 * @param string $startDate
	 * @param string $endDate
	 * @param bool $allowBeyondHistoryLimit
	 * @param bool $restrictToCompletedDays
	 * @return array<string, mixed>
	 */
	private function normalizeDateRange($startDate, $endDate, $allowBeyondHistoryLimit = false, $restrictToCompletedDays = true)
	{
		$yesterday = date('Y-m-d', strtotime('-1 day'));
		$today = date('Y-m-d');
		$maxDate = $restrictToCompletedDays ? $yesterday : $today;
		$fallbackDate = $restrictToCompletedDays ? $yesterday : $today;

		$start = $this->isDateYmd($startDate) ? $startDate : $fallbackDate;
		$end = $this->isDateYmd($endDate) ? $endDate : $fallbackDate;

		if ($start > $maxDate) {
			$start = $maxDate;
		}
		if ($end > $maxDate) {
			$end = $maxDate;
		}

		if (!$this->canReadBeyondHistoryLimit() && !$allowBeyondHistoryLimit) {
			$earliestAllowedDate = $this->getHistoryEarliestDate();
			if ($start < $earliestAllowedDate) {
				$start = $earliestAllowedDate;
			}
			if ($end < $earliestAllowedDate) {
				$end = $earliestAllowedDate;
			}
		}

		if ($start > $end) {
			$tmp = $start;
			$start = $end;
			$end = $tmp;
		}

		$startTs = strtotime($start.' 00:00:00');
		$endTs = strtotime($end.' 00:00:00');
		$diffDays = (int) floor(($endTs - $startTs) / 86400) + 1;
		if ($diffDays > 365) {
			$start = date('Y-m-d', strtotime($end.' -364 days'));
			$diffDays = 365;
		}

		return array(
			'start_date' => $start,
			'end_date' => $end,
			'day_count' => $diffDays,
		);
	}

	/**
	 * Build the fixed completed-day interval used for forecast training.
	 * Visualization ranges must never be passed into this calculation.
	 *
	 * @param string $historyEnd Last completed date
	 * @param int $trainDays Number of completed training days
	 * @return array<string, mixed>
	 */
	private function buildForecastTrainingRange($historyEnd, $trainDays)
	{
		$historyEnd = $this->isDateYmd($historyEnd) ? $historyEnd : date('Y-m-d', strtotime('-1 day'));
		$trainDays = max(60, min(365, (int) $trainDays));
		return array(
			'start_date' => date('Y-m-d', strtotime($historyEnd.' -'.($trainDays - 1).' days')),
			'end_date' => $historyEnd,
			'day_count' => $trainDays,
		);
	}

	/**
	 * @return int
	 */
	private function getHistoryLimitDays()
	{
		$value = (int) getDolGlobalInt('KREAFLOW_HISTORY_LIMIT_DAYS', 15);
		if ($value <= 0) {
			$value = 15;
		}
		if ($value > 3650) {
			$value = 3650;
		}

		return $value;
	}

	/**
	 * @return int
	 */
	private function getFechoEditLimitDays()
	{
		$value = (int) getDolGlobalInt('KREAFLOW_FECHO_EDIT_LIMIT_DAYS', 3);
		if ($value <= 0) {
			$value = 3;
		}
		if ($value > 3650) {
			$value = 3650;
		}

		return $value;
	}

	/**
	 * @return string
	 */
	private function getHistoryEarliestDate()
	{
		return date('Y-m-d', strtotime('-'.$this->getHistoryLimitDays().' days'));
	}

	/**
	 * @return string
	 */
	private function getFechoEditEarliestDate()
	{
		return date('Y-m-d', strtotime('-'.$this->getFechoEditLimitDays().' days'));
	}

	/**
	 * @param string $dateYmd
	 * @param bool $allowBeyondHistoryLimit
	 * @return bool
	 */
	private function isHistoryDateAllowed($dateYmd, $allowBeyondHistoryLimit = false)
	{
		if (!$this->isDateYmd($dateYmd)) {
			return true;
		}
		if ($this->canReadBeyondHistoryLimit() || $allowBeyondHistoryLimit) {
			return true;
		}

		return $dateYmd >= $this->getHistoryEarliestDate();
	}

	/**
	 * @param string $date
	 * @return bool
	 */
	private function isDateYmd($date)
	{
		if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			return false;
		}

		$time = strtotime($date.' 00:00:00');
		if ($time === false) {
			return false;
		}

		return date('Y-m-d', $time) === $date;
	}

	/**
	 * Select one comparison interval; count inclusive calendar days, not elapsed hours.
	 *
	 * @param string $startDate Normalized start date
	 * @param string $endDate Normalized end date
	 * @return array<string, string>
	 */
	public static function getComparisonPeriod($startDate, $endDate)
	{
		$start = new DateTimeImmutable($startDate);
		$end = new DateTimeImmutable($endDate);
		// Complete months take precedence over weekday alignment, including leap February.
		if ($start->format('d') === '01' && $start->format('Y-m') === $end->format('Y-m') && $end->format('d') === $end->format('t')) {
			$previousMonth = $start->setDate((int) $start->format('Y') - 1, (int) $start->format('m'), 1);
			return array('date_start' => $previousMonth->format('Y-m-01'), 'date_end' => $previousMonth->format('Y-m-t'), 'comparison_mode' => 'calendar');
		}
		$operational = (int) $start->diff($end)->days + 1 <= 35;
		$dates = array();
		foreach (array($start, $end) as $source) {
			if ($operational) {
				$previous = $source->modify('-364 days');
			} else {
				$month = $source->setDate((int) $source->format('Y') - 1, (int) $source->format('m'), 1);
				$previous = $month->setDate((int) $month->format('Y'), (int) $month->format('m'), min((int) $source->format('d'), (int) $month->format('t')));
			}
			$dates[] = $previous->format('Y-m-d');
		}
		return array('date_start' => $dates[0], 'date_end' => $dates[1], 'comparison_mode' => $operational ? 'operational' : 'calendar');
	}

	/**
	 * Fetch matching-period totals without bypassing history access.
	 *
	 * @param string $startDate
	 * @param string $endDate
	 * @return array<string, mixed>
	 */
	private function fetchPreviousYearSales($startDate, $endDate)
	{
		$result = self::getComparisonPeriod($startDate, $endDate);
		$dates = array($result['date_start'], $result['date_end']);
		$result['status'] = 'restricted';
		if (!$this->canReadBeyondHistoryLimit() && $dates[0] < $this->getHistoryEarliestDate()) {
			return $result;
		}
		// Revenue only: preserve the dashboard source, entity visibility and daily rounding.
		$rows = $this->fetchDailyForecastRevenueRows($dates[0].' 00:00:00', $dates[1].' 23:59:59', true);
		$result['status'] = count($rows) > 0 ? 'available' : 'empty';
		$result['revenue_ht'] = array_sum(array_column($rows, 'revenue_ht'));
		$result['revenue_ttc'] = array_sum(array_column($rows, 'revenue_ttc'));
		return $result;
	}

	/**
	 * Fetch revenue without invoice-line quantities.
	 *
	 * @param string $startDateTime
	 * @param string $endDateTime
	 * @param bool $dashboardVisibility Preserve native dashboard invoice sharing
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchDailyForecastRevenueRows($startDateTime, $endDateTime, $dashboardVisibility = false)
	{
		if ($this->useSyncedSalesSource()) {
			$sql = "SELECT DATE(z.data_zs) as sale_date";
			$sql .= ", ROUND(SUM(COALESCE(z.liquido_zs, 0)), 2) as revenue_ht";
			$sql .= ", ROUND(SUM(COALESCE(z.total_zs, 0)), 2) as revenue_ttc";
			$sql .= " FROM ".$this->db->prefix()."dolizsynch_zsinvoicesynch as z";
			$sql .= " WHERE z.entity = ".((int) $this->conf->entity);
			$sql .= " AND z.doc IN ('FS', 'FT', 'FA', 'NC', 'IH')";
			$sql .= " AND (z.tipo_zs IN ('0', '1', '2') OR z.tipo_zs IS NULL)";
			$sql .= " AND (z.anulado_zs = 0 OR z.anulado_zs IS NULL)";
			$sql .= " AND z.data_zs >= '".$this->db->escape($startDateTime)."'";
			$sql .= " AND z.data_zs <= '".$this->db->escape($endDateTime)."'";
			$sql .= " GROUP BY DATE(z.data_zs)";
			$sql .= " ORDER BY DATE(z.data_zs) ASC";

			return $this->fetchRows($sql);
		}

		$sql = "SELECT DATE(f.datef) as sale_date";
		$sql .= ", SUM(f.total_ht) as revenue_ht";
		$sql .= ", SUM(f.total_ttc) as revenue_ttc";
		$sql .= " FROM ".$this->db->prefix()."facture as f";
		$sql .= $dashboardVisibility ? " WHERE f.entity IN (".getEntity('invoice').")" : " WHERE f.entity = ".((int) $this->conf->entity);
		$sql .= " AND f.fk_statut IN (1, 2)";
		$sql .= " AND f.type IN (0, 1)";
		$sql .= " AND f.datef >= '".$this->db->escape($startDateTime)."'";
		$sql .= " AND f.datef <= '".$this->db->escape($endDateTime)."'";
		$sql .= " GROUP BY DATE(f.datef)";
		$sql .= " ORDER BY DATE(f.datef) ASC";

		return $this->fetchRows($sql);
	}

	/**
	 * @param string $startDateTime
	 * @param string $endDateTime
	 * @param bool $excludeCiFromSyncedData
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchDailySalesRows($startDateTime, $endDateTime, $excludeCiFromSyncedData = false)
	{
		if ($this->useSyncedSalesSource()) {
			$invoiceSyncTable = $this->db->prefix().'dolizsynch_zsinvoicesynch';
			$sql = "SELECT sales_totals.sale_date";
			$sql .= ", sales_totals.revenue_ht";
			$sql .= ", sales_totals.revenue_ttc";
			$sql .= ", COALESCE(quantity_totals.qty_sold, 0) as qty_sold";
			$sql .= ", sales_totals.invoice_count";
			$sql .= ", sales_totals.people_count";
			$sql .= " FROM (";
			$sql .= "   SELECT DATE(z.data_zs) as sale_date";
			$sql .= "   , ROUND(SUM(COALESCE(z.liquido_zs, 0)), 2) as revenue_ht";
			$sql .= "   , ROUND(SUM(COALESCE(z.total_zs, 0)), 2) as revenue_ttc";
			$sql .= "   , COUNT(DISTINCT CASE WHEN z.fk_facture > 0 THEN z.fk_facture ELSE CONCAT(COALESCE(z.doc, ''), '-', COALESCE(z.doc_serie, ''), '-', COALESCE(z.doc_num, 0)) END) as invoice_count";
			$sql .= "   , SUM(CASE WHEN z.doc <> 'NC' THEN COALESCE(z.pessoas_zs, 0) ELSE 0 END) as people_count";
			$sql .= "   FROM ".$invoiceSyncTable." as z";
			$sql .= "   WHERE z.entity = ".((int) $this->conf->entity);
			$sql .= "   AND z.doc IN ('FS', 'FT', 'FA', 'NC', 'IH')";
			$sql .= "   AND (z.tipo_zs IN ('0', '1', '2') OR z.tipo_zs IS NULL)";
			$sql .= "   AND (z.anulado_zs = 0 OR z.anulado_zs IS NULL)";
			$sql .= "   AND z.data_zs >= '".$this->db->escape($startDateTime)."'";
			$sql .= "   AND z.data_zs <= '".$this->db->escape($endDateTime)."'";
			if (!empty($excludeCiFromSyncedData)) {
				$sql .= "   AND NOT EXISTS (";
				$sql .= "     SELECT 1 FROM ".$invoiceSyncTable." as inv_ci";
				$sql .= "     WHERE inv_ci.fk_facture = z.fk_facture";
				$sql .= "     AND inv_ci.entity = ".((int) $this->conf->entity);
				$sql .= "     AND inv_ci.doc = 'CI'";
				$sql .= "   )";
			}
			$sql .= "   GROUP BY DATE(z.data_zs)";
			$sql .= " ) as sales_totals";
			$sql .= " LEFT JOIN (";
			$sql .= "   SELECT invoice_quantities.sale_date";
			$sql .= "   , SUM(CASE WHEN invoice_quantities.revenue_ttc < 0 THEN -ABS(invoice_quantities.qty_sold) ELSE invoice_quantities.qty_sold END) as qty_sold";
			$sql .= "   FROM (";
			$sql .= "     SELECT invoice_sales.fk_facture";
			$sql .= "     , invoice_sales.sale_date";
			$sql .= "     , invoice_sales.revenue_ttc";
			$sql .= "     , SUM(COALESCE(fd.qty, 0)) as qty_sold";
			$sql .= "     FROM (";
			$sql .= "       SELECT z.fk_facture";
			$sql .= "       , DATE(z.data_zs) as sale_date";
			$sql .= "       , ROUND(SUM(COALESCE(z.total_zs, 0)), 2) as revenue_ttc";
			$sql .= "       FROM ".$invoiceSyncTable." as z";
			$sql .= "       WHERE z.entity = ".((int) $this->conf->entity);
			$sql .= "       AND z.doc IN ('FS', 'FT', 'FA', 'NC', 'IH')";
			$sql .= "       AND (z.tipo_zs IN ('0', '1', '2') OR z.tipo_zs IS NULL)";
			$sql .= "       AND (z.anulado_zs = 0 OR z.anulado_zs IS NULL)";
			$sql .= "       AND z.data_zs >= '".$this->db->escape($startDateTime)."'";
			$sql .= "       AND z.data_zs <= '".$this->db->escape($endDateTime)."'";
			$sql .= "       AND z.fk_facture > 0";
			$sql .= "       GROUP BY z.fk_facture, DATE(z.data_zs)";
			$sql .= "     ) as invoice_sales";
			$sql .= "     INNER JOIN ".$this->db->prefix()."facture as f ON f.rowid = invoice_sales.fk_facture AND f.entity = ".((int) $this->conf->entity);
			$sql .= "     LEFT JOIN ".$this->db->prefix()."facturedet as fd ON fd.fk_facture = f.rowid";
			$sql .= "     GROUP BY invoice_sales.fk_facture, invoice_sales.sale_date, invoice_sales.revenue_ttc";
			$sql .= "   ) as invoice_quantities";
			$sql .= "   GROUP BY invoice_quantities.sale_date";
			$sql .= " ) as quantity_totals ON quantity_totals.sale_date = sales_totals.sale_date";
			$sql .= " ORDER BY sales_totals.sale_date ASC";

			return $this->fetchRows($sql);
		}

		$sql = "SELECT DATE(f.datef) as sale_date";
		$sql .= ", COUNT(f.rowid) as invoice_count";
		$sql .= ", COUNT(f.rowid) as people_count";
		$sql .= ", SUM(f.total_ht) as revenue_ht";
		$sql .= ", SUM(f.total_ttc) as revenue_ttc";
		$sql .= ", SUM(COALESCE(q.qty_sold, 0)) as qty_sold";
		$sql .= " FROM ".$this->db->prefix()."facture as f";
		$sql .= " LEFT JOIN (";
		$sql .= "   SELECT fd.fk_facture, SUM(fd.qty) as qty_sold";
		$sql .= "   FROM ".$this->db->prefix()."facturedet as fd";
		$sql .= "   GROUP BY fd.fk_facture";
		$sql .= " ) as q ON q.fk_facture = f.rowid";
		$sql .= " WHERE f.entity IN (".getEntity('invoice').")";
		$sql .= " AND f.fk_statut IN (1, 2)";
		$sql .= " AND f.type IN (0, 1)";
		$sql .= " AND f.datef >= '".$this->db->escape($startDateTime)."'";
		$sql .= " AND f.datef <= '".$this->db->escape($endDateTime)."'";
		$sql .= " GROUP BY DATE(f.datef)";
		$sql .= " ORDER BY DATE(f.datef) ASC";

		return $this->fetchRows($sql);
	}

	/**
	 * @param string $startDateTime
	 * @param string $endDateTime
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchHourlySalesRows($startDateTime, $endDateTime)
	{
		if ($this->useSyncedSalesSource()) {
			$invoiceSyncTable = $this->db->prefix().'dolizsynch_zsinvoicesynch';
			$sql = "SELECT H.hour_of_day";
			$sql .= ", COALESCE(S.revenue_ttc, 0) as revenue_ttc";
			$sql .= " FROM (";
			$sql .= "  SELECT 0 as hour_of_day UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL";
			$sql .= "  SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL";
			$sql .= "  SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL";
			$sql .= "  SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL";
			$sql .= "  SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL";
			$sql .= "  SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23";
			$sql .= " ) as H";
			$sql .= " LEFT JOIN (";
			$sql .= "   SELECT HOUR(fd.date_start) as hour_of_day";
			$sql .= "   , SUM(CASE WHEN inv.total_zs < 0 THEN -ABS(COALESCE(fd.total_ttc, 0)) ELSE COALESCE(fd.total_ttc, 0) END) as revenue_ttc";
			$sql .= "   , SUM(CASE WHEN inv.total_zs < 0 THEN -ABS(COALESCE(fd.qty, 0)) ELSE COALESCE(fd.qty, 0) END) as qty_sold";
			$sql .= "   FROM ".$this->db->prefix()."facturedet as fd";
			$sql .= "   INNER JOIN ".$this->db->prefix()."facture as f ON f.rowid = fd.fk_facture";
			$sql .= "   INNER JOIN ".$invoiceSyncTable." as inv ON inv.fk_facture = f.rowid";
			$sql .= "   WHERE fd.date_start IS NOT NULL";
			$sql .= "   AND fd.date_start >= '".$this->db->escape($startDateTime)."'";
			$sql .= "   AND fd.date_start <= '".$this->db->escape($endDateTime)."'";
			$sql .= "   AND f.entity = ".((int) $this->conf->entity);
			$sql .= "   AND inv.entity = ".((int) $this->conf->entity);
			$sql .= "   AND inv.doc IN ('FS', 'FT', 'FA', 'NC', 'IH')";
			$sql .= "   AND (inv.tipo_zs IN ('0', '1', '2') OR inv.tipo_zs IS NULL)";
			$sql .= "   AND (inv.anulado_zs = 0 OR inv.anulado_zs IS NULL)";
			$sql .= "   AND f.fk_statut IN (1, 2)";
			$sql .= "   AND f.type IN (0, 1, 2)";
			$sql .= "   GROUP BY HOUR(fd.date_start)";
			$sql .= " ) as S ON S.hour_of_day = H.hour_of_day";
			$sql .= " WHERE COALESCE(S.qty_sold, 0) > 0 OR COALESCE(S.revenue_ttc, 0) > 0";
			$sql .= " ORDER BY H.hour_of_day ASC";

			return $this->fetchRows($sql);
		}

		$sql = "SELECT H.hour_of_day, COALESCE(S.revenue_ttc, 0) as revenue_ttc";
		$sql .= " FROM (";
		$sql .= "  SELECT 0 as hour_of_day UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL";
		$sql .= "  SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL";
		$sql .= "  SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL";
		$sql .= "  SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL";
		$sql .= "  SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL";
		$sql .= "  SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23";
		$sql .= " ) as H";
		$sql .= " LEFT JOIN (";
		$sql .= "   SELECT HOUR(COALESCE(fd.date_start, f.datef)) as hour_of_day, SUM(fd.total_ttc) as revenue_ttc";
		$sql .= "   FROM ".$this->db->prefix()."facturedet as fd";
		$sql .= "   INNER JOIN ".$this->db->prefix()."facture as f ON f.rowid = fd.fk_facture";
		$sql .= "   WHERE f.entity IN (".getEntity('invoice').")";
		$sql .= "   AND f.fk_statut IN (1, 2)";
		$sql .= "   AND f.type IN (0, 1)";
		$sql .= "   AND COALESCE(fd.date_start, f.datef) >= '".$this->db->escape($startDateTime)."'";
		$sql .= "   AND COALESCE(fd.date_start, f.datef) <= '".$this->db->escape($endDateTime)."'";
		$sql .= "   GROUP BY HOUR(COALESCE(fd.date_start, f.datef))";
		$sql .= " ) as S ON S.hour_of_day = H.hour_of_day";
		$sql .= " WHERE COALESCE(S.revenue_ttc, 0) > 0";
		$sql .= " ORDER BY H.hour_of_day ASC";

		return $this->fetchRows($sql);
	}

	/**
	 * @param string $startDateTime
	 * @param string $endDateTime
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchSalesByPaymentTypeRows($startDateTime, $endDateTime)
	{
		if ($this->useSyncedSalesSource() && $this->tableExists('dolizsynch_zspaymenttype')) {
			$invoiceSyncTable = $this->db->prefix().'dolizsynch_zsinvoicesynch';
			$paymentTypeTable = $this->db->prefix().'dolizsynch_zspaymenttype';
			$defaultLabel = $this->db->escape('Sem etiqueta');

			$paymentLabelExpr = "COALESCE(NULLIF(TRIM(pt.paymenttype_name), ''), '".$defaultLabel."')";
			$sql = "SELECT ".$paymentLabelExpr." as paymenttype_name";
			$sql .= ", COUNT(DISTINCT ptg.fk_facture) as transaction_count";
			$sql .= ", SUM(it.revenue_ht) as total_liquido";
			$sql .= ", SUM(it.revenue_ttc) as total_gross";
			$sql .= " FROM (";
			$sql .= "   SELECT f.rowid as fk_facture";
			$sql .= "   , CASE WHEN inv.revenue_ttc < 0 THEN -ABS(COALESCE(f.total_ht, 0)) ELSE COALESCE(f.total_ht, 0) END as revenue_ht";
			$sql .= "   , inv.revenue_ttc";
			$sql .= "   FROM ".$this->db->prefix()."facture as f";
			$sql .= "   INNER JOIN (";
			$sql .= "     SELECT z.fk_facture, ROUND(SUM(COALESCE(z.total_zs, 0)), 2) as revenue_ttc";
			$sql .= "     FROM ".$invoiceSyncTable." as z";
			$sql .= "     WHERE z.entity = ".((int) $this->conf->entity);
			$sql .= "     AND z.doc IN ('FS', 'FT', 'FA', 'NC', 'IH')";
			$sql .= "     AND (z.tipo_zs IN ('0', '1', '2') OR z.tipo_zs IS NULL)";
			$sql .= "     AND (z.anulado_zs = 0 OR z.anulado_zs IS NULL)";
			$sql .= "     AND z.data_zs >= '".$this->db->escape($startDateTime)."'";
			$sql .= "     AND z.data_zs <= '".$this->db->escape($endDateTime)."'";
			$sql .= "     AND z.fk_facture > 0";
			$sql .= "     GROUP BY z.fk_facture";
			$sql .= "   ) as inv ON inv.fk_facture = f.rowid";
			$sql .= "   WHERE f.entity = ".((int) $this->conf->entity);
			$sql .= "   AND f.fk_statut IN (1, 2)";
			$sql .= "   AND f.type IN (0, 1, 2)";
			$sql .= " ) as it";
			$sql .= " INNER JOIN (";
			$sql .= "   SELECT DISTINCT inv.fk_facture, inv.pagamento_zs";
			$sql .= "   FROM ".$invoiceSyncTable." as inv";
			$sql .= "   INNER JOIN ".$this->db->prefix()."facture as f ON f.rowid = inv.fk_facture";
			$sql .= "   WHERE f.entity = ".((int) $this->conf->entity);
			$sql .= "   AND inv.entity = ".((int) $this->conf->entity);
			$sql .= "   AND inv.doc IN ('FS', 'FT', 'FA', 'NC', 'IH')";
			$sql .= "   AND (inv.tipo_zs IN ('0', '1', '2') OR inv.tipo_zs IS NULL)";
			$sql .= "   AND (inv.anulado_zs = 0 OR inv.anulado_zs IS NULL)";
			$sql .= "   AND f.fk_statut IN (1, 2)";
			$sql .= "   AND f.type IN (0, 1, 2)";
			$sql .= "   AND inv.data_zs >= '".$this->db->escape($startDateTime)."'";
			$sql .= "   AND inv.data_zs <= '".$this->db->escape($endDateTime)."'";
			$sql .= " ) as ptg ON ptg.fk_facture = it.fk_facture";
			$sql .= " LEFT JOIN (";
			$sql .= "   SELECT paymenttype_id, MIN(paymenttype_name) as paymenttype_name";
			$sql .= "   FROM ".$paymentTypeTable;
			$sql .= "   GROUP BY paymenttype_id";
			$sql .= " ) as pt ON pt.paymenttype_id = ptg.pagamento_zs";
			$sql .= " GROUP BY ".$paymentLabelExpr;
			$sql .= " ORDER BY paymenttype_name ASC";

			return $this->fetchRows($sql);
		}

		$defaultLabel = $this->db->escape('Sem etiqueta');

		$paymentLabelExpr = "COALESCE(NULLIF(TRIM(cp.libelle), ''), NULLIF(TRIM(cp.code), ''), '".$defaultLabel."')";
		$sql = "SELECT ".$paymentLabelExpr." as paymenttype_name";
		$sql .= ", COUNT(base.rowid) as transaction_count";
		$sql .= ", SUM(base.total_ht) as total_liquido";
		$sql .= ", SUM(base.total_ttc) as total_gross";
		$sql .= " FROM (";
		$sql .= "   SELECT f.rowid, f.total_ht, f.total_ttc, f.fk_mode_reglement";
		$sql .= "   FROM ".$this->db->prefix()."facture as f";
		$sql .= "   WHERE f.entity IN (".getEntity('invoice').")";
		$sql .= "   AND f.fk_statut IN (1, 2)";
		$sql .= "   AND f.type IN (0, 1)";
		$sql .= "   AND f.datef >= '".$this->db->escape($startDateTime)."'";
		$sql .= "   AND f.datef <= '".$this->db->escape($endDateTime)."'";
		$sql .= " ) as base";
		$sql .= " LEFT JOIN ".$this->db->prefix()."c_paiement as cp";
		$sql .= " ON cp.id = base.fk_mode_reglement";
		$sql .= " AND cp.entity IN (".getEntity('c_paiement').")";
		$sql .= " GROUP BY ".$paymentLabelExpr;
		$sql .= " ORDER BY total_gross DESC";

		return $this->fetchRows($sql);
	}

	/**
	 * @param string $startDateTime
	 * @param string $endDateTime
	 * @param int $page
	 * @param int $pageSize
	 * @return array<string, mixed>
	 */
	private function fetchTopProductsPageData($startDateTime, $endDateTime, $page, $pageSize)
	{
		$page = $this->sanitizePositivePage($page);
		$pageSize = (int) $pageSize;
		if ($pageSize <= 0) {
			$pageSize = 12;
		}
		if ($pageSize > 100) {
			$pageSize = 100;
		}

		if ($this->useSyncedSalesSource()) {
			$invoiceSyncTable = $this->db->prefix().'dolizsynch_zsinvoicesynch';
			$baseSql = "SELECT COALESCE(p.ref, 'NOREF') as product_ref";
			$baseSql .= ", COALESCE(p.label, fd.description) as product_label";
			$baseSql .= ", SUM(CASE WHEN inv.total_zs < 0 THEN -ABS(COALESCE(fd.qty, 0)) ELSE COALESCE(fd.qty, 0) END) as qty_sold";
			$baseSql .= ", SUM(CASE WHEN inv.total_zs < 0 THEN -ABS(COALESCE(fd.total_ht, 0)) ELSE COALESCE(fd.total_ht, 0) END) as revenue_ht";
			$baseSql .= ", SUM(CASE WHEN inv.total_zs < 0 THEN -ABS(COALESCE(fd.total_ttc, 0)) ELSE COALESCE(fd.total_ttc, 0) END) as revenue_ttc";
			$baseSql .= " FROM ".$this->db->prefix()."facturedet as fd";
			$baseSql .= " INNER JOIN ".$this->db->prefix()."facture as f ON f.rowid = fd.fk_facture";
			$baseSql .= " INNER JOIN ".$invoiceSyncTable." as inv ON inv.fk_facture = f.rowid";
			$baseSql .= " LEFT JOIN ".$this->db->prefix()."product as p ON p.rowid = fd.fk_product";
			$baseSql .= " WHERE f.entity = ".((int) $this->conf->entity);
			$baseSql .= " AND inv.entity = ".((int) $this->conf->entity);
			$baseSql .= " AND inv.doc IN ('FS', 'FT', 'FA', 'NC', 'IH')";
			$baseSql .= " AND (inv.tipo_zs IN ('0', '1', '2') OR inv.tipo_zs IS NULL)";
			$baseSql .= " AND (inv.anulado_zs = 0 OR inv.anulado_zs IS NULL)";
			$baseSql .= " AND f.fk_statut IN (1, 2)";
			$baseSql .= " AND f.type IN (0, 1, 2)";
			$baseSql .= " AND inv.data_zs >= '".$this->db->escape($startDateTime)."'";
			$baseSql .= " AND inv.data_zs <= '".$this->db->escape($endDateTime)."'";
			$baseSql .= " GROUP BY COALESCE(p.ref, 'NOREF'), COALESCE(p.label, fd.description)";
		} else {
			$baseSql = "SELECT COALESCE(p.ref, 'NOREF') as product_ref";
			$baseSql .= ", COALESCE(p.label, fd.description) as product_label";
			$baseSql .= ", SUM(fd.qty) as qty_sold";
			$baseSql .= ", SUM(fd.total_ht) as revenue_ht";
			$baseSql .= ", SUM(fd.total_ttc) as revenue_ttc";
			$baseSql .= " FROM ".$this->db->prefix()."facturedet as fd";
			$baseSql .= " INNER JOIN ".$this->db->prefix()."facture as f ON f.rowid = fd.fk_facture";
			$baseSql .= " LEFT JOIN ".$this->db->prefix()."product as p ON p.rowid = fd.fk_product";
			$baseSql .= " WHERE f.entity IN (".getEntity('invoice').")";
			$baseSql .= " AND f.fk_statut IN (1, 2)";
			$baseSql .= " AND f.type IN (0, 1)";
			$baseSql .= " AND f.datef >= '".$this->db->escape($startDateTime)."'";
			$baseSql .= " AND f.datef <= '".$this->db->escape($endDateTime)."'";
			$baseSql .= " GROUP BY COALESCE(p.ref, 'NOREF'), COALESCE(p.label, fd.description)";
		}

		$totalCountSql = "SELECT COUNT(1) as total_count FROM (".$baseSql.") as top_products_count";
		$totalCountRows = $this->fetchRows($totalCountSql);
		$total = 0;
		if (!empty($totalCountRows) && isset($totalCountRows[0]['total_count'])) {
			$total = (int) $totalCountRows[0]['total_count'];
		}

		$totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 1;
		if ($page > $totalPages) {
			$page = $totalPages;
		}
		$offset = ($page - 1) * $pageSize;

		$sql = "SELECT top_products.product_ref";
		$sql .= ", top_products.product_label";
		$sql .= ", top_products.qty_sold";
		$sql .= ", top_products.revenue_ht";
		$sql .= ", top_products.revenue_ttc";
		$sql .= " FROM (".$baseSql.") as top_products";
		$sql .= " ORDER BY top_products.revenue_ttc DESC";
		$sql .= $this->db->plimit($pageSize, $offset);
		$rows = $this->fetchRows($sql);

		return array(
			'rows' => $rows,
			'total' => $total,
			'page' => $page,
			'page_size' => $pageSize,
			'pages' => $totalPages,
		);
	}

	/**
	 * @param string $startDateTime
	 * @param string $endDateTime
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchSalesByEmployeeRows($startDateTime, $endDateTime)
	{
		if ($this->useSyncedSalesSource() && $this->tableExists('user_extrafields')) {
			$invoiceSyncTable = $this->db->prefix().'dolizsynch_zsinvoicesynch';
			$defaultEmployee = $this->db->escape('Sem vendedor');

			$employeeLabelExpr = "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))), ''), NULLIF(TRIM(u.login), ''), '".$defaultEmployee."')";
			$sql = "SELECT ".$employeeLabelExpr." as employee_name";
			$sql .= ", COUNT(DISTINCT f.rowid) as transaction_count";
			$sql .= ", SUM(CASE WHEN inv.total_zs < 0 THEN -ABS(COALESCE(fd.qty, 0)) ELSE COALESCE(fd.qty, 0) END) as total_quantity_sold";
			$sql .= ", SUM(CASE WHEN inv.total_zs < 0 THEN -ABS(COALESCE(fd.multicurrency_total_ht, fd.total_ht, 0)) ELSE COALESCE(fd.multicurrency_total_ht, fd.total_ht, 0) END) as total_sales_value_ht";
			$sql .= ", SUM(CASE WHEN inv.total_zs < 0 THEN -ABS(COALESCE(fd.multicurrency_total_ttc, fd.total_ttc, 0)) ELSE COALESCE(fd.multicurrency_total_ttc, fd.total_ttc, 0) END) as total_sales_value_ttc";
			$sql .= " FROM ".$this->db->prefix()."facturedet as fd";
			$sql .= " INNER JOIN ".$this->db->prefix()."facture as f ON fd.fk_facture = f.rowid";
			$sql .= " INNER JOIN ".$invoiceSyncTable." as inv ON inv.fk_facture = f.rowid";
			$sql .= " LEFT JOIN ".$this->db->prefix()."user_extrafields as ue ON ue.zs_employee_id = inv.emp_zs";
			$sql .= " LEFT JOIN ".$this->db->prefix()."user as u ON u.rowid = ue.fk_object";
			$sql .= " WHERE f.entity = ".((int) $this->conf->entity);
			$sql .= " AND inv.entity = ".((int) $this->conf->entity);
			$sql .= " AND inv.doc IN ('FS', 'FT', 'FA', 'NC', 'IH')";
			$sql .= " AND (inv.tipo_zs IN ('0', '1', '2') OR inv.tipo_zs IS NULL)";
			$sql .= " AND (inv.anulado_zs = 0 OR inv.anulado_zs IS NULL)";
			$sql .= " AND f.fk_statut IN (1, 2)";
			$sql .= " AND f.type IN (0, 1, 2)";
			$sql .= " AND inv.data_zs >= '".$this->db->escape($startDateTime)."'";
			$sql .= " AND inv.data_zs <= '".$this->db->escape($endDateTime)."'";
			$sql .= " GROUP BY ".$employeeLabelExpr;
			$sql .= " ORDER BY total_sales_value_ttc DESC";

			return $this->fetchRows($sql);
		}

		$defaultEmployee = $this->db->escape('Sem utilizador');

		$employeeLabelExpr = "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))), ''), NULLIF(TRIM(u.login), ''), '".$defaultEmployee."')";
		$sql = "SELECT ".$employeeLabelExpr." as employee_name";
		$sql .= ", COUNT(f.rowid) as transaction_count";
		$sql .= ", SUM(COALESCE(q.qty_sold, 0)) as total_quantity_sold";
		$sql .= ", SUM(f.total_ht) as total_sales_value_ht";
		$sql .= ", SUM(f.total_ttc) as total_sales_value_ttc";
		$sql .= " FROM ".$this->db->prefix()."facture as f";
		$sql .= " LEFT JOIN (";
		$sql .= "   SELECT fd.fk_facture, SUM(fd.qty) as qty_sold";
		$sql .= "   FROM ".$this->db->prefix()."facturedet as fd";
		$sql .= "   GROUP BY fd.fk_facture";
		$sql .= " ) as q ON q.fk_facture = f.rowid";
		$sql .= " LEFT JOIN ".$this->db->prefix()."user as u ON u.rowid = f.fk_user_author";
		$sql .= " WHERE f.entity IN (".getEntity('invoice').")";
		$sql .= " AND f.fk_statut IN (1, 2)";
		$sql .= " AND f.type IN (0, 1)";
		$sql .= " AND f.datef >= '".$this->db->escape($startDateTime)."'";
		$sql .= " AND f.datef <= '".$this->db->escape($endDateTime)."'";
		$sql .= " GROUP BY ".$employeeLabelExpr;
		$sql .= " ORDER BY total_sales_value_ttc DESC";

		return $this->fetchRows($sql);
	}

	/**
	 * @param int $entityId
	 * @return string
	 */
	private function getForecastWeatherCoordinates($entityId)
	{
		require_once __DIR__.'/KreaFlowWeatherSettings.class.php';
		$settings = KreaFlowWeatherSettings::load($entityId);
		return $settings !== null ? $settings['query'] : '';
	}

	/**
	 * @param string $url
	 * @param int $timeoutSeconds
	 * @return array<string, mixed>|null
	 */
	private function fetchJsonUrl($url, $timeoutSeconds = 8)
	{
		$url = trim((string) $url);
		if ($url === '') {
			return null;
		}

		$timeoutSeconds = (int) $timeoutSeconds;
		if ($timeoutSeconds <= 0) {
			$timeoutSeconds = 8;
		}

		$rawBody = false;
		if (function_exists('curl_init')) {
			$ch = curl_init();
			if ($ch !== false) {
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
				curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
				curl_setopt($ch, CURLOPT_USERAGENT, 'KreaFlowMobile/1.0');
				$rawBody = curl_exec($ch);
				$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
				curl_close($ch);
				if ($rawBody === false || $httpCode < 200 || $httpCode >= 300) {
					$rawBody = false;
				}
			}
		}

		if ($rawBody === false) {
			$httpContext = stream_context_create(array(
				'http' => array(
					'method' => 'GET',
					'timeout' => $timeoutSeconds,
					'ignore_errors' => true,
					'header' => "Accept: application/json\r\nUser-Agent: KreaFlowMobile/1.0\r\n",
				),
			));
			$rawBody = @file_get_contents($url, false, $httpContext);
			if ($rawBody === false) {
				return null;
			}
		}

		$decoded = json_decode($rawBody, true);
		if (!is_array($decoded)) {
			return null;
		}

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $weatherDaily
	 * @return array<string, array<string, float|null>>
	 */
	private function mapWeatherDailyByDate(array $weatherDaily)
	{
		$map = array();
		if (!isset($weatherDaily['time']) || !is_array($weatherDaily['time'])) {
			return $map;
		}

		$temps = isset($weatherDaily['temperature_2m_mean']) && is_array($weatherDaily['temperature_2m_mean'])
			? $weatherDaily['temperature_2m_mean']
			: array();
		$precipitations = isset($weatherDaily['precipitation_sum']) && is_array($weatherDaily['precipitation_sum'])
			? $weatherDaily['precipitation_sum']
			: array();

		foreach ($weatherDaily['time'] as $index => $dateValue) {
			$dateString = (string) $dateValue;
			if (!$this->isDateYmd($dateString)) {
				continue;
			}
			$map[$dateString] = array(
				'temp' => $this->normalizeNullableFiniteFloat(isset($temps[$index]) ? $temps[$index] : null),
				'precip' => $this->normalizeFiniteFloat(isset($precipitations[$index]) ? $precipitations[$index] : 0.0, 0.0),
			);
		}

		return $map;
	}

	/**
	 * @param mixed $value
	 * @param float $fallback
	 * @return float
	 */
	private function normalizeFiniteFloat($value, $fallback = 0.0)
	{
		if ($value === null || $value === '') {
			return (float) $fallback;
		}

		$floatValue = (float) $value;
		if (is_nan($floatValue) || is_infinite($floatValue)) {
			return (float) $fallback;
		}

		return $floatValue;
	}

	/**
	 * @param mixed $value
	 * @return float|null
	 */
	private function normalizeNullableFiniteFloat($value)
	{
		if ($value === null || $value === '') {
			return null;
		}

		$floatValue = (float) $value;
		if (is_nan($floatValue) || is_infinite($floatValue)) {
			return null;
		}

		return $floatValue;
	}

	/**
	 * @param array<int, int|string> $years
	 * @return array<string, bool>
	 */
	private function buildPortugueseHolidaySet(array $years)
	{
		$holidaySet = array();
		foreach ($years as $yearValue) {
			$year = (int) $yearValue;
			if ($year < 1970 || $year > 2100) {
				continue;
			}

			$fixedDates = array(
				$year.'-01-01',
				$year.'-04-25',
				$year.'-05-01',
				$year.'-06-10',
				$year.'-08-15',
				$year.'-10-05',
				$year.'-11-01',
				$year.'-12-01',
				$year.'-12-08',
				$year.'-12-25',
			);
			foreach ($fixedDates as $holidayDate) {
				$holidaySet[$holidayDate] = true;
			}

			if (function_exists('easter_date')) {
				$easterTimestamp = @easter_date($year);
				if (is_int($easterTimestamp) && $easterTimestamp > 0) {
					$holidaySet[date('Y-m-d', $easterTimestamp)] = true;
					$holidaySet[date('Y-m-d', strtotime('-47 days', $easterTimestamp))] = true;
					$holidaySet[date('Y-m-d', strtotime('-2 days', $easterTimestamp))] = true;
					$holidaySet[date('Y-m-d', strtotime('+60 days', $easterTimestamp))] = true;
				}
			}
		}

		return $holidaySet;
	}

	/**
	 * Build a recency-weighted seasonal model for one revenue measure.
	 *
	 * @param array<int, array<string, mixed>> $dailySeries
	 * @param array<string, array<string, float|null>> $weatherHistoryByDate
	 * @param array<string, bool> $holidaySet
	 * @param string $targetKey
	 * @return array<string, mixed>
	 */
	private function buildSalesForecastModel(array $dailySeries, array $weatherHistoryByDate, array $holidaySet, $targetKey)
	{
		$targetKey = (string) $targetKey;
		$historyDayCount = count($dailySeries);
		$temperatureValues = array();
		foreach ($dailySeries as $row) {
			$dateValue = isset($row['sale_date']) ? (string) $row['sale_date'] : '';
			$tempValue = $dateValue !== '' && isset($weatherHistoryByDate[$dateValue]['temp'])
				? $weatherHistoryByDate[$dateValue]['temp']
				: null;
			if ($tempValue !== null) {
				$temperatureValues[] = $this->normalizeFiniteFloat($tempValue, 0.0);
			}
		}

		$meanTemperature = !empty($temperatureValues)
			? array_sum($temperatureValues) / count($temperatureValues)
			: 15.0;
		$samples = array();
		$targets = array();
		$weights = array();
		$lastIndex = max(0, $historyDayCount - 1);
		foreach ($dailySeries as $index => $row) {
			$dateValue = isset($row['sale_date']) ? (string) $row['sale_date'] : '';
			if (!$this->isDateYmd($dateValue)) {
				continue;
			}
			$tempValue = isset($weatherHistoryByDate[$dateValue]['temp'])
				? $weatherHistoryByDate[$dateValue]['temp']
				: null;
			$precipValue = isset($weatherHistoryByDate[$dateValue]['precip'])
				? $this->normalizeFiniteFloat($weatherHistoryByDate[$dateValue]['precip'], 0.0)
				: 0.0;
			$holidayValue = isset($holidaySet[$dateValue]) ? 1.0 : 0.0;
			$samples[] = $this->buildSalesForecastFeatures(
				$dateValue,
				(int) $index,
				$historyDayCount,
				$tempValue,
				$meanTemperature,
				$precipValue,
				$holidayValue
			);
			$targets[] = isset($row[$targetKey]) ? $this->normalizeFiniteFloat($row[$targetKey], 0.0) : 0.0;
			$weights[] = pow(0.5, ($lastIndex - (int) $index) / 90.0);
		}

		$average = !empty($targets) ? array_sum($targets) / count($targets) : 0.0;
		$upperBound = max(
			$average * 2.5,
			$this->calculatePercentile($targets, 0.95) * 1.5,
			0.0
		);
		$coefficients = null;
		if (count($samples) >= 14) {
			$coefficients = $this->fitLinearRegressionModel($samples, $targets, $weights, 2.0, 0.025);
		}

		return array(
			'target_key' => $targetKey,
			'coefficients' => $coefficients,
			'average' => $average,
			'upper_bound' => $upperBound,
			'mean_temperature' => $meanTemperature,
			'history_day_count' => $historyDayCount,
		);
	}

	/**
	 * Predict one revenue value by blending seasonal ridge output with the
	 * median of the latest eight matching weekdays.
	 *
	 * @param array<string, mixed> $model
	 * @param array<int, array<string, mixed>> $dailySeries
	 * @param string $dateValue
	 * @param int $timeIndex
	 * @param float|null $temperature
	 * @param float $precipitation
	 * @param float $holiday
	 * @return float
	 */
	private function predictSalesForecastValue(array $model, array $dailySeries, $dateValue, $timeIndex, $temperature, $precipitation, $holiday)
	{
		$average = isset($model['average']) ? $this->normalizeFiniteFloat($model['average'], 0.0) : 0.0;
		$meanTemperature = isset($model['mean_temperature'])
			? $this->normalizeFiniteFloat($model['mean_temperature'], 15.0)
			: 15.0;
		$historyDayCount = isset($model['history_day_count']) ? max(1, (int) $model['history_day_count']) : 1;
		$features = $this->buildSalesForecastFeatures(
			$dateValue,
			(int) $timeIndex,
			$historyDayCount,
			$temperature,
			$meanTemperature,
			$precipitation,
			$holiday
		);

		$regressionPrediction = null;
		if (isset($model['coefficients']) && is_array($model['coefficients'])) {
			$regressionPrediction = $this->predictLinearRegression($model['coefficients'], $features);
		}
		$targetKey = isset($model['target_key']) ? (string) $model['target_key'] : '';
		$weekdayMedian = $this->getRecentWeekdayMedian($dailySeries, $dateValue, $targetKey, 8);

		if ($regressionPrediction !== null && $weekdayMedian !== null) {
			$prediction = ($regressionPrediction * 0.8) + ($weekdayMedian * 0.2);
		} elseif ($regressionPrediction !== null) {
			$prediction = $regressionPrediction;
		} elseif ($weekdayMedian !== null) {
			$prediction = $weekdayMedian;
		} else {
			$prediction = $average;
		}

		$prediction = max(0.0, $this->normalizeFiniteFloat($prediction, $average));
		$upperBound = isset($model['upper_bound']) ? $this->normalizeFiniteFloat($model['upper_bound'], 0.0) : 0.0;
		if ($upperBound > 0.0 && $prediction > $upperBound) {
			$prediction = $upperBound;
		}

		return $prediction;
	}

	/**
	 * Build normalized trend, weather, holiday, and weekday features.
	 * Sunday is the reference weekday and Monday through Saturday are dummies.
	 *
	 * @param string $dateValue
	 * @param int $timeIndex
	 * @param int $historyDayCount
	 * @param float|null $temperature
	 * @param float $meanTemperature
	 * @param float $precipitation
	 * @param float $holiday
	 * @return array<int, float>
	 */
	private function buildSalesForecastFeatures($dateValue, $timeIndex, $historyDayCount, $temperature, $meanTemperature, $precipitation, $holiday)
	{
		$historyDayCount = max(1, (int) $historyDayCount);
		$meanTemperature = $this->normalizeFiniteFloat($meanTemperature, 15.0);
		$temperatureValue = $temperature === null
			? $meanTemperature
			: $this->normalizeFiniteFloat($temperature, $meanTemperature);
		$precipitationValue = max(0.0, $this->normalizeFiniteFloat($precipitation, 0.0));
		$timestamp = strtotime((string) $dateValue.' 12:00:00');
		$weekday = $timestamp !== false ? (int) date('w', $timestamp) : 0;
		$features = array(
			((int) $timeIndex) / $historyDayCount,
			($temperatureValue - $meanTemperature) / 10.0,
			log(1.0 + $precipitationValue) / 3.0,
			$this->normalizeFiniteFloat($holiday, 0.0) > 0.0 ? 1.0 : 0.0,
		);
		for ($weekdayValue = 1; $weekdayValue <= 6; $weekdayValue++) {
			$features[] = $weekday === $weekdayValue ? 1.0 : 0.0;
		}

		return $features;
	}

	/**
	 * @param array<int, array<string, mixed>> $dailySeries
	 * @param string $dateValue
	 * @param string $targetKey
	 * @param int $occurrenceLimit
	 * @return float|null
	 */
	private function getRecentWeekdayMedian(array $dailySeries, $dateValue, $targetKey, $occurrenceLimit)
	{
		$timestamp = strtotime((string) $dateValue.' 12:00:00');
		if ($timestamp === false || $targetKey === '') {
			return null;
		}
		$targetWeekday = (int) date('w', $timestamp);
		$values = array();
		for ($index = count($dailySeries) - 1; $index >= 0; $index--) {
			$row = $dailySeries[$index];
			$rowDate = isset($row['sale_date']) ? (string) $row['sale_date'] : '';
			$rowTimestamp = strtotime($rowDate.' 12:00:00');
			if ($rowTimestamp === false || (int) date('w', $rowTimestamp) !== $targetWeekday) {
				continue;
			}
			$values[] = isset($row[$targetKey]) ? $this->normalizeFiniteFloat($row[$targetKey], 0.0) : 0.0;
			if (count($values) >= max(1, (int) $occurrenceLimit)) {
				break;
			}
		}

		return empty($values) ? null : $this->calculatePercentile($values, 0.5);
	}

	/**
	 * @param array<int, float|int|string> $values
	 * @param float $quantile
	 * @return float
	 */
	private function calculatePercentile(array $values, $quantile)
	{
		if (empty($values)) {
			return 0.0;
		}
		$normalized = array();
		foreach ($values as $value) {
			$normalized[] = $this->normalizeFiniteFloat($value, 0.0);
		}
		sort($normalized, SORT_NUMERIC);
		$quantile = min(1.0, max(0.0, $this->normalizeFiniteFloat($quantile, 0.0)));
		$position = (count($normalized) - 1) * $quantile;
		$lowerIndex = (int) floor($position);
		$upperIndex = (int) ceil($position);
		if ($lowerIndex === $upperIndex) {
			return (float) $normalized[$lowerIndex];
		}

		$fraction = $position - $lowerIndex;
		return (float) $normalized[$lowerIndex]
			+ (((float) $normalized[$upperIndex] - (float) $normalized[$lowerIndex]) * $fraction);
	}

	/**
	 * @param array<int, array<int, float|int|string>> $samples
	 * @param array<int, float|int|string> $targets
	 * @param array<int, float|int|string> $weights
	 * @param float $ridgePenalty
	 * @param float $winsorizeQuantile
	 * @return array<int, float>|null
	 */
	private function fitLinearRegressionModel(array $samples, array $targets, array $weights = array(), $ridgePenalty = 1.0E-8, $winsorizeQuantile = 0.0)
	{
		$sampleCount = count($samples);
		if ($sampleCount <= 1 || count($targets) !== $sampleCount) {
			return null;
		}

		$firstRow = isset($samples[0]) && is_array($samples[0]) ? $samples[0] : array();
		$featureCount = count($firstRow);
		if ($featureCount <= 0) {
			return null;
		}

		$dimension = $featureCount + 1;
		$xtx = array();
		for ($row = 0; $row < $dimension; $row++) {
			$xtx[$row] = array_fill(0, $dimension, 0.0);
		}
		$xty = array_fill(0, $dimension, 0.0);

		$winsorizeQuantile = min(0.49, max(0.0, $this->normalizeFiniteFloat($winsorizeQuantile, 0.0)));
		$targetLowerBound = $winsorizeQuantile > 0.0 ? $this->calculatePercentile($targets, $winsorizeQuantile) : null;
		$targetUpperBound = $winsorizeQuantile > 0.0 ? $this->calculatePercentile($targets, 1.0 - $winsorizeQuantile) : null;
		for ($sampleIndex = 0; $sampleIndex < $sampleCount; $sampleIndex++) {
			$sampleRow = isset($samples[$sampleIndex]) && is_array($samples[$sampleIndex]) ? $samples[$sampleIndex] : array();
			if (count($sampleRow) !== $featureCount) {
				return null;
			}

			$x = array(1.0);
			for ($featureIndex = 0; $featureIndex < $featureCount; $featureIndex++) {
				$x[] = $this->normalizeFiniteFloat($sampleRow[$featureIndex], 0.0);
			}
			$target = $this->normalizeFiniteFloat(isset($targets[$sampleIndex]) ? $targets[$sampleIndex] : 0.0, 0.0);
			if ($targetLowerBound !== null && $target < $targetLowerBound) {
				$target = $targetLowerBound;
			}
			if ($targetUpperBound !== null && $target > $targetUpperBound) {
				$target = $targetUpperBound;
			}
			$weight = isset($weights[$sampleIndex])
				? max(0.0, $this->normalizeFiniteFloat($weights[$sampleIndex], 0.0))
				: 1.0;

			for ($i = 0; $i < $dimension; $i++) {
				$xty[$i] += $weight * $x[$i] * $target;
				for ($j = 0; $j < $dimension; $j++) {
					$xtx[$i][$j] += $weight * $x[$i] * $x[$j];
				}
			}
		}

		for ($i = 0; $i < $dimension; $i++) {
			$xtx[$i][$i] += 1.0E-8;
			if ($i > 0) {
				$xtx[$i][$i] += max(0.0, $this->normalizeFiniteFloat($ridgePenalty, 0.0));
			}
		}

		return $this->solveLinearSystem($xtx, $xty);
	}

	/**
	 * @param array<int, float> $coefficients
	 * @param array<int, float|int|string> $features
	 * @return float
	 */
	private function predictLinearRegression(array $coefficients, array $features)
	{
		if (empty($coefficients)) {
			return 0.0;
		}

		$prediction = $this->normalizeFiniteFloat($coefficients[0], 0.0);
		$featureCount = count($coefficients) - 1;
		for ($index = 0; $index < $featureCount; $index++) {
			$featureValue = isset($features[$index]) ? $this->normalizeFiniteFloat($features[$index], 0.0) : 0.0;
			$prediction += $this->normalizeFiniteFloat($coefficients[$index + 1], 0.0) * $featureValue;
		}

		return $this->normalizeFiniteFloat($prediction, 0.0);
	}

	/**
	 * @param array<int, array<int, float>> $matrix
	 * @param array<int, float> $vector
	 * @return array<int, float>|null
	 */
	private function solveLinearSystem(array $matrix, array $vector)
	{
		$dimension = count($matrix);
		if ($dimension === 0 || count($vector) !== $dimension) {
			return null;
		}

		$augmented = array();
		for ($row = 0; $row < $dimension; $row++) {
			if (!isset($matrix[$row]) || !is_array($matrix[$row]) || count($matrix[$row]) !== $dimension) {
				return null;
			}
			$augmented[$row] = array();
			for ($column = 0; $column < $dimension; $column++) {
				$augmented[$row][$column] = $this->normalizeFiniteFloat($matrix[$row][$column], 0.0);
			}
			$augmented[$row][$dimension] = $this->normalizeFiniteFloat($vector[$row], 0.0);
		}

		$epsilon = 1.0E-10;
		for ($column = 0; $column < $dimension; $column++) {
			$pivotRow = $column;
			$pivotValue = abs($augmented[$pivotRow][$column]);
			for ($row = $column + 1; $row < $dimension; $row++) {
				$candidate = abs($augmented[$row][$column]);
				if ($candidate > $pivotValue) {
					$pivotValue = $candidate;
					$pivotRow = $row;
				}
			}

			if ($pivotValue <= $epsilon) {
				return null;
			}

			if ($pivotRow !== $column) {
				$tmp = $augmented[$column];
				$augmented[$column] = $augmented[$pivotRow];
				$augmented[$pivotRow] = $tmp;
			}

			$pivot = $augmented[$column][$column];
			for ($j = $column; $j <= $dimension; $j++) {
				$augmented[$column][$j] = $augmented[$column][$j] / $pivot;
			}

			for ($row = 0; $row < $dimension; $row++) {
				if ($row === $column) {
					continue;
				}
				$factor = $augmented[$row][$column];
				if (abs($factor) <= $epsilon) {
					continue;
				}
				for ($j = $column; $j <= $dimension; $j++) {
					$augmented[$row][$j] -= $factor * $augmented[$column][$j];
				}
			}
		}

		$solution = array();
		for ($row = 0; $row < $dimension; $row++) {
			$solution[] = $this->normalizeFiniteFloat($augmented[$row][$dimension], 0.0);
		}

		return $solution;
	}

	/**
	 * @param float|int|string|null $precipitationMm
	 * @return array<string, string>
	 */
	private function classifyPrecipitationLabels($precipitationMm)
	{
		$precipitation = $this->normalizeFiniteFloat($precipitationMm, 0.0);
		if ($precipitation <= 1.0) {
			return array('intensity' => 'Sem chuva', 'probability' => 'Baixa');
		}
		if ($precipitation <= 5.0) {
			return array('intensity' => 'Chuva leve', 'probability' => 'Média');
		}
		if ($precipitation <= 15.0) {
			return array('intensity' => 'Chuva moderada', 'probability' => 'Alta');
		}

		return array('intensity' => 'Chuva forte', 'probability' => 'Alta');
	}

	/**
	 * @param mixed $value
	 * @return float|null
	 */
	private function normalizePrecipitationProbability($value)
	{
		$probability = $this->normalizeNullableFiniteFloat($value);
		if ($probability === null) {
			return null;
		}

		return min(100.0, max(0.0, $probability));
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param string $startDate
	 * @param string $endDate
	 * @return array<int, array<string, mixed>>
	 */
	private function buildDailySeries(array $rows, $startDate, $endDate)
	{
		$map = array();
		foreach ($rows as $row) {
			$day = isset($row['sale_date']) ? (string) $row['sale_date'] : '';
			if ($day === '') {
				continue;
			}
			$map[$day] = array(
				'sale_date' => $day,
				'revenue_ht' => (float) price2num(isset($row['revenue_ht']) ? $row['revenue_ht'] : 0),
				'revenue_ttc' => (float) price2num(isset($row['revenue_ttc']) ? $row['revenue_ttc'] : 0),
				'qty_sold' => (float) price2num(isset($row['qty_sold']) ? $row['qty_sold'] : 0),
				'invoice_count' => (int) (isset($row['invoice_count']) ? $row['invoice_count'] : 0),
				'people_count' => (float) price2num(isset($row['people_count']) ? $row['people_count'] : 0),
			);
		}

		$series = array();
		$cursor = strtotime($startDate.' 00:00:00');
		$endTs = strtotime($endDate.' 00:00:00');
		while ($cursor <= $endTs) {
			$day = date('Y-m-d', $cursor);
			if (isset($map[$day])) {
				$series[] = $map[$day];
			} else {
				$series[] = array(
					'sale_date' => $day,
					'revenue_ht' => 0,
					'revenue_ttc' => 0,
					'qty_sold' => 0,
					'invoice_count' => 0,
					'people_count' => 0,
				);
			}
			$cursor = strtotime('+1 day', $cursor);
		}

		return $series;
	}

	/**
	 * @return bool
	 */
	private function useSyncedSalesSource()
	{
		return $this->tableExists('dolizsynch_zsinvoicesynch');
	}

	/**
	 * @param string $sql
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchRows($sql)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaFlowApiException($this->db->lasterror(), 500);
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = (array) $obj;
		}
		$this->db->free($resql);

		return $rows;
	}
}
