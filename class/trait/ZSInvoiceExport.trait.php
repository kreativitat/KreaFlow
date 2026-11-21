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
 * \file        class/trait/ZSInvoiceExport.trait.php
 * \ingroup     dolizsynch
 * \brief       Export/create Dolibarr invoices in ZSBMS (ZoneSoft) through ZSAPI v3
 */

/**
 * Trait ZSInvoiceExport
 *
 * Creates invoice/work documents in ZSBMS from a Dolibarr invoice.
 * API contracts used:
 * - invoicedocuments/saveInstance (FT, VD)
 * - invoicedocuments/setInstanceAsNulled (FT, VD)
 * - invoicedocuments/cancelInstance (fallback when available)
 * - workingdocuments/saveInstance (EC), only if not excluded by module setup
 */
trait ZSInvoiceExport
{
	/**
	 * Create a ZSBMS invoice/work document from a Dolibarr invoice.
	 *
	 * @param int   $invoiceId Dolibarr invoice ID
	 * @param array $options   Optional overrides:
	 *                         - doc (FT|VD|EC), store, client_id, supplier_id, payment_type
	 *                         - iva_incluido (0|1), emp, fallback_product_code
	 *                         - delivery (array with morada/contacto/dataentrega/obs)
	 *                         - levantamento (string)
	 * @return array Structured result with success flag, status and payload/response
	 */
	public function createZsbmsInvoiceFromDolibarr($invoiceId, array $options = [])
	{
		global $db, $conf;

		$operation = __METHOD__;
		$invoiceId = (int) $invoiceId;

		if ($invoiceId <= 0) {
			$this->error = 'Invalid invoice id';
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
			];
		}

