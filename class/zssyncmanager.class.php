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
 * \file        class/zssyncmanager.class.php
 * \ingroup     DoliZSynch
 * \brief       Synchronization state management for ZoneSoft integration
 */

require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zssyncstatus.class.php';

/**
 * ZoneSoft Synchronization State Manager
 *
 * This class provides state management and tracking for synchronization processes
 */
class ZSSyncManager
{
    /** @var DoliDB Database connection */
    private $db;

    /** @var string Table name for sync records */
    private $table;

    /** @var string Primary key field name */
    private $keyField;

    public function __construct(DoliDB $db, $table, $keyField = 'invoice_ref')
    {
        $this->db = $db;
        $this->table = $table;
        $this->keyField = $keyField;
    }

    /**
     * Resolve current working entity.
     *
     * @return int
     */
    private function getCurrentEntity()
    {
        global $conf;

        return (int) ($conf->entity ?? 1);
    }

    /**
     * Get configured maximum retry attempts per invoice.
     *
     * @return int
     */
    private function getMaxSyncTriesPerInvoice()
    {
        global $conf;

        $maxTries = 3;
        if (function_exists('getDolGlobalInt')) {
            $maxTries = (int) getDolGlobalInt('ZS_MAX_SYNCH_TRIES_PER_INVOICE', 3);
        } elseif (isset($conf->global->ZS_MAX_SYNCH_TRIES_PER_INVOICE)) {
            $maxTries = (int) $conf->global->ZS_MAX_SYNCH_TRIES_PER_INVOICE;
        }

        return max(1, min(50, $maxTries));
    }

    /**
     * Remove retry-specific metadata from sync context.
     *
     * @param array $context
     * @return array
     */
    private function clearRetryMetadata(array $context)
    {
        unset(
            $context['retry_attempts'],
            $context['max_retry_attempts'],
            $context['retry_status'],
            $context['retry_last_at'],
            $context['abandoned_after_retries'],
            $context['abandoned_at'],
            $context['abandoned_from_status']
        );

        return $context;
    }

    /**
     * Build a clear error message when retry cap is reached.
     *
     * @param string|null $errorMessage
     * @param int $attempts
     * @param int $maxTries
     * @return string
     */
    private function buildRetryCapMessage($errorMessage, $attempts, $maxTries)
    {
        $capMessage = "Retry limit reached ({$attempts}/{$maxTries}). Invoice marked as do not synchronize.";
        $baseMessage = trim((string) $errorMessage);

        if ($baseMessage === '') {
            return $capMessage;
        }
        if (strpos($baseMessage, 'Retry limit reached') !== false) {
            return $baseMessage;
        }

        return $baseMessage . ' | ' . $capMessage;
    }

