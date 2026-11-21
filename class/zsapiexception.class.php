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
 * \file        class/zsapiexception.class.php
 * \ingroup     dolizsynch
 * \brief       Custom exception class for Zone Soft API operations
 */

/**
 * Exception class for Zone Soft API errors
 */
class ZSAPIException extends Exception
{
	/**
	 * @var array Additional context information about the exception
	 */
	private $context;

	/**
	 * Constructor
	 *
	 * @param string         $message  Exception message
	 * @param int            $code     Exception code (usually HTTP status code)
	 * @param Exception|null $previous Previous exception for chaining
	 * @param array          $context  Additional context information
	 */
	public function __construct($message = "", $code = 0, ?Exception $previous = null, array $context = array())
	{
		parent::__construct($message, $code, $previous);
		$this->context = $context;
	}

	/**
	 * Get context information
	 *
	 * @return array Context information
	 */
	public function getContext()
	{
		return $this->context;
	}

	/**
	 * Get formatted exception information including context
	 *
	 * @return string Formatted exception details
	 */
	public function getFullMessage()
	{
		$message = $this->getMessage();

		if (!empty($this->context)) {
			$message .= "\nContext: " . json_encode($this->context);
		}

		if ($this->getPrevious()) {
			$message .= "\nCaused by: " . $this->getPrevious()->getMessage();
		}

		return $message;
	}

	/**
	 * Convert exception to string representation
	 *
	 * @return string String representation of the exception
	 */
	public function __toString()
	{
		$output = __CLASS__ . ": [{$this->code}]: {$this->message}\n";

		if (!empty($this->context)) {
			$output .= "Context: " . json_encode($this->context) . "\n";
		}

		$output .= "Stack trace:\n" . $this->getTraceAsString();

		return $output;
	}
}

/**
 * Exception for configuration issues (missing/invalid settings).
 */
class ZSConfigException extends ZSAPIException
{
}

/**
 * Exception for validation errors (missing parameters, invalid payloads, etc).
 */
class ZSValidationException extends ZSAPIException
{
}
