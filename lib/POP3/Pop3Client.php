<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\POP3;

use Exception;

/**
 * Simple POP3 client implementation
 * Supports basic POP3 commands: USER, PASS, STAT, LIST, UIDL, RETR, NOOP, QUIT
 */
class Pop3Client {
	private const TIMEOUT = 30;
	private const BUFFER_SIZE = 8192;

	/** @var resource|null */
	private $socket = null;
	
	private string $host;
	private int $port;
	private string $sslMode;
	private bool $connected = false;

	public function __construct(string $host, int $port, string $sslMode = 'ssl') {
		$this->host = $host;
		$this->port = $port;
		$this->sslMode = $sslMode;
	}

	/**
	 * Connect to POP3 server
	 */
	public function connect(): void {
		if ($this->connected) {
			return;
		}

		$remoteSocket = $this->buildRemoteSocket();
		$context = stream_context_create([
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
				'allow_self_signed' => false,
			],
		]);

		$this->socket = @stream_socket_client(
			$remoteSocket,
			$errno,
			$errstr,
			self::TIMEOUT,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if (!$this->socket) {
			throw new Pop3Exception("Failed to connect to POP3 server: $errstr ($errno)");
		}

		stream_set_timeout($this->socket, self::TIMEOUT);

		// Read server greeting
		$response = $this->readResponse();
		if (!str_starts_with($response, '+OK')) {
			$this->disconnect();
			throw new Pop3Exception("POP3 server returned error: $response");
		}

		// If using STARTTLS, initiate it
		if ($this->sslMode === 'tls') {
			$this->sendCommand('STLS');
			$response = $this->readResponse();
			if (!str_starts_with($response, '+OK')) {
				throw new Pop3Exception("STARTTLS failed: $response");
			}

			if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
				throw new Pop3Exception("Failed to enable TLS encryption");
			}
		}

		$this->connected = true;
	}

	/**
	 * Login to POP3 server
	 */
	public function login(string $username, string $password): void {
		$this->connect();

		$this->sendCommand("USER $username");
		$response = $this->readResponse();
		if (!str_starts_with($response, '+OK')) {
			throw new Pop3Exception("USER command failed: $response");
		}

		$this->sendCommand("PASS $password");
		$response = $this->readResponse();
		if (!str_starts_with($response, '+OK')) {
			throw new Pop3Exception("Authentication failed: $response");
		}
	}

	/**
	 * Get mailbox statistics (message count and total size)
	 * @return array{count: int, size: int}
	 */
	public function stat(): array {
		$this->sendCommand('STAT');
		$response = $this->readResponse();
		
		if (!str_starts_with($response, '+OK')) {
			throw new Pop3Exception("STAT command failed: $response");
		}

		// Response format: +OK count size
		if (preg_match('/\+OK\s+(\d+)\s+(\d+)/', $response, $matches)) {
			return [
				'count' => (int)$matches[1],
				'size' => (int)$matches[2],
			];
		}

		throw new Pop3Exception("Invalid STAT response: $response");
	}

	/**
	 * Get list of messages with their sizes
	 * @return array<int, int> Message number => size in bytes
	 */
	public function listMessages(): array {
		$this->sendCommand('LIST');
		$response = $this->readResponse();
		
		if (!str_starts_with($response, '+OK')) {
			throw new Pop3Exception("LIST command failed: $response");
		}

		$messages = [];
		while (($line = $this->readLine()) !== '.') {
			if (preg_match('/^(\d+)\s+(\d+)$/', $line, $matches)) {
				$messages[(int)$matches[1]] = (int)$matches[2];
			}
		}

		return $messages;
	}

	/**
	 * Get unique IDs for all messages (UIDL)
	 * @return array<int, string> Message number => unique ID
	 */
	public function getUniqueIds(): array {
		$this->sendCommand('UIDL');
		$response = $this->readResponse();
		
		if (!str_starts_with($response, '+OK')) {
			throw new Pop3Exception("UIDL command failed: $response");
		}

		$uidList = [];
		while (($line = $this->readLine()) !== '.') {
			if (preg_match('/^(\d+)\s+(.+)$/', $line, $matches)) {
				$uidList[(int)$matches[1]] = trim($matches[2]);
			}
		}

		return $uidList;
	}

	/**
	 * Retrieve a message by number
	 * @return string Raw message content (headers + body)
	 */
	public function retrieveMessage(int $messageNumber): string {
		$this->sendCommand("RETR $messageNumber");
		$response = $this->readResponse();
		
		if (!str_starts_with($response, '+OK')) {
			throw new Pop3Exception("RETR command failed: $response");
		}

		$message = '';
		while (($line = $this->readLine()) !== '.') {
			// Handle byte-stuffing: lines starting with '.' are escaped as '..'
			if (str_starts_with($line, '.')) {
				$line = substr($line, 1);
			}
			$message .= $line . "\r\n";
		}

		return $message;
	}

	/**
	 * Keep connection alive
	 */
	public function noop(): void {
		$this->sendCommand('NOOP');
		$response = $this->readResponse();
		
		if (!str_starts_with($response, '+OK')) {
			throw new Pop3Exception("NOOP command failed: $response");
		}
	}

	/**
	 * Disconnect from server
	 */
	public function disconnect(): void {
		if ($this->socket && $this->connected) {
			try {
				$this->sendCommand('QUIT');
				$this->readResponse();
			} catch (Exception $e) {
				// Ignore errors during disconnect
			}
			
			fclose($this->socket);
			$this->socket = null;
			$this->connected = false;
		}
	}

	public function __destruct() {
		$this->disconnect();
	}

	/**
	 * Build remote socket string based on SSL mode
	 */
	private function buildRemoteSocket(): string {
		$prefix = match ($this->sslMode) {
			'ssl' => 'ssl://',
			'tls' => 'tcp://', // STARTTLS starts as plaintext
			default => 'tcp://',
		};

		return $prefix . $this->host . ':' . $this->port;
	}

	/**
	 * Send a command to the server
	 */
	private function sendCommand(string $command): void {
		if (!$this->socket) {
			throw new Pop3Exception("Not connected to POP3 server");
		}

		$written = fwrite($this->socket, $command . "\r\n");
		if ($written === false) {
			throw new Pop3Exception("Failed to send command to server");
		}
	}

	/**
	 * Read a single line from the server
	 */
	private function readLine(): string {
		if (!$this->socket) {
			throw new Pop3Exception("Not connected to POP3 server");
		}

		$line = fgets($this->socket, self::BUFFER_SIZE);
		if ($line === false) {
			throw new Pop3Exception("Failed to read from server");
		}

		return rtrim($line, "\r\n");
	}

	/**
	 * Read a response line from the server
	 */
	private function readResponse(): string {
		return $this->readLine();
	}
}