    /**
     * Set synchronization status for a record
     *
     * @param string $identifier Record identifier (e.g., invoice_ref)
     * @param int $status New status
     * @param string|null $error_message Optional error message
     * @param array $context Additional context information
     * @return bool True on success
     * @throws Exception On database error
     */
    public function setSyncStatus($identifier, $status, $error_message = null, array $context = [])
    {
        $requestedStatus = (int) $status;
        $effectiveStatus = $requestedStatus;
        $existingStatus = $this->getSyncStatus($identifier);
        $storedContext = [];
        if (is_array($existingStatus) && isset($existingStatus['context']) && is_array($existingStatus['context'])) {
            $storedContext = $existingStatus['context'];
        }

        $contextData = $storedContext;
        $contextTouched = !empty($context);
        if (!empty($context)) {
            $contextData = array_merge($contextData, $context);
        }

        if (ZSSyncStatus::isRetryable($requestedStatus)) {
            $maxTries = $this->getMaxSyncTriesPerInvoice();
            $attempts = isset($contextData['retry_attempts']) ? (int) $contextData['retry_attempts'] : 0;
            $attempts++;

            $contextData['retry_attempts'] = $attempts;
            $contextData['max_retry_attempts'] = $maxTries;
            $contextData['retry_status'] = $requestedStatus;
            $contextData['retry_last_at'] = dol_print_date(dol_now(), 'dayhourlog');
            $contextTouched = true;

            if ($attempts >= $maxTries) {
                $effectiveStatus = ZSSyncStatus::DO_NOT_SYNCHRONIZE;
                $contextData['abandoned_after_retries'] = 1;
                $contextData['abandoned_at'] = dol_print_date(dol_now(), 'dayhourlog');
                $contextData['abandoned_from_status'] = $requestedStatus;
                $error_message = $this->buildRetryCapMessage($error_message, $attempts, $maxTries);
            }
        } elseif (in_array($requestedStatus, [ZSSyncStatus::OK, ZSSyncStatus::CREDIT_PROCESSED, ZSSyncStatus::NOT_SYNCHRONIZED], true)) {
            $cleanedContext = $this->clearRetryMetadata($contextData);
            if ($cleanedContext !== $contextData) {
                $contextData = $cleanedContext;
                $contextTouched = true;
            }
        }

        $entity = $this->getCurrentEntity();
        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table . " SET ";
        $sql .= "synch = " . $effectiveStatus . ", ";
        $sql .= "date_modification = NOW()";

        if ($error_message !== null) {
            $sql .= ", error_message = '" . $this->db->escape($error_message) . "'";
        } elseif (in_array($effectiveStatus, [ZSSyncStatus::OK, ZSSyncStatus::CREDIT_PROCESSED, ZSSyncStatus::NOT_SYNCHRONIZED], true)) {
            $sql .= ", error_message = NULL";
        }

        // Change the live link only when this operation explicitly supplies it.
        // Historical context may refer to a deleted partial invoice.
        if (array_key_exists('facture_id', $context)) {
            $sql .= ", fk_facture = " . max(0, (int) $context['facture_id']);
        }

        if ($contextTouched) {
            if (!empty($contextData)) {
                $encodedContext = json_encode($contextData);
                if ($encodedContext === false) {
                    $encodedContext = '{}';
                }
                $sql .= ", sync_context = '" . $this->db->escape($encodedContext) . "'";
            } else {
                $sql .= ", sync_context = NULL";
            }
        }

        $sql .= " WHERE " . $this->keyField . " = '" . $this->db->escape($identifier) . "'";
        $sql .= " AND entity = " . $entity;


        $result = $this->db->query($sql);
        if (!$result) {
            throw new Exception("Failed to update sync status: " . $this->db->lasterror());
        }

        return true;
    }

    /**
     * Get synchronization status for a record
     *
     * @param string $identifier Record identifier
     * @return array|null Array with status info or null if not found
     * @throws Exception On database error
     */
    public function getSyncStatus($identifier)
    {
        $entity = $this->getCurrentEntity();
        $sql = "SELECT synch, error_message, sync_context, date_modification ";
        $sql .= "FROM " . MAIN_DB_PREFIX . $this->table . " ";
        $sql .= "WHERE " . $this->keyField . " = '" . $this->db->escape($identifier) . "' ";
        $sql .= "AND entity = " . $entity;

        $result = $this->db->query($sql);
        if (!$result) {
            throw new Exception("Failed to get sync status: " . $this->db->lasterror());
        }

        if ($this->db->num_rows($result) === 0) {
            return null;
        }

        $obj = $this->db->fetch_object($result);
        return [
            'status' => (int)$obj->synch,
            'status_description' => ZSSyncStatus::getStatusDescription($obj->synch),
            'status_category' => ZSSyncStatus::getStatusCategory($obj->synch),
            'error_message' => $obj->error_message,
            'context' => $obj->sync_context ? json_decode($obj->sync_context, true) : [],
            'last_modified' => $obj->date_modification
        ];
    }

    /**
     * Get records by synchronization status
     *
     * @param array $statuses Array of status codes to filter by
     * @param int $limit Maximum number of records to return
     * @param int $offset Offset for pagination
     * @return array Array of records
     * @throws Exception On database error
     */
    public function getRecordsByStatus(array $statuses, $limit = 100, $offset = 0)
    {
        $entity = $this->getCurrentEntity();
        $statusList = implode(',', array_map('intval', $statuses));

        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . $this->table . " ";
        $sql .= "WHERE synch IN (" . $statusList . ") ";
        $sql .= "AND entity = " . $entity . " ";
        $sql .= "ORDER BY date_creation ASC ";
        $sql .= $this->db->plimit($limit, $offset);

        $result = $this->db->query($sql);
        if (!$result) {
            throw new Exception("Failed to get records by status: " . $this->db->lasterror());
        }

        $records = [];
        while ($obj = $this->db->fetch_object($result)) {
            $records[] = $obj;
        }

        return $records;
    }

