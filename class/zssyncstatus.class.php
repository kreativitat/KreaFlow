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
 * \file        class/zssyncstatus.class.php
 * \ingroup     DoliZSynch
 * \brief       Synchronization status constants and management for ZoneSoft integration
 */

/**
 * ZoneSoft Synchronization Status Manager
 *
 * This class provides centralized management of synchronization statuses across the module
 */
class ZSSyncStatus
{
    // ===== PENDING STATES =====

    /** @var int Not synchronized yet - pending synchronization */
    const NOT_SYNCHRONIZED = 0;

    // ===== SUCCESS STATES =====

    /** @var int Successfully synchronized */
    const OK = 1;

    // ===== PROCESSING STATES =====

    /** @var int Currently being processed */
    const PROCESSING = 10;

    /** @var int Queued for retry after temporary failure */
    const RETRY_QUEUED = 11;

    // ===== ERROR STATES =====

    /** @var int Failed synchronization - retryable */
    const FAILED = 2;

    /** @var int Validation error during sync */
    const VALIDATION_ERROR = 5;

    /** @var int Mapping error - configuration issue */
    const MAPPING_ERROR = 7;

    /** @var int Save/update error in Dolibarr */
    const SAVE_UPDATE_ERROR = 8;

    // ===== BUSINESS LOGIC STATES =====

    /** @var int Canceled invoice - needs credit note */
    const CANCELED_NEEDS_CREDIT = 3;

    /** @var int Credit note has been processed */
    const CREDIT_PROCESSED = 4;

    /** @var int Credit note creation failed */
    const CREDIT_CREATION_FAILED = 9;

    /** @var int Marked to not synchronize */
    const DO_NOT_SYNCHRONIZE = 6;

    // ===== API ERROR STATES =====

    /** @var int API authentication failed */
    const AUTHENTICATION_FAILED = 21;

    /** @var int Date mismatch between systems */
    const DATE_MISMATCH = 22;

    /** @var int Invoice not found in ZoneSoft */
    const INVOICE_NOT_FOUND = 23;

    /** @var int Receipt (RC) synchronization error */
    const RECEIPT_ERROR_SYNCH = 24;

    // ===== DEVELOPMENT STATES =====

    /** @var int Feature not yet implemented */
    const FUTURE_DEVELOPMENT = 99;

    /**
     * Get human-readable status description
     *
     * @param int $status Status code
     * @return string Description
     */
    public static function getStatusDescription($status)
    {
        $descriptions = [
            self::NOT_SYNCHRONIZED => 'Not synchronized',
            self::OK => 'Successfully synchronized',
            self::PROCESSING => 'Currently processing',
            self::RETRY_QUEUED => 'Queued for retry',
            self::FAILED => 'Failed - retryable',
            self::VALIDATION_ERROR => 'Validation error',
            self::MAPPING_ERROR => 'Mapping configuration error',
            self::SAVE_UPDATE_ERROR => 'Save/update error',
            self::CANCELED_NEEDS_CREDIT => 'Canceled - needs credit note',
            self::CREDIT_PROCESSED => 'Credit note processed',
            self::CREDIT_CREATION_FAILED => 'Credit note creation failed',
            self::DO_NOT_SYNCHRONIZE => 'Marked to not synchronize',
            self::AUTHENTICATION_FAILED => 'API authentication failed',
            self::DATE_MISMATCH => 'Date mismatch',
            self::INVOICE_NOT_FOUND => 'Invoice not found in ZoneSoft',
            self::RECEIPT_ERROR_SYNCH => 'Receipt synchronization error',
            self::FUTURE_DEVELOPMENT => 'Feature not implemented'
        ];

        return $descriptions[$status] ?? "Unknown status ($status)";
    }

