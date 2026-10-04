<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getFileId()
 * @method void setFileId(int $id)
 * @method int getStorageId()
 * @method void setStorageId(int $id)
 * @method string getPath()
 * @method void setPath(string $path)
 * @method ?int getRuleId()
 * @method void setRuleId(?int $id)
 * @method ?int getRuleFolderId()
 * @method void setRuleFolderId(?int $id)
 * @method ?string getRuleLabel()
 * @method void setRuleLabel(?string $label)
 * @method int getReferenceDate()
 * @method void setReferenceDate(int $ts)
 * @method string getReferenceSource()
 * @method void setReferenceSource(string $source)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $ts)
 * @method string getMode()
 * @method void setMode(string $mode)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getMessage()
 * @method void setMessage(?string $message)
 */
class LogEntry extends Entity implements JsonSerializable {
	public const MODE_REAL = 'real';
	public const MODE_SIMULATION = 'simulation';

	public const STATUS_DELETED = 'deleted';
	public const STATUS_WOULD_DELETE = 'would_delete';
	public const STATUS_SKIPPED_LOCKED = 'skipped_locked';
	public const STATUS_SKIPPED_CHANGED = 'skipped_changed';
	public const STATUS_ERROR = 'error';
	/**
	 * File is gone but never arrived in the trash bin – Nextcloud deleted it bypassing the
	 * trash bin. Counts as an error; the area is locked afterwards (Settings::blockRoot).
	 */
	public const STATUS_DELETED_FINAL = 'deleted_final';
	/** Feedback to occ only, not logged: area locked, nothing attempted */
	public const STATUS_SKIPPED_BLOCKED = 'skipped_blocked';

	protected $fileId;
	protected $storageId;
	protected $path;
	protected $ruleId;
	protected $ruleFolderId;
	protected $ruleLabel;
	protected $referenceDate;
	protected $referenceSource;
	protected $deletedAt;
	protected $mode;
	protected $status;
	protected $message;

	public function __construct() {
		$this->addType('fileId', Types::BIGINT);
		$this->addType('storageId', Types::BIGINT);
		$this->addType('path', Types::STRING);
		$this->addType('ruleId', Types::BIGINT);
		$this->addType('ruleFolderId', Types::BIGINT);
		$this->addType('ruleLabel', Types::STRING);
		$this->addType('referenceDate', Types::BIGINT);
		$this->addType('referenceSource', Types::STRING);
		$this->addType('deletedAt', Types::BIGINT);
		$this->addType('mode', Types::STRING);
		$this->addType('status', Types::STRING);
		$this->addType('message', Types::STRING);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'fileId' => (int)$this->fileId,
			'path' => $this->path,
			'ruleId' => $this->ruleId === null ? null : (int)$this->ruleId,
			'ruleFolderId' => $this->ruleFolderId === null ? null : (int)$this->ruleFolderId,
			'ruleLabel' => $this->ruleLabel,
			'referenceDate' => (int)$this->referenceDate,
			'referenceSource' => $this->referenceSource,
			'deletedAt' => (int)$this->deletedAt,
			'mode' => $this->mode,
			'status' => $this->status,
			'message' => $this->message,
		];
	}
}