    /**
     * Mark record as processing to prevent concurrent processing
     *
     * @param string $identifier Record identifier
     * @return bool True if successfully marked as processing
     * @throws Exception On database error
     */
    public function markAsProcessing($identifier)
    {
        $entity = $this->getCurrentEntity();
        // Use atomic update with WHERE condition to prevent race conditions
        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table . " SET ";
        $sql .= "synch = " . ZSSyncStatus::PROCESSING . ", ";
        $sql .= "date_modification = NOW() ";
        $sql .= "WHERE " . $this->keyField . " = '" . $this->db->escape($identifier) . "' ";
        $sql .= "AND entity = " . $entity . " ";
        $sql .= "AND synch IN (" . implode(',', ZSSyncStatus::getSynchronizableStatuses()) . ")";

        $result = $this->db->query($sql);
        if (!$result) {
            throw new Exception("Failed to mark as processing: " . $this->db->lasterror());
        }

        return $this->db->affected_rows($result) > 0;
    }

    /**
     * Reset failed statuses back to pending for retry
     *
     * @param array|null $specificStatuses Specific statuses to reset, or null for all retryable
     * @return int Number of records reset
     * @throws Exception On database error
     */
    public function resetFailedStatuses($specificStatuses = null)
    {
        $entity = $this->getCurrentEntity();
        $statusesToReset = $specificStatuses ?? ZSSyncStatus::getRetryableStatuses();
        $statusList = implode(',', array_map('intval', $statusesToReset));

        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table . " SET ";
        $sql .= "synch = " . ZSSyncStatus::NOT_SYNCHRONIZED . ", ";
        $sql .= "date_modification = NOW() ";
        $sql .= "WHERE synch IN (" . $statusList . ") ";
        $sql .= "AND entity = " . $entity;

        $result = $this->db->query($sql);
        if (!$result) {
            throw new Exception("Failed to reset failed statuses: " . $this->db->lasterror());
        }

        return $this->db->affected_rows($result);
    }

    /**
     * Get synchronization statistics
     *
     * @return array Statistics array
     * @throws Exception On database error
     */
    public function getSyncStatistics()
    {
        $entity = $this->getCurrentEntity();
        $sql = "SELECT synch, COUNT(*) as count FROM " . MAIN_DB_PREFIX . $this->table . " ";
        $sql .= "WHERE entity = " . $entity . " ";
        $sql .= "GROUP BY synch ORDER BY synch";

        $result = $this->db->query($sql);
        if (!$result) {
            throw new Exception("Failed to get sync statistics: " . $this->db->lasterror());
        }

        $stats = [];
        $total = 0;

        while ($obj = $this->db->fetch_object($result)) {
            $status = (int)$obj->synch;
            $count = (int)$obj->count;
            $total += $count;

            $stats['by_status'][$status] = [
                'count' => $count,
                'description' => ZSSyncStatus::getStatusDescription($status),
                'category' => ZSSyncStatus::getStatusCategory($status)
            ];
        }

        // Group by categories
        $stats['by_category'] = [];
        foreach ($stats['by_status'] as $status => $data) {
            $category = $data['category'];
            if (!isset($stats['by_category'][$category])) {
                $stats['by_category'][$category] = 0;
            }
            $stats['by_category'][$category] += $data['count'];
        }

        $stats['total'] = $total;
        $stats['success_rate'] = $total > 0 ? round(($stats['by_category']['success'] ?? 0) / $total * 100, 2) : 0;

        return $stats;
    }

    /**
     * Clean up old processing records that may have been stuck
     *
     * @param int $timeoutMinutes Records marked as processing for longer than this will be reset
     * @return int Number of records cleaned up
     * @throws Exception On database error
     */
    public function cleanupStuckProcessing($timeoutMinutes = 30)
    {
        $entity = $this->getCurrentEntity();
        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table . " SET ";
        $sql .= "synch = " . ZSSyncStatus::FAILED . ", ";
        $sql .= "error_message = 'Processing timeout - reset to failed status', ";
        $sql .= "date_modification = NOW() ";
        $sql .= "WHERE synch = " . ZSSyncStatus::PROCESSING . " ";
        $sql .= "AND entity = " . $entity . " ";
        $sql .= "AND date_modification < DATE_SUB(NOW(), INTERVAL " . (int)$timeoutMinutes . " MINUTE)";

        $result = $this->db->query($sql);
        if (!$result) {
            throw new Exception("Failed to cleanup stuck processing: " . $this->db->lasterror());
        }

        return $this->db->affected_rows($result);
    }
}