    /**
     * Get status category
     *
     * @param int $status Status code
     * @return string Category (pending|success|processing|error|business|api|development)
     */
    public static function getStatusCategory($status)
    {
        if ($status === self::NOT_SYNCHRONIZED) {
            return 'pending';
        }
        if ($status === self::OK) {
            return 'success';
        }
        if (in_array($status, [self::PROCESSING, self::RETRY_QUEUED])) {
            return 'processing';
        }
        if (in_array($status, [self::FAILED, self::VALIDATION_ERROR, self::MAPPING_ERROR, self::SAVE_UPDATE_ERROR, self::RECEIPT_ERROR_SYNCH])) {
            return 'error';
        }
        if (in_array($status, [self::CANCELED_NEEDS_CREDIT, self::CREDIT_PROCESSED, self::CREDIT_CREATION_FAILED, self::DO_NOT_SYNCHRONIZE])) {
            return 'business';
        }
        if (in_array($status, [self::AUTHENTICATION_FAILED, self::DATE_MISMATCH, self::INVOICE_NOT_FOUND])) {
            return 'api';
        }
        if ($status === self::FUTURE_DEVELOPMENT) {
            return 'development';
        }
        return 'unknown';
    }

    /**
     * Check if status is retryable
     *
     * @param int $status Status code
     * @return bool True if status can be retried
     */
    public static function isRetryable($status)
    {
        return in_array($status, [
            self::FAILED,
            self::VALIDATION_ERROR,
            self::SAVE_UPDATE_ERROR,
            self::AUTHENTICATION_FAILED,
            self::DATE_MISMATCH,
            self::INVOICE_NOT_FOUND
        ]);
    }

    /**
     * Check if status indicates success
     *
     * @param int $status Status code
     * @return bool True if status indicates successful synchronization
     */
    public static function isSuccess($status)
    {
        return in_array($status, [self::OK, self::CREDIT_PROCESSED]);
    }

    /**
     * Check if status indicates error
     *
     * @param int $status Status code
     * @return bool True if status indicates an error
     */
    public static function isError($status)
    {
        return self::getStatusCategory($status) === 'error';
    }

    /**
     * Check if status indicates processing
     *
     * @param int $status Status code
     * @return bool True if status indicates currently processing
     */
    public static function isProcessing($status)
    {
        return self::getStatusCategory($status) === 'processing';
    }

    /**
     * Get CSS class for status display
     *
     * @param int $status Status code
     * @return string CSS class
     */
    public static function getStatusCssClass($status)
    {
        $category = self::getStatusCategory($status);

        switch ($category) {
            case 'pending':
                return 'badge badge-secondary';
            case 'success':
                return ($status === self::OK) ? 'badge badge-success' : 'badge badge-secondary';
            case 'processing':
                return 'badge badge-info';
            case 'error':
                return 'badge badge-danger';
            case 'business':
                return 'badge badge-warning';
            case 'api':
                return 'badge badge-warning';
            case 'development':
                return 'badge badge-light';
            default:
                return 'badge badge-secondary';
        }
    }

    /**
     * Get all retryable statuses
     *
     * @return array Array of status codes that can be retried
     */
    public static function getRetryableStatuses()
    {
        return [
            self::FAILED,
            self::VALIDATION_ERROR,
            self::SAVE_UPDATE_ERROR,
            self::AUTHENTICATION_FAILED,
            self::DATE_MISMATCH,
            self::INVOICE_NOT_FOUND
        ];
    }

    /**
     * Get all synchronizable statuses (can be processed)
     *
     * @return array Array of status codes that can be synchronized
     */
    public static function getSynchronizableStatuses()
    {
        return [
            self::NOT_SYNCHRONIZED,
            self::FAILED,
            self::VALIDATION_ERROR,
            self::SAVE_UPDATE_ERROR,
            self::RETRY_QUEUED,
            self::CANCELED_NEEDS_CREDIT,
            self::AUTHENTICATION_FAILED,
            self::DATE_MISMATCH,
            self::INVOICE_NOT_FOUND
        ];
    }
}