		$invoice = new Facture($db);
		if ($invoice->fetch($invoiceId) <= 0) {
			$this->error = !empty($invoice->error) ? $invoice->error : 'Invoice not found';
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
			];
		}

		$invoice->fetch_lines();
		if (empty($invoice->lines) || !is_array($invoice->lines)) {
			$this->error = 'Invoice has no lines';
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
			];
		}

		$docType = dolizsynch_normalize_zsbms_document_type($options['doc'] ?? 'FT');
		if ($this->isExcludedZsbmsDocumentType($docType)) {
			$this->error = "Document type {$docType} is excluded from ZSBMS invoice export";
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
			];
		}
		$isWorkingDocument = ($docType === 'EC');

		if (!$isWorkingDocument && !in_array($docType, ['FT', 'VD'], true)) {
			$this->error = 'Unsupported document type for ZSBMS creation. Allowed: FT, VD, EC';
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
			];
		}

		$store = isset($options['store']) ? (int) $options['store'] : (int) ($conf->global->ZS_API_STORE ?? 0);
		$clientId = isset($options['client_id']) ? (int) $options['client_id'] : 0;
		$supplierId = isset($options['supplier_id']) ? (int) $options['supplier_id'] : 0;
		$paymentType = isset($options['payment_type']) ? (int) $options['payment_type'] : (int) ($conf->global->ZS_DEFAULT_PAYMENT_TYPE ?? 1);
		$ivaIncluido = isset($options['iva_incluido']) ? (int) $options['iva_incluido'] : (!empty($conf->global->ZS_IVA_INCLUIDO) ? 1 : 0);
		$fallbackProductCode = isset($options['fallback_product_code']) ? (int) $options['fallback_product_code'] : 0;

		if ($paymentType <= 0) {
			$paymentType = 1;
		}
		$ivaIncluido = ($ivaIncluido > 0) ? 1 : 0;
		if ($isWorkingDocument) {
			// ZSBMS workingdocuments/saveInstance expects EC prices with VAT included.
			$ivaIncluido = 1;
		}

		$zsbmsLines = $this->buildZsbmsInvoiceLinesFromDolibarr($invoice->lines, $store, $ivaIncluido, $fallbackProductCode);
		if ($zsbmsLines === false) {
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
			];
		}
		if (!$this->validateDolibarrInvoiceExportTotals($invoice, $zsbmsLines, $ivaIncluido)) {
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
			];
		}

		$entityKey = $isWorkingDocument ? 'workingdocument' : 'invoicedocument';
		$interface = $isWorkingDocument ? 'workingdocuments' : 'invoicedocuments';

		$documentPayload = [
			'doc' => $docType,
			'cliente' => $clientId,
			'pagamento' => $paymentType,
			'ivaincluido' => $ivaIncluido,
			'vendas' => $zsbmsLines,
		];
		if ($isWorkingDocument) {
			// Customer orders (EC) must be created as unpaid in ZSBMS.
			$documentPayload['pago'] = 0;
		}

		if ($store > 0) {
			$documentPayload['loja'] = $store;
		}

		if (!$isWorkingDocument) {
			// ZSBMS requires the supplier field on FT/VD payloads, even when it resolves to 0.
			$documentPayload['fornecedor'] = max(0, $supplierId);
		}

		if (isset($options['emp']) && $options['emp'] !== '') {
			$documentPayload['emp'] = (int) $options['emp'];
		}

		if (!empty($options['delivery']) && is_array($options['delivery'])) {
			$delivery = $this->sanitizeZsbmsDeliveryData($options['delivery']);
			if (!empty($delivery)) {
				$documentPayload['delivery'] = $delivery;
			}
		}

		if ($isWorkingDocument && !empty($options['levantamento'])) {
			$documentPayload['levantamento'] = trim((string) $options['levantamento']);
		}

		$payload = [$entityKey => $documentPayload];

		$this->logDebug($operation, "Sending invoice creation payload to ZSBMS", [
			'invoice_id' => $invoiceId,
			'interface' => $interface,
			'doc' => $docType,
			'store' => $store,
			'line_count' => count($zsbmsLines),
		]);

		try {
			$saveInstanceResult = $this->executeSaveInstanceWithPayloadFallbacks(
				$operation,
				$interface,
				$entityKey,
				$documentPayload,
				$store
			);
			$payload = $saveInstanceResult['payload'];
			$response = $saveInstanceResult['response'];
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			$this->logOperationError($operation, "Exception while creating ZSBMS invoice", [
				'invoice_id' => $invoiceId,
				'doc' => $docType,
				'exception' => $e->getMessage(),
			]);
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
				'payload' => $payload,
			];
		}

		$statusCode = (int) ($response['Response']['StatusCode'] ?? 0);
		$statusMessage = (string) ($response['Response']['StatusMessage'] ?? '');
		$errorMessage = (string) ($response['Response']['ErrorMessage'] ?? '');
		$isTransportSuccess = in_array($statusCode, [200, 201], true);
		$instanceErrors = $this->extractInstanceErrorsFromApiResponse($response, $entityKey);

		$content = $this->extractDocumentContentFromApiResponse($response, $entityKey);
		$documentMetadata = $this->extractDocumentMetadataFromContent($content);
		$documentRef = $documentMetadata['document_ref'];
		$documentData = $documentMetadata['document'];
		$pdfUrl = $documentMetadata['pdf_url'];
		$isSuccess = $isTransportSuccess || ($documentRef !== '');
		if (!$isTransportSuccess && $documentRef !== '') {
			dol_syslog(__METHOD__ . " treating non-2xx ZSBMS response as successful because document identity was returned: " . $documentRef, LOG_WARNING);
		}
		$pdfAttachment = [
			'attached' => false,
			'file_path' => '',
			'relative_path' => '',
			'error' => '',
		];

		if (!$isSuccess) {
			$this->error = $this->buildInformativeApiErrorMessage(
				'ZSBMS invoice creation failed',
				$statusCode,
				$statusMessage,
				$errorMessage,
				$instanceErrors,
				$interface,
				$entityKey
			);

			$this->persistInvoiceExportErrorsToSyncTable(
				$invoiceId,
				(string) ($invoice->ref_client ?? ''),
				$this->error,
				$instanceErrors,
				$statusCode,
				$interface
			);
			$this->persistApiInstanceErrorsToErrorLog(
				'invoice_export',
				'ZSBMS invoice export failed',
				$instanceErrors,
				[
					'invoice_id' => $invoiceId,
					'invoice_ref' => (string) ($invoice->ref_client ?? ''),
					'status_code' => $statusCode,
					'interface' => $interface,
				]
			);
		}

		if ($isSuccess && $pdfUrl !== '') {
			$pdfAttachment = $this->attachZsbmsPdfToDolibarrInvoice($invoice, $pdfUrl, $documentRef);
			if (!$pdfAttachment['attached'] && $pdfAttachment['error'] !== '') {
				$this->logOperationError($operation, "Unable to attach ZSBMS PDF to Dolibarr invoice", [
					'invoice_id' => $invoiceId,
					'document_ref' => $documentRef,
					'pdf_url' => $pdfUrl,
					'error' => $pdfAttachment['error'],
				]);
			}
		}

		if ($isSuccess) {
			$this->persistInvoiceExportSuccessToSyncTable(
				$invoiceId,
				(string) ($invoice->ref_client ?? ''),
				$statusCode,
				$interface,
				$documentRef,
				$documentData,
				$pdfUrl,
				$pdfAttachment,
				$store
			);
		}

		$result = [
			'success' => $isSuccess,
			'status_code' => $statusCode,
			'status_message' => $statusMessage,
			'error' => $isSuccess ? '' : $this->error,
			'interface' => $interface,
			'entity' => $entityKey,
			'document_ref' => $documentRef,
			'document' => $documentData,
			'pdf_url' => $pdfUrl,
			'pdf_attachment' => $pdfAttachment,
			'instance_errors' => $instanceErrors,
			'payload' => $payload,
			'response' => $response,
		];

		if ($isSuccess) {
			$this->logOperationSuccess($operation, [
				'invoice_id' => $invoiceId,
				'document_ref' => $documentRef,
				'pdf_url' => $pdfUrl,
				'pdf_attached' => !empty($pdfAttachment['attached']),
				'status_code' => $statusCode,
			]);
		} else {
			$this->logOperationError($operation, "ZSBMS invoice creation failed", [
				'invoice_id' => $invoiceId,
				'status_code' => $statusCode,
				'error' => $this->error,
			]);
		}

		return $result;
	}

	/**
	 * Create a ZSBMS invoice/work/import document directly from manual header/line data.
	 *
	 * @param array $manualData Manual invoice data.
	 * @return array Structured result with success flag, status and payload/response
	 */
	public function createZsbmsInvoiceFromManualData(array $manualData)
	{
		global $conf;

		$operation = __METHOD__;

		$docType = strtoupper(trim((string) ($manualData['doc'] ?? $manualData['doc_type'] ?? 'FT')));
		$documentContract = $this->resolveZsbmsManualDocumentContract($docType);
		if ($documentContract === false) {
			$this->error = 'Unsupported document type for ZSBMS creation. Allowed: FT, VD, EC';
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
			];
		}
		$isWorkingDocument = !empty($documentContract['is_working_document']);
		$isDocumentImport = !empty($documentContract['is_document_import']);

		$store = (int) ($manualData['store'] ?? $manualData['loja'] ?? ($conf->global->ZS_API_STORE ?? 0));
		$clientId = (int) ($manualData['cliente'] ?? $manualData['client_id'] ?? 0);
		$supplierId = (int) ($manualData['fornecedor'] ?? $manualData['supplier_id'] ?? 0);
		$paymentType = (int) ($manualData['pagamento'] ?? $manualData['payment_type'] ?? ($conf->global->ZS_DEFAULT_PAYMENT_TYPE ?? 1));

		if ($paymentType <= 0) {
			$paymentType = 1;
		}

		if (array_key_exists('ivaincluido', $manualData)) {
			$ivaIncluido = ((int) $manualData['ivaincluido'] > 0) ? 1 : 0;
		} elseif (array_key_exists('iva_incluido', $manualData)) {
			$ivaIncluido = ((int) $manualData['iva_incluido'] > 0) ? 1 : 0;
		} else {
			$ivaIncluido = !empty($conf->global->ZS_IVA_INCLUIDO) ? 1 : 0;
		}
		$inputPricesIncludeVat = $ivaIncluido;
		if ($isWorkingDocument) {
			// ZSBMS workingdocuments/saveInstance expects EC prices with VAT included.
			$ivaIncluido = 1;
		}

		$rawLines = $manualData['vendas'] ?? $manualData['lines'] ?? [];
		$entityKey = (string) $documentContract['entity_key'];
		$interface = (string) $documentContract['interface'];
		$action = (string) $documentContract['action'];

		if ($isDocumentImport) {
			$documentPayload = $this->buildZsbmsIntegratedDocumentPayloadFromManualData(
				$manualData,
				$store,
				$clientId,
				$paymentType,
				$ivaIncluido
			);
			if ($documentPayload === false) {
				return [
					'success' => false,
					'error' => $this->error,
					'status_code' => 0,
				];
			}
		} else {
			$zsbmsLines = $this->buildZsbmsInvoiceLinesFromManualInput((array) $rawLines, $inputPricesIncludeVat, $ivaIncluido);
			if ($zsbmsLines === false) {
				return [
					'success' => false,
					'error' => $this->error,
					'status_code' => 0,
				];
			}

			$documentPayload = [
				'doc' => $docType,
				'cliente' => $clientId,
				'pagamento' => $paymentType,
				'ivaincluido' => $ivaIncluido,
				'vendas' => $zsbmsLines,
			];
			if ($isWorkingDocument) {
				// Customer orders (EC) must be created as unpaid in ZSBMS.
				$documentPayload['pago'] = 0;
			}

			if ($store > 0) {
				$documentPayload['loja'] = $store;
			}

			if (!$isWorkingDocument) {
				// ZSBMS requires the supplier field on FT/VD payloads, even when it resolves to 0.
				$documentPayload['fornecedor'] = max(0, $supplierId);
			}

			if (isset($manualData['emp']) && $manualData['emp'] !== '') {
				$documentPayload['emp'] = (int) $manualData['emp'];
			}

			$deliveryRaw = $manualData['delivery'] ?? null;
			if (is_array($deliveryRaw)) {
				$delivery = $this->sanitizeZsbmsDeliveryData($deliveryRaw);
				if (!empty($delivery)) {
					$documentPayload['delivery'] = $delivery;
				}
			}

			if ($isWorkingDocument && !empty($manualData['levantamento'])) {
				$documentPayload['levantamento'] = trim((string) $manualData['levantamento']);
			}
		}

		$payload = (strcasecmp($action, 'saveInstances') === 0)
			? [$entityKey => [$documentPayload]]
			: [$entityKey => $documentPayload];

		$this->logDebug($operation, "Sending manual invoice creation payload to ZSBMS", [
			'interface' => $interface,
			'action' => $action,
			'doc' => $docType,
			'store' => $store,
			'line_count' => count((array) ($documentPayload['vendas'] ?? [])),
		]);

		try {
			$saveInstanceResult = $this->executeSaveInstanceWithPayloadFallbacks(
				$operation,
				$interface,
				$entityKey,
				$documentPayload,
				$store,
				$action
			);
			$payload = $saveInstanceResult['payload'];
			$response = $saveInstanceResult['response'];
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			$this->logOperationError($operation, "Exception while creating manual ZSBMS invoice", [
				'doc' => $docType,
				'exception' => $e->getMessage(),
			]);
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
				'payload' => $payload,
			];
		}

		$statusCode = (int) ($response['Response']['StatusCode'] ?? 0);
		$statusMessage = (string) ($response['Response']['StatusMessage'] ?? '');
		$errorMessage = (string) ($response['Response']['ErrorMessage'] ?? '');
		$isTransportSuccess = in_array($statusCode, [200, 201], true);
		$instanceErrors = $this->extractInstanceErrorsFromApiResponse($response, $entityKey);

		$content = $this->extractDocumentContentFromApiResponse($response, $entityKey);
		$documentMetadata = $this->extractDocumentMetadataFromContent($content);
		$documentRef = $documentMetadata['document_ref'];
		$documentData = $documentMetadata['document'];
		$pdfUrl = $documentMetadata['pdf_url'];
		$isSuccess = $isTransportSuccess || ($documentRef !== '');
		if (!$isTransportSuccess && $documentRef !== '') {
			dol_syslog(__METHOD__ . " treating non-2xx ZSBMS response as successful because document identity was returned: " . $documentRef, LOG_WARNING);
		}

		if (!$isSuccess) {
			$this->error = $this->buildInformativeApiErrorMessage(
				'ZSBMS invoice creation failed',
				$statusCode,
				$statusMessage,
				$errorMessage,
				$instanceErrors,
				$interface,
				$entityKey
			);

			$this->persistApiInstanceErrorsToErrorLog(
				'invoice_export_manual',
				'ZSBMS manual invoice export failed',
				$instanceErrors,
				[
					'status_code' => $statusCode,
					'interface' => $interface,
					'doc' => $docType,
					'store' => $store,
				]
			);
		}

		$result = [
			'success' => $isSuccess,
			'status_code' => $statusCode,
			'status_message' => $statusMessage,
			'error' => $isSuccess ? '' : $this->error,
			'interface' => $interface,
			'entity' => $entityKey,
			'document_ref' => $documentRef,
			'document' => $documentData,
			'pdf_url' => $pdfUrl,
			'instance_errors' => $instanceErrors,
			'payload' => $payload,
			'response' => $response,
		];

		if ($isSuccess) {
			$this->logOperationSuccess($operation, [
				'document_ref' => $documentRef,
				'status_code' => $statusCode,
			]);
		} else {
			$this->logOperationError($operation, "Manual ZSBMS invoice creation failed", [
				'status_code' => $statusCode,
				'error' => $this->error,
			]);
		}

		return $result;
	}

	/**
	 * Execute create action with limited saveInstance fallbacks for strict ZSBMS deployments.
	 *
	 * @param string $operation
	 * @param string $interface
	 * @param string $entityKey
	 * @param array  $documentPayload
	 * @param int    $store
	 * @param string $action
	 * @return array{payload: array, response: array}
	 */
	private function executeSaveInstanceWithPayloadFallbacks($operation, $interface, $entityKey, array $documentPayload, $store, $action = 'saveInstance')
	{
		$action = trim((string) $action);
		if ($action === '') {
			$action = 'saveInstance';
		}

		if (strcasecmp($action, 'saveInstance') !== 0) {
			$payload = [$entityKey => [$documentPayload]];
			$response = $this->executeDocumentCreateRequest($interface, $action, $payload, $store);
			return [
				'payload' => $payload,
				'response' => $response,
			];
		}

		$payloadCandidates = $this->buildSaveInstancePayloadCandidates($interface, $entityKey, $documentPayload, $store);
		$lastPayload = [];
		$lastResponse = [];

		foreach ($payloadCandidates as $index => $candidateInfo) {
			$candidatePayload = (array) ($candidateInfo['payload'] ?? []);
			$candidateMeta = (array) ($candidateInfo['meta'] ?? []);

			$lastPayload = $candidatePayload;
			$lastResponse = $this->executeDocumentCreateRequest($interface, $action, $candidatePayload, $store);

			$statusCode = (int) ($lastResponse['Response']['StatusCode'] ?? 0);
			$hasDocumentIdentity = $this->responseContainsZsbmsDocumentIdentity($lastResponse, $entityKey);
			if (in_array($statusCode, [200, 201], true) || $hasDocumentIdentity) {
				if (!in_array($statusCode, [200, 201], true) && $hasDocumentIdentity) {
					$this->logDebug($operation, "Stopping saveInstance retries because ZSBMS returned a document identity with a non-2xx response", [
						'interface' => $interface,
						'entity' => $entityKey,
						'store' => (int) $store,
						'status_code' => $statusCode,
						'candidate' => $candidateMeta,
					]);
				}
				return [
					'payload' => $candidatePayload,
					'response' => $lastResponse,
				];
			}

			$instanceErrors = $this->extractInstanceErrorsFromApiResponse($lastResponse, $entityKey);
			$shouldRetry = $this->shouldRetryWithAlternativeSaveInstancePayload($statusCode, $instanceErrors, $lastResponse);
			$hasNextCandidate = isset($payloadCandidates[$index + 1]);
			if (!$shouldRetry || !$hasNextCandidate) {
				return [
					'payload' => $candidatePayload,
					'response' => $lastResponse,
				];
			}

			$this->logDebug($operation, "Retrying saveInstance with strict fallback payload", [
				'interface' => $interface,
				'entity' => $entityKey,
				'store' => (int) $store,
				'candidate' => $candidateMeta,
				'status_code' => $statusCode,
				'instance_errors' => $instanceErrors,
			]);
		}

		return [
			'payload' => $lastPayload,
			'response' => $lastResponse,
		];
	}

	/**
	 * Execute one document creation request.
	 *
	 * @param string $interface
	 * @param string $action
	 * @param array  $payload
	 * @param int    $store
	 * @return array
	 */
	private function executeDocumentCreateRequest($interface, $action, array $payload, $store)
	{
		$this->zoneSoftApi->setInterface($interface);
		$action = trim((string) $action);
		if ($action === '') {
			$action = 'saveInstance';
		}

		if ((int) $store > 0 && method_exists($this->zoneSoftApi, 'executeForStore')) {
			return $this->zoneSoftApi->executeForStore($action, $payload, (string) $store);
		}
		if ((int) $store > 0 && method_exists($this->zoneSoftApi, 'executeUpdateProducts')) {
			return $this->zoneSoftApi->executeUpdateProducts($action, $payload, (string) $store);
		}

		return $this->zoneSoftApi->execute($action, $payload);
	}

	/**
	 * Build saveInstance payload candidates (base + strict Postman-like variants).
	 *
	 * @param string $interface
	 * @param string $entityKey
	 * @param array  $documentPayload
	 * @param int    $store
	 * @return array<int, array{payload: array, meta: array}>
	 */
	private function buildSaveInstancePayloadCandidates($interface, $entityKey, array $documentPayload, $store = 0)
	{
		$interface = strtolower(trim((string) $interface));
		$entityKey = trim((string) $entityKey);
		$docType = strtoupper(trim((string) ($documentPayload['doc'] ?? '')));

		$candidates = [];
		$seen = [];
		$appendCandidate = function (array $payload, array $meta) use (&$candidates, &$seen) {
			$fingerprint = json_encode($payload);
			if ($fingerprint === false) {
				$fingerprint = md5(serialize($payload));
			}
			if (isset($seen[$fingerprint])) {
				return;
			}
			$seen[$fingerprint] = true;
			$candidates[] = [
				'payload' => $payload,
				'meta' => $meta,
			];
		};

		$appendCandidate(
			[$entityKey => $documentPayload],
			['variant' => 'base', 'shape' => 'object']
		);

		$isInvoiceDoc = ($interface === 'invoicedocuments' && $entityKey === 'invoicedocument' && in_array($docType, ['FT', 'VD'], true));
		$isWorkingDoc = ($interface === 'workingdocuments' && $entityKey === 'workingdocument' && $docType === 'EC');
		$resolvedEmp = (int) ($documentPayload['emp'] ?? 0);
		if (($isInvoiceDoc || $isWorkingDoc) && $resolvedEmp <= 0) {
			$resolvedEmp = $this->resolveFirstZsbmsOperatorCode((int) $store);
		}
		if (($isInvoiceDoc || $isWorkingDoc) && $resolvedEmp > 0) {
			$baseWithEmp = $documentPayload;
			$baseWithEmp['emp'] = $resolvedEmp;
			$appendCandidate(
				[$entityKey => $baseWithEmp],
				['variant' => 'base_with_emp', 'shape' => 'object', 'emp' => $resolvedEmp]
			);
		}

		if ($isInvoiceDoc || $isWorkingDoc) {
			$postmanMinimal = $this->buildPostmanMinimalSaveInstanceDocumentPayload($documentPayload, false, false);
			if ($isWorkingDoc && $resolvedEmp > 0) {
				$postmanMinimalWithEmp = $postmanMinimal;
				$postmanMinimalWithEmp['emp'] = $resolvedEmp;
				$appendCandidate(
					[$entityKey => $postmanMinimalWithEmp],
					['variant' => 'postman_minimal_with_emp', 'shape' => 'object', 'emp' => $resolvedEmp]
				);
			}
			$appendCandidate(
				[$entityKey => $postmanMinimal],
				['variant' => 'postman_minimal', 'shape' => 'object']
			);
			if (!$isWorkingDoc && $resolvedEmp > 0) {
				$postmanMinimalWithEmp = $postmanMinimal;
				$postmanMinimalWithEmp['emp'] = $resolvedEmp;
				$appendCandidate(
					[$entityKey => $postmanMinimalWithEmp],
					['variant' => 'postman_minimal_with_emp', 'shape' => 'object', 'emp' => $resolvedEmp]
				);
			}

			if ($isInvoiceDoc) {
				$postmanSupplierZero = $this->buildPostmanMinimalSaveInstanceDocumentPayload($documentPayload, false, true);
				$appendCandidate(
					[$entityKey => $postmanSupplierZero],
					['variant' => 'postman_minimal_fornecedor_0', 'shape' => 'object']
				);
				if ($resolvedEmp > 0) {
					$postmanSupplierZeroWithEmp = $postmanSupplierZero;
					$postmanSupplierZeroWithEmp['emp'] = $resolvedEmp;
					$appendCandidate(
						[$entityKey => $postmanSupplierZeroWithEmp],
						['variant' => 'postman_minimal_fornecedor_0_with_emp', 'shape' => 'object', 'emp' => $resolvedEmp]
					);
				}

				if ((int) ($documentPayload['cliente'] ?? 0) > 0) {
					$postmanClientZero = $this->buildPostmanMinimalSaveInstanceDocumentPayload($documentPayload, true, true);
					$appendCandidate(
						[$entityKey => $postmanClientZero],
						['variant' => 'postman_minimal_cliente_0_fornecedor_0', 'shape' => 'object']
					);
					if ($resolvedEmp > 0) {
						$postmanClientZeroWithEmp = $postmanClientZero;
						$postmanClientZeroWithEmp['emp'] = $resolvedEmp;
						$appendCandidate(
							[$entityKey => $postmanClientZeroWithEmp],
							['variant' => 'postman_minimal_cliente_0_fornecedor_0_with_emp', 'shape' => 'object', 'emp' => $resolvedEmp]
						);
					}
				}
			}

			if ($resolvedEmp <= 0) {
				foreach ([1, 2] as $fallbackEmp) {
					$baseWithFallbackEmp = $documentPayload;
					$baseWithFallbackEmp['emp'] = (int) $fallbackEmp;
					$appendCandidate(
						[$entityKey => $baseWithFallbackEmp],
						['variant' => 'base_with_fallback_emp', 'shape' => 'object', 'emp' => (int) $fallbackEmp]
					);

					$postmanMinimalWithFallbackEmp = $postmanMinimal;
					$postmanMinimalWithFallbackEmp['emp'] = (int) $fallbackEmp;
					$appendCandidate(
						[$entityKey => $postmanMinimalWithFallbackEmp],
						['variant' => 'postman_minimal_with_fallback_emp', 'shape' => 'object', 'emp' => (int) $fallbackEmp]
					);

					if ($isInvoiceDoc) {
						$postmanSupplierZeroWithFallbackEmp = $this->buildPostmanMinimalSaveInstanceDocumentPayload($documentPayload, false, true);
						$postmanSupplierZeroWithFallbackEmp['emp'] = (int) $fallbackEmp;
						$appendCandidate(
							[$entityKey => $postmanSupplierZeroWithFallbackEmp],
							['variant' => 'postman_minimal_fornecedor_0_with_fallback_emp', 'shape' => 'object', 'emp' => (int) $fallbackEmp]
						);

						if ((int) ($documentPayload['cliente'] ?? 0) > 0) {
							$postmanClientZeroWithFallbackEmp = $this->buildPostmanMinimalSaveInstanceDocumentPayload($documentPayload, true, true);
							$postmanClientZeroWithFallbackEmp['emp'] = (int) $fallbackEmp;
							$appendCandidate(
								[$entityKey => $postmanClientZeroWithFallbackEmp],
								['variant' => 'postman_minimal_cliente_0_fornecedor_0_with_fallback_emp', 'shape' => 'object', 'emp' => (int) $fallbackEmp]
							);
						}
					}
				}
			}
		}

		return $candidates;
	}

	/**
	 * Resolve first operator code in ZSBMS for the target store.
	 *
	 * @param int $store
	 * @return int
	 */
	private function resolveFirstZsbmsOperatorCode($store)
	{
		$store = (int) $store;
		$operation = __METHOD__;

		$extractCodeFromContent = function ($content) {
			if (!is_array($content)) {
				return 0;
			}
			if (isset($content[0]) && is_array($content[0])) {
				return (int) ($content[0]['codigo'] ?? 0);
			}
			if (!empty($content) && !isset($content[0])) {
				return (int) ($content['codigo'] ?? 0);
			}
			return 0;
		};

		$attempts = [
			['interface' => 'operators', 'root_key' => 'operator', 'response_keys' => ['operator'], 'action' => 'getInstances'],
			['interface' => 'operators', 'root_key' => 'operator', 'response_keys' => ['operator'], 'action' => 'getActiveInstances'],
			['interface' => 'employees', 'root_key' => 'employee', 'response_keys' => ['employee', 'operator'], 'action' => 'getInstances'],
			['interface' => 'employees', 'root_key' => 'operator', 'response_keys' => ['employee', 'operator'], 'action' => 'getInstances'],
		];

		foreach ($attempts as $attempt) {
			$interfaceName = (string) ($attempt['interface'] ?? '');
			$rootKey = (string) ($attempt['root_key'] ?? '');
			$action = (string) ($attempt['action'] ?? 'getInstances');
			$responseKeys = (array) ($attempt['response_keys'] ?? []);
			if ($interfaceName === '' || $rootKey === '') {
				continue;
			}

			try {
				$this->zoneSoftApi->setInterface($interfaceName);
				$payload = [
					$rootKey => [
						'order' => 'codigo;ASC',
						'limit' => 1,
					],
				];

				if ($store > 0 && method_exists($this->zoneSoftApi, 'executeForStore')) {
					$response = $this->zoneSoftApi->executeForStore($action, $payload, (string) $store);
				} elseif ($store > 0 && method_exists($this->zoneSoftApi, 'executeUpdateProducts')) {
					$response = $this->zoneSoftApi->executeUpdateProducts($action, $payload, (string) $store);
				} else {
					$response = $this->zoneSoftApi->execute($action, $payload);
				}
			} catch (Exception $e) {
				$this->logDebug($operation, "EMP auto-resolution attempt failed", [
					'store' => $store,
					'interface' => $interfaceName,
					'action' => $action,
					'root_key' => $rootKey,
					'exception' => $e->getMessage(),
				]);
				continue;
			}

			$statusCode = (int) ($response['Response']['StatusCode'] ?? 0);
			if ($statusCode !== 200) {
				$this->logDebug($operation, "EMP auto-resolution returned non-200", [
					'store' => $store,
					'interface' => $interfaceName,
					'action' => $action,
					'root_key' => $rootKey,
					'status_code' => $statusCode,
				]);
				continue;
			}

			$contentRoot = $response['Response']['Content'] ?? [];
			if (!is_array($contentRoot)) {
				$contentRoot = [];
			}

			foreach ($responseKeys as $responseKey) {
				$responseKey = trim((string) $responseKey);
				if ($responseKey === '' || !array_key_exists($responseKey, $contentRoot)) {
					continue;
				}
				$resolvedCode = (int) $extractCodeFromContent($contentRoot[$responseKey]);
				if ($resolvedCode > 0) {
					$this->logDebug($operation, "EMP auto-resolution succeeded", [
						'store' => $store,
						'interface' => $interfaceName,
						'action' => $action,
						'response_key' => $responseKey,
						'emp' => $resolvedCode,
					]);
					return $resolvedCode;
				}
			}

			$resolvedCode = (int) $extractCodeFromContent($contentRoot);
			if ($resolvedCode > 0) {
				$this->logDebug($operation, "EMP auto-resolution succeeded", [
					'store' => $store,
					'interface' => $interfaceName,
					'action' => $action,
					'response_key' => '',
					'emp' => $resolvedCode,
				]);
				return $resolvedCode;
			}
		}

		$this->logDebug($operation, "EMP auto-resolution did not find a valid operator", [
			'store' => $store,
		]);
		return 0;
	}

	/**
	 * Build strict Postman-like saveInstance document payload.
	 *
	 * @param array $documentPayload
	 * @param bool  $forceClientZero
	 * @param bool  $forceSupplierZero
	 * @return array
	 */
	private function buildPostmanMinimalSaveInstanceDocumentPayload(array $documentPayload, $forceClientZero = false, $forceSupplierZero = false)
	{
		$docType = strtoupper(trim((string) ($documentPayload['doc'] ?? '')));
		$out = [
			'doc' => $docType,
			'cliente' => $forceClientZero ? 0 : (int) ($documentPayload['cliente'] ?? 0),
			'pagamento' => (int) ($documentPayload['pagamento'] ?? 0),
			'ivaincluido' => 1,
			'vendas' => $this->buildPostmanMinimalSaveInstanceSalesLines((array) ($documentPayload['vendas'] ?? [])),
		];
		if ($docType === 'EC') {
			$out['pago'] = 0;
		}

		if (in_array($docType, ['FT', 'VD'], true)) {
			$fornecedor = $forceSupplierZero ? 0 : (int) ($documentPayload['fornecedor'] ?? 0);
			$out['fornecedor'] = max(0, $fornecedor);
		}

		return $out;
	}

	/**
	 * Build minimal sales lines expected by saveInstance examples.
	 *
	 * @param array $lines
	 * @return array
	 */
	private function buildPostmanMinimalSaveInstanceSalesLines(array $lines)
	{
		$out = [];

		foreach ($lines as $line) {
			if (!is_array($line)) {
				continue;
			}

			$codigo = (int) ($line['codigo'] ?? 0);
			if ($codigo <= 0) {
				continue;
			}

			$qtd = (float) ($line['qtd'] ?? 0);
			if ($qtd <= 0) {
				$qtd = 1.0;
			}

			$punit = (float) ($line['punit'] ?? 0);
			if (!is_finite($punit) || $punit < 0) {
				$punit = 0.0;
			}

			$desconto = (float) ($line['desconto'] ?? 0);
			if (!is_finite($desconto) || $desconto < 0) {
				$desconto = 0.0;
			}

			$out[] = [
				'codigo' => $codigo,
				'qtd' => $qtd,
				'punit' => $punit,
				'desconto' => $desconto,
			];
		}

		return $out;
	}

	/**
	 * Detect if a response should trigger strict fallback payload retries.
	 *
	 * @param int   $statusCode
	 * @param array $instanceErrors
	 * @param array $response
	 * @return bool
	 */
	private function shouldRetryWithAlternativeSaveInstancePayload($statusCode, array $instanceErrors, array $response)
	{
		if ((int) $statusCode !== 422) {
			return false;
		}

		foreach ($instanceErrors as $instanceError) {
			$property = strtolower(trim((string) ($instanceError['property'] ?? '')));
			$errorText = strtolower(trim((string) ($instanceError['error'] ?? '')));

			if ($property === 'document') {
				return true;
			}

			if (
				$errorText !== ''
				&& (
					strpos($errorText, 'missing propert') !== false
					|| strpos($errorText, '"code":null') !== false
					|| strpos($errorText, "'code':null") !== false
					|| strpos($errorText, '"message":null') !== false
					|| strpos($errorText, "'message':null") !== false
				)
			) {
				return true;
			}
		}

		$statusMessage = strtolower(trim((string) ($response['Response']['StatusMessage'] ?? '')));
		$errorMessage = strtolower(trim((string) ($response['Response']['ErrorMessage'] ?? '')));
		$combined = trim($statusMessage . ' ' . $errorMessage);

		return ($combined !== '' && strpos($combined, 'missing propert') !== false);
	}

	/**
	 * Nullify an FT/VD invoice document previously exported to ZSBMS.
	 *
	 * @param array $document Required keys: doc, serie, numero. Optional: loja, descanulado
	 * @param array $options Optional overrides: store, reason|descanulado
	 * @return array Structured result with success flag, status and payload/response
	 */
	public function setZsbmsInvoiceDocumentAsNulled(array $document, array $options = [])
	{
		return $this->executeZsbmsInvoiceDocumentCancelAction('setInstanceAsNulled', $document, $options);
	}

	/**
	 * Try cancel action for an FT/VD invoice document.
	 * Note: This action may not be available in all ZSBMS deployments for invoicedocuments.
	 *
	 * @param array $document Required keys: doc, serie, numero. Optional: loja, descanulado
	 * @param array $options Optional overrides: store, reason|descanulado
	 * @return array Structured result with success flag, status and payload/response
	 */
	public function cancelZsbmsInvoiceDocument(array $document, array $options = [])
	{
		return $this->executeZsbmsInvoiceDocumentCancelAction('cancelInstance', $document, $options);
	}

	/**
	 * Nullify an FT/VD invoice document using a document reference.
	 * Tries setInstanceAsNulled first and optionally falls back to cancelInstance.
	 *
	 * @param string $documentRef Reference in format DOC_SERIE_NUM (or DOC_SERIE_NUM_DATE)
	 * @param array $options Optional overrides:
	 *                       - store
	 *                       - reason|descanulado
	 *                       - allow_cancel_fallback (default true)
	 * @return array Structured result with success flag, status and payload/response
	 */
	public function nullifyOrCancelZsbmsInvoiceDocumentByReference($documentRef, array $options = [])
	{
		$parsed = $this->parseZsbmsDocumentReference($documentRef);
		if ($parsed === false) {
			$this->error = 'Invalid ZSBMS document reference. Expected format DOC_SERIE_NUM.';
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
				'document_ref' => (string) $documentRef,
			];
		}

		$primary = $this->setZsbmsInvoiceDocumentAsNulled($parsed, $options);
		if (!empty($primary['success'])) {
			return $primary;
		}

		$allowFallback = !array_key_exists('allow_cancel_fallback', $options) || !empty($options['allow_cancel_fallback']);
		if (!$allowFallback) {
			return $primary;
		}

		$fallback = $this->cancelZsbmsInvoiceDocument($parsed, $options);
		$fallback['fallback_from'] = $primary;
		return $fallback;
	}

	/**
	 * Build ZSBMS invoice lines from Dolibarr lines.
	 *
	 * @param array $dolibarrLines Dolibarr invoice lines
	 * @param int   $store Store ID
	 * @param int   $ivaIncluido 1 if punit should include VAT, 0 otherwise
	 * @param int   $fallbackProductCode Optional fallback codigo
	 * @return array|false
	 */
	private function buildZsbmsInvoiceLinesFromDolibarr(array $dolibarrLines, $store, $ivaIncluido, $fallbackProductCode = 0)
	{
		$zsbmsLines = [];

		foreach ($dolibarrLines as $lineIndex => $line) {
			try {
				$qtyRaw = ZSDecimal::normalize((string) ($line->qty ?? '0'));
				$lineTotalHt = ZSDecimal::normalize((string) ($line->total_ht ?? '0'));
				$lineTotalTtc = ZSDecimal::normalize((string) ($line->total_ttc ?? '0'));
				$unitPrice = ZSDecimal::normalize((string) ($line->subprice ?? '0'));
				$discount = ZSDecimal::normalize((string) ($line->remise_percent ?? '0'));
				$vatRate = ZSDecimal::normalize((string) ($line->tva_tx ?? '0'));
			} catch (InvalidArgumentException $e) {
				$this->error = 'Invalid decimal value on Dolibarr invoice line ' . ($lineIndex + 1);
				return false;
			}
			if (ZSDecimal::compare($qtyRaw, '0') === 0) {
				if (ZSDecimal::compare(ZSDecimal::round($lineTotalHt, 2), '0') === 0
					&& ZSDecimal::compare(ZSDecimal::round($lineTotalTtc, 2), '0') === 0) {
					continue;
				}
				$this->error = 'A financial Dolibarr invoice line cannot have zero quantity';
				return false;
			}

			$qty = $qtyRaw;
			$productCode = $this->resolveZsbmsProductCodeFromLine($line, $store, $fallbackProductCode);
			if ($productCode <= 0) {
				$this->error = 'Missing ZSBMS product mapping for invoice line ' . ($lineIndex + 1);
				return false;
			}

			if (ZSDecimal::compare($discount, '0') < 0 || ZSDecimal::compare($discount, '100') > 0) {
				$this->error = 'Invalid discount on Dolibarr invoice line ' . ($lineIndex + 1);
				return false;
			}
			if (ZSDecimal::compare($vatRate, '0') < 0) {
				$this->error = 'Invalid VAT rate on Dolibarr invoice line ' . ($lineIndex + 1);
				return false;
			}
			if (ZSDecimal::compare($qty, '0') < 0 || ZSDecimal::compare($unitPrice, '0') < 0
				|| ZSDecimal::compare($lineTotalHt, '0') < 0 || ZSDecimal::compare($lineTotalTtc, '0') < 0) {
				$this->error = 'Signed corrections and credit-note lines require an explicit supported ZSBMS document type';
				return false;
			}
			if ($ivaIncluido) {
				$unitPrice = ZSDecimal::divideByPowerOfTen(
					ZSDecimal::multiply($unitPrice, ZSDecimal::add('100', $vatRate)),
					2
				);
			}

			$linePayload = [
				'codigo' => (int) $productCode,
				'qtd' => $this->decimalToApiNumber($qty),
				'punit' => $this->decimalToApiNumber($unitPrice),
				'desconto' => $this->decimalToApiNumber($discount),
			];

			$zsbmsLines[] = $linePayload;
		}

		if (empty($zsbmsLines)) {
			$this->error = 'Invoice has no exportable lines for ZSBMS';
			return false;
		}

		return $zsbmsLines;
	}

	/**
	 * Require the stored lines, header, and outbound ZSBMS representation to use
	 * one reconcilable rounding method before a remote financial write.
	 *
	 * @param Facture $invoice Source invoice
	 * @param array $zsbmsLines Prepared ZSBMS sales lines
	 * @param int $ivaIncluido Whether outbound unit prices include VAT
	 * @return bool
	 */
	private function validateDolibarrInvoiceExportTotals($invoice, array $zsbmsLines, $ivaIncluido)
	{
		try {
			$expectedHt = ZSDecimal::round((string) ($invoice->total_ht ?? ''), 2);
			$expectedVat = ZSDecimal::round((string) ($invoice->total_tva ?? ''), 2);
			$expectedTtc = ZSDecimal::round((string) ($invoice->total_ttc ?? ''), 2);
			$lineHt = '0';
			$lineVat = '0';
			$lineTtc = '0';
			$sourceFinancialLines = [];
			foreach ((array) ($invoice->lines ?? []) as $line) {
				$storedLineHt = ZSDecimal::normalize((string) ($line->total_ht ?? '0'));
				$storedLineVat = ZSDecimal::normalize((string) ($line->total_tva ?? '0'));
				$storedLineTtc = ZSDecimal::normalize((string) ($line->total_ttc ?? '0'));
				$lineHt = ZSDecimal::add($lineHt, $storedLineHt);
				$lineVat = ZSDecimal::add($lineVat, $storedLineVat);
				$lineTtc = ZSDecimal::add($lineTtc, $storedLineTtc);
				if (ZSDecimal::compare((string) ($line->qty ?? '0'), '0') !== 0
					|| ZSDecimal::compare(ZSDecimal::round($storedLineHt, 2), '0') !== 0
					|| ZSDecimal::compare(ZSDecimal::round($storedLineTtc, 2), '0') !== 0) {
					$sourceFinancialLines[] = $line;
				}
			}
		} catch (InvalidArgumentException $e) {
			$this->error = 'Dolibarr invoice contains an invalid stored decimal total';
			return false;
		}

		$storedLineTotals = [
			ZSDecimal::round($lineHt, 2),
			ZSDecimal::round($lineVat, 2),
			ZSDecimal::round($lineTtc, 2),
		];
		if ($storedLineTotals !== [$expectedHt, $expectedVat, $expectedTtc]
			|| ZSDecimal::compare($expectedTtc, ZSDecimal::add($expectedHt, $expectedVat)) !== 0) {
			$this->error = 'Dolibarr invoice header and line totals do not reconcile for export';
			return false;
		}
		if (count($zsbmsLines) !== count($sourceFinancialLines)) {
			$this->error = 'Prepared ZSBMS line count does not match the source invoice';
			return false;
		}

		$taxLines = [];
		foreach ($zsbmsLines as $index => $payloadLine) {
			$sourceLine = $sourceFinancialLines[$index];
			$taxLines[] = [
				'quantity' => (string) $payloadLine['qtd'],
				'unit_price' => (string) $payloadLine['punit'],
				'discount_percent' => (string) $payloadLine['desconto'],
				'vat_rate' => (string) ($sourceLine->tva_tx ?? '0'),
				'source_total_ht' => (string) ($sourceLine->total_ht ?? '0'),
				'source_total_ttc' => (string) ($sourceLine->total_ttc ?? '0'),
			];
		}

		try {
			ZSInvoiceTaxCalculator::reconcileSource(
				$taxLines,
				'0',
				!empty($ivaIncluido),
				$expectedHt,
				$expectedVat,
				$expectedTtc,
				[
					'external_id' => 'dolibarr|' . (string) ($invoice->id ?? ''),
					'invoice_number' => (string) ($invoice->ref ?? ''),
				]
			);
		} catch (Throwable $e) {
			$this->error = $e->getMessage();
			return false;
		}

		return true;
	}

	/**
	 * Build ZSBMS lines from manual input rows.
	 *
	 * @param array $rawLines
	 * @param int   $inputPricesIncludeVat 1 when incoming punit already includes VAT, 0 otherwise
	 * @param int   $outputPricesIncludeVat 1 when outgoing punit must include VAT, 0 otherwise
	 * @return array|false
	 */
	private function buildZsbmsInvoiceLinesFromManualInput(array $rawLines, $inputPricesIncludeVat = 0, $outputPricesIncludeVat = 0)
	{
		$lines = [];
		$inputPricesIncludeVat = ((int) $inputPricesIncludeVat > 0) ? 1 : 0;
		$outputPricesIncludeVat = ((int) $outputPricesIncludeVat > 0) ? 1 : 0;

		foreach ($rawLines as $index => $rawLine) {
			if (!is_array($rawLine)) {
				continue;
			}

			$codigoRaw = trim((string) ($rawLine['codigo'] ?? ''));
			$qtdRaw = trim((string) ($rawLine['qtd'] ?? ''));
			$punitRaw = trim((string) ($rawLine['punit'] ?? ''));
			$descontoRaw = trim((string) ($rawLine['desconto'] ?? '0'));
			$vatTxRaw = trim((string) ($rawLine['vat_tx'] ?? '0'));
			$obsRaw = trim((string) ($rawLine['obs'] ?? ''));
			$armazemRaw = trim((string) ($rawLine['armazem'] ?? ''));

			// Ignore fully empty visual rows.
			if ($codigoRaw === '' && $qtdRaw === '' && $punitRaw === '' && $descontoRaw === '' && $obsRaw === '' && $armazemRaw === '') {
				continue;
			}

			$codigo = ctype_digit($codigoRaw) ? (int) $codigoRaw : 0;
			try {
				$qtd = $this->normalizeDecimalValue($qtdRaw);
				$punit = $this->normalizeDecimalValue($punitRaw);
				$desconto = ($descontoRaw === '') ? '0' : $this->normalizeDecimalValue($descontoRaw);
				$vatTx = ($vatTxRaw === '') ? '0' : $this->normalizeDecimalValue($vatTxRaw);
			} catch (InvalidArgumentException $e) {
				$this->error = 'Invalid decimal value on line ' . ($index + 1) . ': ' . $e->getMessage();
				return false;
			}

			if ($codigo <= 0) {
				$this->error = 'Invalid product code on line ' . ($index + 1);
				return false;
			}
			if (ZSDecimal::compare($qtd, '0') <= 0) {
				$this->error = 'Invalid quantity on line ' . ($index + 1);
				return false;
			}
			if (ZSDecimal::compare($punit, '0') < 0) {
				$this->error = 'Invalid unit price on line ' . ($index + 1);
				return false;
			}
			if (ZSDecimal::compare($desconto, '0') < 0 || ZSDecimal::compare($desconto, '100') > 0) {
				$this->error = 'Invalid discount on line ' . ($index + 1);
				return false;
			}
			if (ZSDecimal::compare($vatTx, '0') < 0 || ZSDecimal::compare($vatTx, '100') > 0) {
				$this->error = 'Invalid VAT rate on line ' . ($index + 1);
				return false;
			}
			if ($inputPricesIncludeVat !== $outputPricesIncludeVat) {
				if ($inputPricesIncludeVat === 0 && $outputPricesIncludeVat === 1) {
					$punit = ZSDecimal::divideByPowerOfTen(ZSDecimal::multiply($punit, ZSDecimal::add('100', $vatTx)), 2);
				} elseif ($inputPricesIncludeVat === 1 && $outputPricesIncludeVat === 0) {
					$punit = ZSDecimal::divide(ZSDecimal::multiply($punit, '100'), ZSDecimal::add('100', $vatTx));
				}
			}

			$line = [
				'codigo' => $codigo,
				'qtd' => $this->decimalToApiNumber($qtd),
				'punit' => $this->decimalToApiNumber($punit),
				'desconto' => $this->decimalToApiNumber($desconto),
			];

			if ($armazemRaw !== '' && ctype_digit($armazemRaw)) {
				$line['armazem'] = (int) $armazemRaw;
			}

			$lines[] = $line;
		}

		if (empty($lines)) {
			$this->error = 'At least one valid invoice line is required';
			return false;
		}

		return $lines;
	}

	/**
	 * Resolve the documented ZSBMS creation contract for manual creator document types.
	 *
	 * @param string $docType
	 * @return array|false
	 */
	private function resolveZsbmsManualDocumentContract($docType)
	{
		$docType = strtoupper(trim((string) $docType));

		switch ($docType) {
			case 'FT':
			case 'VD':
				return [
					'interface' => 'invoicedocuments',
					'entity_key' => 'invoicedocument',
					'action' => 'saveInstance',
					'is_working_document' => false,
					'is_document_import' => false,
				];

			case 'EC':
				return [
					'interface' => 'workingdocuments',
					'entity_key' => 'workingdocument',
					'action' => 'saveInstance',
					'is_working_document' => true,
					'is_document_import' => false,
				];
		}

		return false;
	}

	/**
	 * Build a full generic Document payload for FA imports through documents/saveInstances.
	 *
	 * @param array $manualData
	 * @param int   $store
	 * @param int   $clientId
	 * @param int   $paymentType
	 * @param int   $ivaIncluido
	 * @return array|false
	 */
	private function buildZsbmsIntegratedDocumentPayloadFromManualData(array $manualData, $store, $clientId, $paymentType, $ivaIncluido)
	{
		$docType = strtoupper(trim((string) ($manualData['doc'] ?? $manualData['doc_type'] ?? '')));
		$documentNumber = (int) ($manualData['document_number'] ?? $manualData['numero'] ?? 0);
		$documentSerie = trim((string) ($manualData['document_serie'] ?? $manualData['serie'] ?? ''));
		$documentHash = trim((string) ($manualData['document_hash'] ?? $manualData['hash'] ?? ''));
		$documentHashcontrol2 = trim((string) ($manualData['document_hashcontrol2'] ?? $manualData['hashcontrol2'] ?? ''));
		$documentDate = $this->normalizeZsbmsDocumentDateValue($manualData['document_date'] ?? $manualData['data'] ?? '');
		if ($documentDate === '') {
			$documentDate = dol_print_date(dol_now(), '%Y-%m-%d');
		}
		$defaultDateTime = $documentDate . ' ' . dol_print_date(dol_now(), '%H:%M:%S');
		$documentDateTime = $this->normalizeZsbmsDocumentDatetimeValue($manualData['document_datetime'] ?? $manualData['datahora'] ?? $defaultDateTime);
		if ($documentDateTime === '') {
			$documentDateTime = $defaultDateTime;
		}

		if ($docType !== 'FA') {
			$this->error = 'Integrated document payload is only implemented for FA';
			return false;
		}
		if ((int) $store <= 0) {
			$this->error = 'A valid ZSBMS store is required for FA creation';
			return false;
		}
		if ($documentSerie === '') {
			$this->error = 'FA series is required for documents/saveInstances';
			return false;
		}
		if ($documentNumber <= 0) {
			$this->error = 'FA document number is required for documents/saveInstances';
			return false;
		}
		if ($documentHash === '') {
			$this->error = 'FA hash is required for documents/saveInstances';
			return false;
		}
		if ($documentHashcontrol2 === '') {
			$this->error = 'FA hashcontrol2 is required for documents/saveInstances';
			return false;
		}

		$resolvedEmp = isset($manualData['emp']) ? (int) $manualData['emp'] : 0;
		if ($resolvedEmp <= 0) {
			$resolvedEmp = $this->resolveFirstZsbmsOperatorCode((int) $store);
		}

		$lineBuild = $this->buildZsbmsIntegratedDocumentLinesFromManualInput(
			(array) ($manualData['vendas'] ?? $manualData['lines'] ?? []),
			[
				'doc' => $docType,
				'store' => (int) $store,
				'numero' => $documentNumber,
				'serie' => $documentSerie,
				'date' => $documentDate,
				'date_time' => $documentDateTime,
				'emp' => $resolvedEmp,
				'ivaincluido' => (int) $ivaIncluido,
			]
		);
		if ($lineBuild === false) {
			return false;
		}

		$delivery = [];
		if (!empty($manualData['delivery']) && is_array($manualData['delivery'])) {
			$delivery = $this->sanitizeZsbmsDeliveryData($manualData['delivery']);
		}

		$clientName = trim((string) ($manualData['nome'] ?? ''));
		if ($clientName === '') {
			$clientName = 'Cliente ' . ((int) $clientId);
		}

		$countryCode = strtoupper(trim((string) ($manualData['countrycode'] ?? 'PT')));
		if ($countryCode === '') {
			$countryCode = 'PT';
		}

		$clientVat = trim((string) ($manualData['contribuinte'] ?? ''));
		$clientAddress = trim((string) ($manualData['morada'] ?? ''));
		if ($clientAddress === '' && !empty($delivery['morada'])) {
			$clientAddress = (string) $delivery['morada'];
		}

		$clientPhone = trim((string) ($manualData['telefone'] ?? ''));
		if ($clientPhone === '' && !empty($delivery['contacto'])) {
			$clientPhone = (string) $delivery['contacto'];
		}

		$totals = (array) ($lineBuild['totals'] ?? []);
		$totalHt = ZSDecimal::round((string) ($totals['ht'] ?? '0'), 2);
		$totalTtc = ZSDecimal::round((string) ($totals['ttc'] ?? '0'), 2);
		$discountTtc = ZSDecimal::round((string) ($totals['discount_ttc'] ?? '0'), 2);
		$isPaid = !empty($manualData['pago']) ? 1 : 0;
		$deliveryDateTime = $this->normalizeZsbmsDocumentDatetimeValue($delivery['dataentrega'] ?? '');
		$notes = trim((string) ($manualData['observacoes'] ?? $delivery['obs'] ?? ''));

		$payload = [
			'loja' => (int) $store,
			'numero' => $documentNumber,
			'doc' => $docType,
			'serie' => $documentSerie,
			'hash' => $documentHash,
			'data' => $documentDate,
			'cliente' => (int) $clientId,
			'nome' => substr($clientName, 0, 50),
			'liquido' => $this->decimalToApiNumber($totalHt, 2),
			'total' => $this->decimalToApiNumber($totalTtc, 2),
			'anulado' => 0,
			'emp' => max(0, (int) $resolvedEmp),
			'pago' => $isPaid,
			'datapag' => ($isPaid ? $documentDate : null),
			'tipo' => 0,
			'pagamento' => (int) $paymentType,
			'datahora' => $documentDateTime,
			'deve' => $this->decimalToApiNumber($isPaid ? '0' : $totalTtc, 2),
			'contribuinte' => substr($clientVat, 0, 20),
			'morada' => substr($clientAddress, 0, 1024),
			'descricao' => '',
			'telefone' => substr($clientPhone, 0, 50),
			'hashcontrol' => (int) ($manualData['hashcontrol'] ?? 0),
			'ATDocCodeID' => trim((string) ($manualData['ATDocCodeID'] ?? '')),
			'ATDocCodeSource' => trim((string) ($manualData['ATDocCodeSource'] ?? '')),
			'motivo_isencao' => '',
			'isencao' => '',
			'vendas' => (array) ($lineBuild['lines'] ?? []),
			'documentos_pagamento' => [],
			'documentos_info' => [],
			'compensacoes' => [],
			'dpercent' => 0,
			'descontos' => $this->decimalToApiNumber($discountTtc, 2),
			'ivaincluido' => ((int) $ivaIncluido > 0 ? 1 : 0),
			'docforn' => '',
			'numpag' => '',
			'dataDoc' => $documentDate,
			'dataPagamento' => null,
			'diasPagamento' => null,
			'arredondamento' => 0,
			'observacoes' => substr($notes, 0, 255),
			'CashVATScheme' => 0,
			'latitude' => '',
			'longitude' => '',
			'hashcontrol2' => $documentHashcontrol2,
			'countrycode' => substr($countryCode, 0, 10),
			'referencia_pagamento' => '',
			'app_origem' => 3,
			'armdestino' => 0,
			'armorigem' => 0,
			'cartao' => 0,
			'compdoc' => 0,
			'data_alteracao' => $documentDateTime,
			'docext' => 0,
			'freebeecupao' => '',
			'idcx' => 0,
			'impressao' => 0,
			'ljdestino' => 0,
			'ljorigem' => 0,
			'lugar' => 0,
			'mesa' => 0,
			'mesaidx' => 0,
			'numero_manual' => 0,
			'peso' => '',
			'serie_manual' => '',
			'sync' => 1,
			'sync_at' => 0,
			'tipodoc' => 0,
			'viatura' => '',
		];

		if ($deliveryDateTime !== '') {
			$payload['dataentrega'] = $deliveryDateTime;
		}

		return $payload;
	}

	/**
	 * Build full document sales lines for FA imports through documents/saveInstances.
	 *
	 * @param array $rawLines
	 * @param array $context
	 * @return array|false
	 */
	private function buildZsbmsIntegratedDocumentLinesFromManualInput(array $rawLines, array $context)
	{
		$docType = strtoupper(trim((string) ($context['doc'] ?? '')));
		$store = (int) ($context['store'] ?? 0);
		$documentNumber = (int) ($context['numero'] ?? 0);
		$documentSerie = trim((string) ($context['serie'] ?? ''));
		$documentDate = trim((string) ($context['date'] ?? ''));
		$documentDateTime = trim((string) ($context['date_time'] ?? ''));
		$resolvedEmp = (int) ($context['emp'] ?? 0);
		$ivaIncluido = !empty($context['ivaincluido']);

		if ($docType === '' || $documentNumber <= 0 || $documentSerie === '' || $documentDate === '') {
			$this->error = 'Missing FA line context for documents/saveInstances';
			return false;
		}

		$lineDefinitions = [];

		foreach ($rawLines as $index => $rawLine) {
			if (!is_array($rawLine)) {
				continue;
			}

			$codigoRaw = trim((string) ($rawLine['codigo'] ?? ''));
			$qtdRaw = trim((string) ($rawLine['qtd'] ?? ''));
			$punitRaw = trim((string) ($rawLine['punit'] ?? ''));
			$descontoRaw = trim((string) ($rawLine['desconto'] ?? '0'));
			$vatRateRaw = trim((string) ($rawLine['vat_tx'] ?? '0'));
			$obsRaw = trim((string) ($rawLine['obs'] ?? ''));
			$armazemRaw = trim((string) ($rawLine['armazem'] ?? ''));

			if ($codigoRaw === '' && $qtdRaw === '' && $punitRaw === '' && $descontoRaw === '' && $vatRateRaw === '' && $obsRaw === '' && $armazemRaw === '') {
				continue;
			}

			$codigo = ctype_digit($codigoRaw) ? (int) $codigoRaw : 0;
			try {
				$qtd = $this->normalizeDecimalValue($qtdRaw);
				$punit = $this->normalizeDecimalValue($punitRaw);
				$discountPercent = ($descontoRaw === '') ? '0' : $this->normalizeDecimalValue($descontoRaw);
				$vatRate = ($vatRateRaw === '') ? '0' : $this->normalizeDecimalValue($vatRateRaw);
			} catch (InvalidArgumentException $e) {
				$this->error = 'Invalid decimal value on line ' . ($index + 1) . ': ' . $e->getMessage();
				return false;
			}

			if ($codigo <= 0) {
				$this->error = 'Invalid product code on line ' . ($index + 1);
				return false;
			}
			if (ZSDecimal::compare($qtd, '0') <= 0) {
				$this->error = 'Invalid quantity on line ' . ($index + 1);
				return false;
			}
			if (ZSDecimal::compare($punit, '0') < 0) {
				$this->error = 'Invalid unit price on line ' . ($index + 1);
				return false;
			}
			if (ZSDecimal::compare($discountPercent, '0') < 0 || ZSDecimal::compare($discountPercent, '100') > 0) {
				$this->error = 'Invalid discount on line ' . ($index + 1);
				return false;
			}
			if (ZSDecimal::compare($vatRate, '0') < 0 || ZSDecimal::compare($vatRate, '100') > 0) {
				$this->error = 'Invalid VAT rate on line ' . ($index + 1);
				return false;
			}

			$lineDefinitions[] = [
				'codigo' => $codigo,
				'quantity' => $qtd,
				'unit_price' => $punit,
				'discount_percent' => $discountPercent,
				'vat_rate' => $vatRate,
				'armazem' => (ctype_digit($armazemRaw) ? (int) $armazemRaw : 0),
			];
		}

		if (empty($lineDefinitions)) {
			$this->error = 'At least one valid invoice line is required';
			return false;
		}

		try {
			$calculation = ZSInvoiceTaxCalculator::calculate(
				$lineDefinitions,
				'0',
				(bool) $ivaIncluido,
				ZSInvoiceTaxCalculator::ROUND_VAT_GROUP
			);
		} catch (Throwable $e) {
			$this->error = 'Unable to calculate FA invoice totals: ' . $e->getMessage();
			return false;
		}

		$lines = [];
		$totalDiscountTtc = '0';
		foreach ($lineDefinitions as $index => $definition) {
			$amounts = $calculation['lines'][$index];
			$grossInput = ZSDecimal::multiply($definition['quantity'], $definition['unit_price']);
			$discountInput = ZSDecimal::divideByPowerOfTen(
				ZSDecimal::multiply($grossInput, $definition['discount_percent']),
				2
			);
			$discountTtc = $ivaIncluido
				? $discountInput
				: ZSDecimal::divideByPowerOfTen(
					ZSDecimal::multiply($discountInput, ZSDecimal::add('100', $definition['vat_rate'])),
					2
				);
			$totalDiscountTtc = ZSDecimal::add($totalDiscountTtc, $discountTtc);
			$lineId = $index + 1;

			$line = [
				'loja' => $store,
				'numero' => $documentNumber,
				'doc' => $docType,
				'data' => $documentDate,
				'datahora' => $documentDateTime,
				'id' => $lineId,
				'codigo' => $definition['codigo'],
				'iva' => $this->decimalToApiNumber($definition['vat_rate']),
				'qtd' => $this->decimalToApiNumber($definition['quantity']),
				'punit' => $this->decimalToApiNumber($definition['unit_price']),
				'valor' => $this->decimalToApiNumber($amounts['total_ht'], 2),
				'desconto' => $this->decimalToApiNumber(ZSDecimal::round($discountTtc, 2), 2),
				'desconto2' => $this->decimalToApiNumber($definition['discount_percent']),
				'total' => $this->decimalToApiNumber($amounts['total_ttc'], 2),
				'posto' => 0,
				'empid' => max(0, $resolvedEmp),
				'armazem' => $definition['armazem'],
				'qtdstock' => $this->decimalToApiNumber($definition['quantity']),
				'prodstock' => $definition['codigo'],
				'origem' => 0,
				'codprom' => 0,
				'serie' => $documentSerie,
				'lote' => '',
				'desperdicio' => 0,
				'ljorigem' => 0,
				'armorigem' => 0,
				'ljdestino' => 0,
				'armdestino' => 0,
				'unidade' => 1,
				'motivo_isencao' => '',
				'isencao' => '',
				'descforn' => '',
				'referencia' => '',
				'refforn' => '',
				'ddoc' => 0,
				'dpercent' => 0,
				'dvalor' => 0,
				'dextenso' => 0,
				'pvp' => 0,
				'validade' => null,
				'qtdunidades' => $this->decimalToApiNumber($definition['quantity']),
				'PrecoLiquido' => 0,
				'uid_caracteristica' => 0,
				'uid_propriedade' => 0,
				'tipo' => 0,
				'prodorigem' => 0,
			];

			$lines[] = $line;
		}

		return [
			'lines' => $lines,
			'totals' => [
				'ht' => $calculation['total_ht'],
				'ttc' => $calculation['total_ttc'],
				'discount_ttc' => ZSDecimal::round($totalDiscountTtc, 2),
			],
		];
	}

	/**
	 * Normalize document dates to YYYY-MM-DD.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function normalizeZsbmsDocumentDateValue($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}

		if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $value, $matches)) {
			return $matches[1];
		}
		if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+/', $value, $matches)) {
			return $matches[1];
		}

		$timestamp = dol_stringtotime($value);
		return (!empty($timestamp) ? dol_print_date($timestamp, '%Y-%m-%d') : '');
	}

	/**
	 * Normalize document datetimes to YYYY-MM-DD HH:MM:SS.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function normalizeZsbmsDocumentDatetimeValue($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return $value . ' 00:00:00';
		}

		if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})(:\d{2})?$/', $value, $matches)) {
			return $matches[1] . ' ' . $matches[2] . (!empty($matches[3]) ? $matches[3] : ':00');
		}

		$timestamp = dol_stringtotime($value);
		return (!empty($timestamp) ? dol_print_date($timestamp, '%Y-%m-%d %H:%M:%S') : '');
	}

	/**
	 * Normalize decimal values from form input.
	 *
	 * @param mixed $value
	 * @return string Exact plain decimal
	 */
	private function normalizeDecimalValue($value)
	{
		$value = trim(str_replace(["\xC2\xA0", ' '], '', (string) $value));
		if ($value === '') {
			throw new InvalidArgumentException('Empty decimal value');
		}

		$lastComma = strrpos($value, ',');
		$lastDot = strrpos($value, '.');
		if ($lastComma !== false && $lastDot !== false) {
			if ($lastComma > $lastDot) {
				$value = str_replace('.', '', $value);
				$value = str_replace(',', '.', $value);
			} else {
				$value = str_replace(',', '', $value);
			}
		} elseif ($lastComma !== false) {
			$value = str_replace(',', '.', $value);
		}

		return ZSDecimal::normalize($value);
	}

	/**
	 * Convert an exact decimal to the numeric scalar required by the ZSBMS JSON contract.
	 * This is the only floating-point boundary; all calculations are complete beforehand.
	 *
	 * @param string $value Exact decimal
	 * @param int|null $scale Optional final output scale
	 * @return float
	 */
	private function decimalToApiNumber($value, $scale = null)
	{
		$value = $scale === null
			? ZSDecimal::normalize((string) $value)
			: ZSDecimal::round((string) $value, (int) $scale);

		return (float) $value;
	}

	/**
	 * Resolve ZSBMS product code from a Dolibarr invoice line.
	 *
	 * @param object $line Dolibarr line
	 * @param int    $store Store ID
	 * @param int    $fallbackProductCode Optional fallback codigo
	 * @return int
	 */
	private function resolveZsbmsProductCodeFromLine($line, $store, $fallbackProductCode = 0)
	{
		global $db;

		$productId = (int) ($line->fk_product ?? 0);
		if ($productId > 0) {
			$sql = "SELECT codigo FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
			$sql .= " WHERE fk_product = " . $productId;
			if ((int) $store > 0) {
				$sql .= " ORDER BY (loja_zs = " . ((int) $store) . ") DESC, rowid DESC";
			} else {
				$sql .= " ORDER BY rowid DESC";
			}
			$sql .= " " . $db->plimit(1);

			$resql = $db->query($sql);
			if ($resql && ($obj = $db->fetch_object($resql)) && !empty($obj->codigo) && ctype_digit((string) $obj->codigo)) {
				return (int) $obj->codigo;
			}

			$sql = "SELECT ref FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . $productId;
			$resql = $db->query($sql);
			if ($resql && ($obj = $db->fetch_object($resql)) && !empty($obj->ref) && ctype_digit((string) $obj->ref)) {
				return (int) $obj->ref;
			}
		}

		if ((int) $fallbackProductCode > 0) {
			return (int) $fallbackProductCode;
		}

		return 0;
	}

	/**
	 * Keep only supported delivery properties.
	 *
	 * @param array $delivery Raw delivery array
	 * @return array
	 */
	private function sanitizeZsbmsDeliveryData(array $delivery)
	{
		$allowedKeys = ['morada', 'contacto', 'dataentrega', 'obs'];
		$out = [];

		foreach ($allowedKeys as $key) {
			if (isset($delivery[$key]) && trim((string) $delivery[$key]) !== '') {
				$out[$key] = trim((string) $delivery[$key]);
			}
		}

		return $out;
	}

	/**
	 * Execute a ZSBMS cancel/nullify action for an FT/VD invoice document.
	 *
	 * @param string $action Action name (setInstanceAsNulled|cancelInstance)
	 * @param array $document Required keys: doc, serie, numero
	 * @param array $options Optional overrides: store, reason|descanulado
	 * @return array Structured result with success flag, status and payload/response
	 */
	private function executeZsbmsInvoiceDocumentCancelAction($action, array $document, array $options = [])
	{
		global $conf;

		$operation = __METHOD__;
		$action = trim((string) $action);
		if ($action === '') {
			$this->error = 'Missing cancel action';
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
			];
		}

		$docType = strtoupper(trim((string) ($document['doc'] ?? '')));
		$serie = trim((string) ($document['serie'] ?? ''));
		$numero = (int) ($document['numero'] ?? 0);
		if (!in_array($docType, ['FT', 'VD'], true) || $serie === '' || $numero <= 0) {
			$this->error = 'Invalid document payload. Required: doc (FT|VD), serie, numero';
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
			];
		}

		$store = isset($options['store']) ? (int) $options['store'] : (int) ($document['loja'] ?? ($conf->global->ZS_API_STORE ?? 0));
		$reason = trim((string) ($options['descanulado'] ?? $options['reason'] ?? ($document['descanulado'] ?? 'Nullified from Dolibarr')));
		if ($reason === '') {
			$reason = 'Nullified from Dolibarr';
		}

		$payloadDocument = [
			'doc' => $docType,
			'serie' => $serie,
			'numero' => $numero,
			'descanulado' => $reason,
		];
		if ($store > 0) {
			$payloadDocument['loja'] = $store;
		}

		$entityKey = 'invoicedocument';
		$interface = 'invoicedocuments';
		$payload = [
			$entityKey => $payloadDocument,
		];

		try {
			$this->zoneSoftApi->setInterface($interface);
			if ($store > 0 && method_exists($this->zoneSoftApi, 'executeForStore')) {
				$response = $this->zoneSoftApi->executeForStore($action, $payload, (string) $store);
			} elseif ($store > 0 && method_exists($this->zoneSoftApi, 'executeUpdateProducts')) {
				$response = $this->zoneSoftApi->executeUpdateProducts($action, $payload, (string) $store);
			} else {
				$response = $this->zoneSoftApi->execute($action, $payload);
			}
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			$this->logOperationError($operation, "Exception while calling {$action}", [
				'action' => $action,
				'doc' => $docType,
				'serie' => $serie,
				'numero' => $numero,
				'store' => $store,
				'exception' => $e->getMessage(),
			]);
			return [
				'success' => false,
				'error' => $this->error,
				'status_code' => 0,
				'action' => $action,
				'payload' => $payload,
			];
		}

		$statusCode = (int) ($response['Response']['StatusCode'] ?? 0);
		$statusMessage = (string) ($response['Response']['StatusMessage'] ?? '');
		$errorMessage = (string) ($response['Response']['ErrorMessage'] ?? '');
		$instanceErrors = $this->extractInstanceErrorsFromApiResponse($response, $entityKey);
		$isSuccess = in_array($statusCode, [200, 201], true);

		if (!$isSuccess) {
			$this->error = $this->buildInformativeApiErrorMessage(
				'ZSBMS invoice nullify/cancel failed',
				$statusCode,
				$statusMessage,
				$errorMessage,
				$instanceErrors,
				$interface,
				$entityKey
			);

			$this->persistApiInstanceErrorsToErrorLog(
				'invoice_export_cancel',
				'ZSBMS invoice nullify/cancel failed',
				$instanceErrors,
				[
					'action' => $action,
					'status_code' => $statusCode,
					'interface' => $interface,
					'doc' => $docType,
					'serie' => $serie,
					'numero' => $numero,
					'store' => $store,
				]
			);
		}

		return [
			'success' => $isSuccess,
			'status_code' => $statusCode,
			'status_message' => $statusMessage,
			'error' => $isSuccess ? '' : $this->error,
			'action' => $action,
			'interface' => $interface,
			'entity' => $entityKey,
			'document_ref' => $docType . '_' . $serie . '_' . $numero,
			'document' => [
				'doc' => $docType,
				'serie' => $serie,
				'numero' => $numero,
			],
			'instance_errors' => $instanceErrors,
			'payload' => $payload,
			'response' => $response,
		];
	}

	/**
	 * Extract response content for a given document entity.
	 *
	 * @param array $response API response payload
	 * @param string $entityKey Entity key in Response.Content
	 * @return array|null
	 */
	private function extractDocumentContentFromApiResponse(array $response, $entityKey)
	{
		$content = $response['Response']['Content'][$entityKey] ?? null;
		if (is_array($content) && isset($content[0]) && is_array($content[0])) {
			$content = $content[0];
		}

		return is_array($content) ? $content : null;
	}

	/**
	 * Extract document identifiers and pdf URL from a response content node.
	 *
	 * @param array|null $content Entity content from Response.Content
	 * @return array
	 */
	private function extractDocumentMetadataFromContent($content)
	{
		$doc = '';
		$serie = '';
		$numero = 0;
		$documentRef = '';
		$pdfUrl = '';

		if (is_array($content)) {
			$doc = isset($content['doc']) ? strtoupper(trim((string) $content['doc'])) : '';
			$serie = isset($content['serie']) ? trim((string) $content['serie']) : '';
			$numeroRaw = isset($content['numero']) ? trim((string) $content['numero']) : '';
			if ($numeroRaw !== '' && is_numeric($numeroRaw)) {
				$numero = (int) $numeroRaw;
			}

			if ($doc !== '' && $serie !== '' && $numero > 0) {
				$documentRef = $doc . '_' . $serie . '_' . $numero;
			}

			$pdfUrlRaw = isset($content['pdf']) ? trim((string) $content['pdf']) : '';
			if ($pdfUrlRaw !== '' && filter_var($pdfUrlRaw, FILTER_VALIDATE_URL)) {
				$pdfUrl = $pdfUrlRaw;
			}
		}

		return [
			'document_ref' => $documentRef,
			'document' => [
				'doc' => $doc,
				'serie' => $serie,
				'numero' => $numero,
			],
			'pdf_url' => $pdfUrl,
		];
	}

	/**
	 * Check whether a create response already contains a document identity.
	 *
	 * @param array  $response API response payload
	 * @param string $entityKey Entity key in Response.Content
	 * @return bool
	 */
	private function responseContainsZsbmsDocumentIdentity(array $response, $entityKey)
	{
		$content = $this->extractDocumentContentFromApiResponse($response, $entityKey);
		$metadata = $this->extractDocumentMetadataFromContent($content);

		return trim((string) ($metadata['document_ref'] ?? '')) !== '';
	}

	/**
	 * Parse document references in format DOC_SERIE_NUM or DOC_SERIE_NUM_DATE.
	 *
	 * @param string $documentRef
	 * @return array|false
	 */
	private function parseZsbmsDocumentReference($documentRef)
	{
		$documentRef = trim((string) $documentRef);
		if ($documentRef === '') {
			return false;
		}

		$parts = explode('_', $documentRef);
		if (count($parts) < 3) {
			return false;
		}

		$doc = strtoupper(trim((string) array_shift($parts)));
		if ($doc === '') {
			return false;
		}

		$lastPart = trim((string) array_pop($parts));
		$numeroPart = $lastPart;
		if (
			count($parts) >= 2
			&& ctype_digit($lastPart)
			&& strlen($lastPart) === 8
			&& ctype_digit((string) end($parts))
		) {
			$numeroPart = (string) array_pop($parts);
		}

		$serie = trim(implode('_', $parts));
		if ($serie === '' || !ctype_digit((string) $numeroPart)) {
			return false;
		}

		return [
			'doc' => $doc,
			'serie' => $serie,
			'numero' => (int) $numeroPart,
		];
	}

	/**
	 * Download and attach ZSBMS PDF into Dolibarr invoice documents.
	 *
	 * @param Facture $invoice Dolibarr invoice object
	 * @param string $pdfUrl ZSBMS temporary PDF URL
	 * @param string $documentRef ZSBMS document reference
	 * @return array
	 */
	private function attachZsbmsPdfToDolibarrInvoice($invoice, $pdfUrl, $documentRef)
	{
		global $conf;

		$result = [
			'attached' => false,
			'file_path' => '',
			'relative_path' => '',
			'error' => '',
		];

		if (!is_object($invoice) || empty($invoice->id) || empty($invoice->ref)) {
			$result['error'] = 'Missing invoice context for PDF attachment';
			return $result;
		}

		if (!filter_var($pdfUrl, FILTER_VALIDATE_URL)) {
			$result['error'] = 'Invalid PDF URL';
			return $result;
		}

		$scheme = strtolower((string) parse_url($pdfUrl, PHP_URL_SCHEME));
		if (!in_array($scheme, ['http', 'https'], true)) {
			$result['error'] = 'Unsupported PDF URL scheme';
			return $result;
		}

		require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

		$entityId = !empty($invoice->entity) ? (int) $invoice->entity : (int) ($conf->entity ?? 1);
		$invoiceOutputRoot = $conf->invoice->multidir_output[$entityId] ?? '';
		$invoiceOutputRoot = trim((string) $invoiceOutputRoot);
		if ($invoiceOutputRoot === '') {
			$result['error'] = 'Invoice output directory is not configured';
			return $result;
		}

		$invoiceDirName = dol_sanitizeFileName((string) $invoice->ref);
		if ($invoiceDirName === '') {
			$result['error'] = 'Invalid invoice reference for attachment path';
			return $result;
		}

		$targetDir = rtrim($invoiceOutputRoot, '/\\') . '/' . $invoiceDirName;
		if (!is_dir(dol_osencode($targetDir)) && dol_mkdir($targetDir) < 0) {
			$result['error'] = 'Failed to create invoice attachment directory';
			return $result;
		}

		$baseName = $documentRef !== '' ? $documentRef : ('zsbms_' . $invoiceDirName);
		$baseName = dol_sanitizeFileName($baseName);
		if ($baseName === '') {
			$baseName = 'zsbms_invoice_' . (int) $invoice->id;
		}

		$fileName = $baseName . '.pdf';
		$destPath = $targetDir . '/' . $fileName;
		if (file_exists(dol_osencode($destPath))) {
			$fileName = $baseName . '_' . date('YmdHis') . '.pdf';
			$destPath = $targetDir . '/' . $fileName;
		}

		$download = $this->downloadRemoteFileToPath($pdfUrl, $destPath);
		if (!$download['success']) {
			$result['error'] = $download['error'];
			return $result;
		}

		$addIndexResult = addFileIntoDatabaseIndex($targetDir, $fileName, $pdfUrl, 'api', 0, $invoice);
		if ((int) $addIndexResult <= 0) {
			dol_syslog(__METHOD__ . " - Unable to index invoice attachment in ECM: " . $fileName, LOG_WARNING);
		}

		$relativePath = 'facture/' . $invoiceDirName . '/' . $fileName;

		$result['attached'] = true;
		$result['file_path'] = $destPath;
		$result['relative_path'] = $relativePath;
		return $result;
	}

	/**
	 * Download a remote file to local path.
	 *
	 * @param string $url
	 * @param string $destPath
	 * @return array{success:bool,error:string}
	 */
	private function downloadRemoteFileToPath($url, $destPath)
	{
		$result = ['success' => false, 'error' => ''];
		$destPathEncoded = dol_osencode($destPath);

		if (function_exists('curl_init')) {
			$fp = @fopen($destPathEncoded, 'wb');
			if ($fp === false) {
				$result['error'] = 'Unable to create local PDF file';
				return $result;
			}

			$curl = curl_init($url);
			if ($curl === false) {
				fclose($fp);
				$result['error'] = 'cURL initialization failed';
				return $result;
			}

			curl_setopt_array($curl, [
				CURLOPT_FILE => $fp,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_TIMEOUT => 45,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_SSL_VERIFYPEER => true,
			]);

			$curlOk = curl_exec($curl);
			$httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
			$curlErrno = (int) curl_errno($curl);
			$curlError = (string) curl_error($curl);
			curl_close($curl);
			fclose($fp);

			if ($curlOk === false) {
				@unlink($destPathEncoded);
				$result['error'] = "cURL download failed (errno {$curlErrno}): {$curlError}";
				return $result;
			}

			if ($httpCode < 200 || $httpCode >= 300) {
				@unlink($destPathEncoded);
				$result['error'] = "Unexpected HTTP status while downloading PDF: {$httpCode}";
				return $result;
			}
		} else {
			$context = stream_context_create([
				'http' => [
					'method' => 'GET',
					'timeout' => 45,
					'follow_location' => 1,
				],
			]);
			$data = @file_get_contents($url, false, $context);
			if ($data === false) {
				$result['error'] = 'Download failed (file_get_contents)';
				return $result;
			}
			if (@file_put_contents($destPathEncoded, $data) === false) {
				$result['error'] = 'Unable to save downloaded PDF';
				return $result;
			}
		}

		$fileSize = @filesize($destPathEncoded);
		if ($fileSize === false || (int) $fileSize <= 0) {
			@unlink($destPathEncoded);
			$result['error'] = 'Downloaded PDF is empty';
			return $result;
		}

		$result['success'] = true;
		return $result;
	}

	/**
	 * Persist export success metadata (document + pdf URL) to sync table when matching row exists.
	 *
	 * @param int $invoiceId
	 * @param string $invoiceRef
	 * @param int $statusCode
	 * @param string $interface
	 * @param string $documentRef
	 * @param array $document
	 * @param string $pdfUrl
	 * @param array $pdfAttachment
	 * @param int $store
	 * @return void
	 */
	private function persistInvoiceExportSuccessToSyncTable($invoiceId, $invoiceRef, $statusCode, $interface, $documentRef, array $document, $pdfUrl, array $pdfAttachment, $store)
	{
		global $db, $conf;

		$invoiceId = (int) $invoiceId;
		$invoiceRef = trim((string) $invoiceRef);
		if ($invoiceId <= 0 && $invoiceRef === '') {
			return;
		}

		$where = [];
		if ($invoiceId > 0) {
			$where[] = "fk_facture = " . $invoiceId;
		}
		if ($invoiceRef !== '') {
			$where[] = "invoice_ref = '" . $db->escape($invoiceRef) . "'";
		}
		if (empty($where)) {
			return;
		}

		$sql = "SELECT rowid, sync_context FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
		$sql .= " WHERE (" . implode(' OR ', $where) . ")";
		$sql .= " AND entity IN (0, " . (int) ($conf->entity ?? 1) . ")";
		$sql .= " ORDER BY rowid DESC";
		$sql .= " " . $db->plimit(1, 0);

		$resql = $db->query($sql);
		if (!$resql || $db->num_rows($resql) === 0) {
			return;
		}

		$obj = $db->fetch_object($resql);
		$context = [];
		if (!empty($obj->sync_context)) {
			$decoded = json_decode($obj->sync_context, true);
			if (is_array($decoded)) {
				$context = $decoded;
			}
		}

		$context['zsbms_export'] = [
			'status_code' => (int) $statusCode,
			'interface' => (string) $interface,
			'document_ref' => (string) $documentRef,
			'document' => $document,
			'pdf_url' => (string) $pdfUrl,
			'pdf_attached' => !empty($pdfAttachment['attached']),
			'pdf_relative_path' => (string) ($pdfAttachment['relative_path'] ?? ''),
			'store' => (int) $store,
			'updated_at' => date('c'),
		];

		$contextJson = json_encode($context, JSON_UNESCAPED_UNICODE);
		if ($contextJson === false) {
			$contextJson = json_encode(['zsbms_export' => ['status_code' => (int) $statusCode]]);
		}

		$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch SET";
		$sqlUpdate .= " sync_context = '" . $db->escape((string) $contextJson) . "',";
		if (trim((string) $pdfUrl) !== '') {
			$sqlUpdate .= " last_main_doc = '" . $db->escape(dol_trunc((string) $pdfUrl, 255)) . "',";
		}
		$sqlUpdate .= " date_modification = NOW()";
		$sqlUpdate .= " WHERE rowid = " . (int) $obj->rowid;

		if (!$db->query($sqlUpdate)) {
			dol_syslog(__METHOD__ . " - Failed to persist export metadata into zsinvoicesynch: " . $db->lasterror(), LOG_WARNING);
		}
	}

	/**
	 * Extract per-instance validation errors from a ZSBMS response.
	 *
	 * @param array $response API response (decoded as associative array)
	 * @param string|null $entityKey Preferred entity key inside Response.Content
	 * @return array<int, array<string, string>>
	 */
	private function extractInstanceErrorsFromApiResponse(array $response, $entityKey = null)
	{
		$errors = [];
		$content = $response['Response']['Content'] ?? null;
		if (!is_array($content)) {
			return $errors;
		}

		if ($entityKey !== null && array_key_exists($entityKey, $content)) {
			$content = [$entityKey => $content[$entityKey]];
		}

		$walker = function ($node, $path = '') use (&$walker, &$errors) {
			if (!is_array($node)) {
				return;
			}

			if (!empty($node['_errors']) && is_array($node['_errors'])) {
				foreach ($node['_errors'] as $item) {
					if (!is_array($item)) {
						continue;
					}
					$errors[] = [
						'path' => (string) $path,
						'property' => (string) ($item['property'] ?? ''),
						'error' => (string) ($item['error'] ?? ''),
					];
				}
			}

			foreach ($node as $key => $child) {
				if ($key === '_errors') {
					continue;
				}
				if (!is_array($child)) {
					continue;
				}

				$segment = (string) $key;
				if (ctype_digit($segment)) {
					$childPath = $path === '' ? '[' . $segment . ']' : $path . '[' . $segment . ']';
				} else {
					$childPath = $path === '' ? $segment : $path . '.' . $segment;
				}

				$walker($child, $childPath);
			}
		};

		$walker($content, '');

		return $errors;
	}

	/**
	 * Build a short summary from parsed per-instance errors.
	 *
	 * @param array<int, array<string, string>> $instanceErrors
	 * @param int $maxItems
	 * @return string
	 */
	private function buildInstanceErrorsSummary(array $instanceErrors, $maxItems = 3)
	{
		if (empty($instanceErrors)) {
			return '';
		}

		$items = [];
		$slice = array_slice($instanceErrors, 0, max(1, (int) $maxItems));
		foreach ($slice as $err) {
			$prefix = '';
			if (!empty($err['path'])) {
				$prefix .= $err['path'] . ': ';
			}
			if (!empty($err['property'])) {
				$prefix .= $err['property'] . ' - ';
			}
			$items[] = trim($prefix . (string) ($err['error'] ?? 'Validation error'));
		}

		$summary = implode(' | ', $items);
		$extra = count($instanceErrors) - count($slice);
		if ($extra > 0) {
			$summary .= ' | +' . $extra . ' more validation error(s)';
		}

		return $summary;
	}

	/**
	 * Build a clearer API failure message with status code and actionable hints.
	 *
	 * @param string $fallbackMessage
	 * @param int $statusCode
	 * @param string $statusMessage
	 * @param string $errorMessage
	 * @param array<int, array<string, string>> $instanceErrors
	 * @param string $interface
	 * @param string $entityKey
	 * @return string
	 */
	private function buildInformativeApiErrorMessage($fallbackMessage, $statusCode, $statusMessage, $errorMessage, array $instanceErrors, $interface = '', $entityKey = '')
	{
		$parts = [];

		$instanceErrorSummary = $this->buildInstanceErrorsSummary($instanceErrors);
		if (trim($instanceErrorSummary) !== '') {
			$parts[] = trim($instanceErrorSummary);
		}
		if (trim((string) $errorMessage) !== '') {
			$parts[] = trim((string) $errorMessage);
		}
		if (trim((string) $statusMessage) !== '') {
			$parts[] = trim((string) $statusMessage);
		}

		$dedup = [];
		$baseSegments = [];
		foreach ($parts as $segment) {
			$segment = trim((string) $segment);
			if ($segment === '') {
				continue;
			}
			$hash = strtolower($segment);
			if (isset($dedup[$hash])) {
				continue;
			}
			$dedup[$hash] = true;
			$baseSegments[] = $segment;
		}

		$message = implode(' | ', $baseSegments);
		if ($message === '') {
			$message = trim((string) $fallbackMessage);
		}

		$hints = [];
		if ((int) $statusCode > 0) {
			$hints[] = 'API status code: ' . ((int) $statusCode);
		}

		if ($this->isOpaqueDocumentApiError($instanceErrors, $errorMessage, $statusMessage)) {
			$interfaceName = strtolower(trim((string) $interface));
			$entityName = strtolower(trim((string) $entityKey));
			$isWorkingDocument = ($interfaceName === 'workingdocuments' || $entityName === 'workingdocument');
			$isDocumentImport = ($interfaceName === 'documents' || $entityName === 'document');

			if ($isDocumentImport) {
				$endpoint = '/v3/documents/saveInstances';
				$scope = 'documents';
			} else {
				$endpoint = $isWorkingDocument ? '/v3/workingdocuments/saveInstance' : '/v3/invoicedocuments/saveInstance';
				$scope = $isWorkingDocument ? 'workingdocuments' : 'invoicedocuments';
			}

			$hints[] = "Causa provavel: a integracao/permissao da API ZSBMS para '{$scope}' nao esta ativa para este client_id/loja.";
			$hints[] = "Peca a ZoneSoft para ativar o endpoint '{$endpoint}' para estas credenciais.";
		}

		if (!empty($hints)) {
			$message = trim($message . ' | ' . implode(' ', $hints));
		}

		if ($message === '') {
			$message = trim((string) $fallbackMessage);
		}

		return $message;
	}

	/**
	 * Detect ZSBMS opaque validation errors like {'code':null,'message':null}.
	 *
	 * @param array<int, array<string, string>> $instanceErrors
	 * @param string $errorMessage
	 * @param string $statusMessage
	 * @return bool
	 */
	private function isOpaqueDocumentApiError(array $instanceErrors, $errorMessage = '', $statusMessage = '')
	{
		if ($this->containsNullCodeNullMessageSignature($errorMessage) || $this->containsNullCodeNullMessageSignature($statusMessage)) {
			return true;
		}

		foreach ($instanceErrors as $instanceError) {
			if (!is_array($instanceError)) {
				continue;
			}
			$errorLine = (string) ($instanceError['error'] ?? '');
			if (!$this->containsNullCodeNullMessageSignature($errorLine)) {
				continue;
			}

			$property = strtolower(trim((string) ($instanceError['property'] ?? '')));
			if ($property === '' || $property === 'document' || $property === 'doc') {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if text contains the signature code:null + message:null.
	 *
	 * @param string $text
	 * @return bool
	 */
	private function containsNullCodeNullMessageSignature($text)
	{
		$normalized = strtolower(trim((string) $text));
		if ($normalized === '') {
			return false;
		}

		$normalized = str_replace(["\r", "\n", "\t", " "], '', $normalized);
		$hasCodeNull = (
			strpos($normalized, '"code":null') !== false
			|| strpos($normalized, "'code':null") !== false
			|| strpos($normalized, 'code:null') !== false
		);
		$hasMessageNull = (
			strpos($normalized, '"message":null') !== false
			|| strpos($normalized, "'message':null") !== false
			|| strpos($normalized, 'message:null') !== false
		);

		return $hasCodeNull && $hasMessageNull;
	}

	/**
	 * Persist API instance errors in generic error log table.
	 *
	 * @param string $category Error category
	 * @param string $message Message to persist
	 * @param array<int, array<string, string>> $instanceErrors Parsed errors
	 * @param array $context Additional context
	 * @return void
	 */
	private function persistApiInstanceErrorsToErrorLog($category, $message, array $instanceErrors, array $context = [])
	{
		global $db, $conf;

		if (empty($instanceErrors)) {
			return;
		}

		$table = MAIN_DB_PREFIX . 'dolizsynch_error_log';
		$payload = array_merge($context, [
			'instance_errors' => $instanceErrors,
		]);
		$contextJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
		if ($contextJson === false) {
			$contextJson = json_encode(['instance_errors' => $instanceErrors]);
		}

		$sql = "INSERT INTO " . $table . " (entity, message, severity, category, context, date_creation) VALUES (";
		$sql .= (int) ($conf->entity ?? 1) . ", ";
		$sql .= "'" . $db->escape((string) $message) . "', ";
		$sql .= "'ERROR', ";
		$sql .= "'" . $db->escape((string) $category) . "', ";
		$sql .= "'" . $db->escape((string) $contextJson) . "', ";
		$sql .= "'" . $db->idate(dol_now()) . "'";
		$sql .= ")";

		if (!$db->query($sql)) {
			dol_syslog(__METHOD__ . " - Failed to persist instance errors to " . $table . ": " . $db->lasterror(), LOG_WARNING);
		}
	}

	/**
	 * Persist invoice export errors into invoice sync table when matching row exists.
	 *
	 * @param int $invoiceId Dolibarr invoice ID
	 * @param string $invoiceRef External invoice reference
	 * @param string $errorMessage Error summary
	 * @param array<int, array<string, string>> $instanceErrors Parsed errors
	 * @param int $statusCode API status code
	 * @param string $interface API interface used
	 * @return void
	 */
	private function persistInvoiceExportErrorsToSyncTable($invoiceId, $invoiceRef, $errorMessage, array $instanceErrors, $statusCode, $interface)
	{
		global $db, $conf;

		$invoiceId = (int) $invoiceId;
		$invoiceRef = trim((string) $invoiceRef);
		if ($invoiceId <= 0 && $invoiceRef === '') {
			return;
		}

		$where = [];
		if ($invoiceId > 0) {
			$where[] = "fk_facture = " . $invoiceId;
		}
		if ($invoiceRef !== '') {
			$where[] = "invoice_ref = '" . $db->escape($invoiceRef) . "'";
		}
		if (empty($where)) {
			return;
		}

		$sql = "SELECT rowid, sync_context FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
		$sql .= " WHERE (" . implode(' OR ', $where) . ")";
		$sql .= " AND entity IN (0, " . (int) ($conf->entity ?? 1) . ")";
		$sql .= " ORDER BY rowid DESC";
		$sql .= " " . $db->plimit(1, 0);

		$resql = $db->query($sql);
		if (!$resql || $db->num_rows($resql) === 0) {
			return;
		}

		$obj = $db->fetch_object($resql);
		$context = [];
		if (!empty($obj->sync_context)) {
			$decoded = json_decode($obj->sync_context, true);
			if (is_array($decoded)) {
				$context = $decoded;
			}
		}

		$context['zsbms_export'] = [
			'status_code' => (int) $statusCode,
			'interface' => (string) $interface,
			'instance_errors' => $instanceErrors,
			'updated_at' => date('c'),
		];

		$contextJson = json_encode($context, JSON_UNESCAPED_UNICODE);
		if ($contextJson === false) {
			$contextJson = json_encode(['zsbms_export' => ['status_code' => (int) $statusCode]]);
		}

		$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch SET";
		$sqlUpdate .= " error_message = '" . $db->escape((string) $errorMessage) . "',";
		$sqlUpdate .= " sync_context = '" . $db->escape((string) $contextJson) . "',";
		$sqlUpdate .= " date_modification = NOW()";
		$sqlUpdate .= " WHERE rowid = " . (int) $obj->rowid;

		if (!$db->query($sqlUpdate)) {
			dol_syslog(__METHOD__ . " - Failed to persist export errors into zsinvoicesynch: " . $db->lasterror(), LOG_WARNING);
		}
	}
}
