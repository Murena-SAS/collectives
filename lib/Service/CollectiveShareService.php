<?php

declare(strict_types=1);

namespace OCA\Collectives\Service;

use OCA\Collectives\Db\Collective;
use OCA\Collectives\Db\CollectiveShareMapper;
use OCA\Collectives\Fs\UserFolderHelper;
use OCA\Collectives\Model\CollectiveInfo;
use OCA\Collectives\Model\CollectiveShareInfo;
use OCA\Collectives\Model\PageInfo;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\Constants;
use OCP\DB\Exception;
use OCP\Files\Folder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Share\Exceptions\GenericShareException;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

class CollectiveShareService {
	public function __construct(private IShareManager $shareManager,
		private UserFolderHelper $userFolderHelper,
		private CollectiveShareMapper $collectiveShareMapper,
		private PageService $pageService,
		private IL10N $l10n) {
	}

	/**
	 * Use a share link to grant access to collectives folder
	 *
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function createFolderShare(string $userId, string $collectiveName, int $nodeId): IShare {
		$share = $this->shareManager->newShare();

		$permissions = Constants::PERMISSION_READ;
		$share->setPermissions($permissions);

		// Can we even share link?
		if (!$this->shareManager->shareApiAllowLinks()) {
			throw new NotFoundException('Public link sharing is disabled by the administrator');
		}

		$userFolder = $this->userFolderHelper->get($userId);
		try {
			$path = $userFolder->get($collectiveName);
			if (!($path instanceof Folder)) {
				throw new FilesNotFoundException();
			}
			if ($nodeId !== 0) {
				$nodes = $path->getById($nodeId);
				if (count($nodes) <= 0) {
					throw new FilesNotFoundException();
				}
				$path = $nodes[0];
			}
		} catch (FilesNotFoundException $e) {
			throw new NotFoundException('Wrong path, collective folder doesn\'t exist', 0, $e);
		}
		$share->setNode($path);

		try {
			$share->getNode()->lock(ILockingProvider::LOCK_SHARED);
		} catch (FilesNotFoundException | LockedException $e) {
			throw new NotFoundException('Could not create share', 0, $e);
		}

		$share->setShareType(IShare::TYPE_LINK);
		$share->setSharedBy($userId);
		$share->setLabel($this->l10n->t('Collective Share'));

		try {
			$share = $this->shareManager->createShare($share);
		} catch (GenericShareException $e) {
			throw new NotFoundException($e->getHint(), 0, $e);
		} catch (\Exception $e) {
			throw new NotPermittedException($e->getMessage(), 0, $e);
		} finally {
			try {
				$share->getNode()->unlock(ILockingProvider::LOCK_SHARED);
			} catch (FilesNotFoundException $e) {
				throw new NotFoundException('Could not get share', 0, $e);
			} catch (LockedException $e) {
				throw new NotPermittedException('Failed to unlock share', 0, $e);
			}
		}

		return $share;
	}

	public function findShare(string $userId, int $collectiveId, int $pageId): ?CollectiveShareInfo {
		try {
			$collectiveShare = $this->collectiveShareMapper->findOneByCollectiveIdAndUser($collectiveId, $pageId, $userId);
		} catch (DoesNotExistException | MultipleObjectsReturnedException | Exception) {
			return null;
		}

		try {
			$folderShare = $this->shareManager->getShareByToken($collectiveShare->getToken());
		} catch (ShareNotFound) {
			// Corresponding folder share not found, delete the collective share as well.
			$this->collectiveShareMapper->delete($collectiveShare);
			return null;
		}

		return new CollectiveShareInfo($collectiveShare, $this->isShareEditable($folderShare));
	}

	public function findShareByToken(string $token): ?CollectiveShareInfo {
		try {
			$collectiveShare = $this->collectiveShareMapper->findOneByToken($token);
		} catch (DoesNotExistException | MultipleObjectsReturnedException | Exception) {
			return null;
		}

		try {
			$folderShare = $this->shareManager->getShareByToken($collectiveShare->getToken());
		} catch (ShareNotFound) {
			// Corresponding folder share not found, delete the collective share as well.
			$this->collectiveShareMapper->delete($collectiveShare);
			return null;
		}

		return new CollectiveShareInfo($collectiveShare, $this->isShareEditable($folderShare));
	}

	public function getSharesByCollectiveAndUser(string $userId, int $collectiveId): array {
		$collectiveShares = $this->collectiveShareMapper->findByCollectiveIdAndUser($collectiveId, $userId);

		$shares = [];
		foreach ($collectiveShares as $share) {
			try {
				$folderShare = $this->shareManager->getShareByToken($share->getToken());
			} catch (ShareNotFound) {
				// Corresponding folder share not found, delete the collective share as well.
				$this->collectiveShareMapper->delete($share);
			}
			$shares[] = new CollectiveShareInfo($share, $this->isShareEditable($folderShare));
		}

		return $shares;
	}

	private function isShareEditable(IShare $folderShare): bool {
		$folderShare->getPermissions();
		return ($folderShare->getPermissions() & Collective::editPermissions) === Collective::editPermissions;
	}

	/**
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function createShare(string $userId, CollectiveInfo $collectiveInfo, ?PageInfo $pageInfo): CollectiveShareInfo {
		if (!$collectiveInfo->canShare()) {
			throw new NotPermittedException($this->l10n->t('You are not allowed to share %s', $collectiveInfo->getName()));
		}

		$pageId = 0;
		$nodeId = 0;
		if ($pageInfo) {
			$pageId = $pageInfo->getId();
			$file = $this->pageService->getPageFile($collectiveInfo->getId(), $pageId, $userId);
			$nodeId = $file->getParent()->getId();
		}

		$folderShare = $this->createFolderShare($userId, $collectiveInfo->getName(), $nodeId);

		try {
			return new CollectiveShareInfo($this->collectiveShareMapper->create($collectiveInfo->getId(), $pageId, $folderShare->getToken(), $userId));
		} catch (Exception $e) {
			throw new NotPermittedException('Failed to create collective/page share for ' . $collectiveInfo->getName(), 0, $e);
		}
	}

	/**
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function updateShare(string $userId, CollectiveInfo $collectiveInfo, ?PageInfo $pageInfo, string $token, bool $editable = false): CollectiveShareInfo {
		if (!$collectiveInfo->canShare()) {
			throw new NotPermittedException($this->l10n->t('You are not allowed to share %s', $collectiveInfo->getName()));
		}

		if (!$collectiveInfo->canEdit()) {
			throw new NotPermittedException($this->l10n->t('You are not allowed to edit %s', $collectiveInfo->getName()));
		}

		$pageId = 0;
		if ($pageInfo) {
			$pageId = $pageInfo->getId();
		}

		if (null === $share = $this->collectiveShareMapper->findOneByCollectiveIdAndTokenAndUser($collectiveInfo->getId(), $pageId, $token, $userId)) {
			throw new NotFoundException($this->l10n->t('Share not found for user'));
		}

		try {
			$folderShare = $this->shareManager->getShareByToken($token);
		} catch (ShareNotFound $e) {
			throw new NotFoundException($this->l10n->t('Share not found for user'), 0, $e);
		}

		$permissions = Constants::PERMISSION_READ;
		if ($editable) {
			$permissions |= Collective::editPermissions;
		}

		$folderShare->setPermissions($permissions);
		$this->shareManager->updateShare($folderShare);

		return new CollectiveShareInfo($share, $this->isShareEditable($folderShare));
	}

	private function deleteFileShare(string $token): void {
		try {
			$share = $this->shareManager->getShareByToken($token);
			$this->shareManager->deleteShare($share);
		} catch (ShareNotFound) {
			// Corresponding folder share is already gone.
		}
	}

	/**
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function deleteShare(string $userId, int $collectiveId, int $pageId, string $token): CollectiveShareInfo {
		try {
			$collectiveShare = $this->collectiveShareMapper->findOneByCollectiveIdAndTokenAndUser($collectiveId, $pageId, $token, $userId);
			$this->collectiveShareMapper->delete($collectiveShare);
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
			throw new NotFoundException('Failed to find collective share ' . $token, 0, $e);
		} catch (Exception $e) {
			throw new NotPermittedException('Failed to delete collective share ' . $token, 0, $e);
		}

		$this->deleteFileShare($collectiveShare->getToken());

		return new CollectiveShareInfo($collectiveShare);
	}

	/**
	 * @throws NotPermittedException
	 */
	public function deleteShareByCollectiveId(int $collectiveId): void {
		try {
			$collectiveShares = $this->collectiveShareMapper->findByCollectiveId($collectiveId);
			foreach ($collectiveShares as $collectiveShare) {
				$this->collectiveShareMapper->delete($collectiveShare);
				$this->deleteFileShare($collectiveShare->getToken());
			}
		} catch (Exception $e) {
			throw new NotPermittedException('Failed to delete collective share for ' . $collectiveId, 0, $e);
		}
	}
}
