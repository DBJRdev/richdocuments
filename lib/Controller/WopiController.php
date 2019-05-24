<?php
/**
 * @copyright Copyright (c) 2016-2017 Lukas Reschke <lukas@statuscode.ch>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Richdocuments\Controller;

use OC\Files\View;
use OCA\Richdocuments\Db\WopiMapper;
use OCA\Richdocuments\TemplateManager;
use OCA\Richdocuments\TokenManager;
use OCA\Richdocuments\Helper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IUserManager;
use OCP\IUserSession;

class WopiController extends Controller {
	/** @var IRootFolder */
	private $rootFolder;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var IConfig */
	private $config;
	/** @var TokenManager */
	private $tokenManager;
	/** @var IUserManager */
	private $userManager;
	/** @var WopiMapper */
	private $wopiMapper;
	/** @var ILogger */
	private $logger;
	/** @var IUserSession */
	private $userSession;
	/** @var TemplateManager */
	private $templateManager;

	// Signifies LOOL that document has been changed externally in this storage
	const LOOL_STATUS_DOC_CHANGED = 1010;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IRootFolder $rootFolder
	 * @param IURLGenerator $urlGenerator
	 * @param IConfig $config
	 * @param TokenManager $tokenManager
	 * @param IUserManager $userManager
	 * @param WopiMapper $wopiMapper
	 * @param ILogger $logger
	 * @param IUserSession $userSession
	 * @param TemplateManager $templateManager
	 */
	public function __construct($appName,
								IRequest $request,
								IRootFolder $rootFolder,
								IURLGenerator $urlGenerator,
								IConfig $config,
								TokenManager $tokenManager,
								IUserManager $userManager,
								WopiMapper $wopiMapper,
								ILogger $logger,
								IUserSession $userSession,
								TemplateManager $templateManager) {
		parent::__construct($appName, $request);
		$this->rootFolder = $rootFolder;
		$this->urlGenerator = $urlGenerator;
		$this->config = $config;
		$this->tokenManager = $tokenManager;
		$this->userManager = $userManager;
		$this->wopiMapper = $wopiMapper;
		$this->logger = $logger;
		$this->userSession = $userSession;
		$this->templateManager = $templateManager;
	}

	/**
	 * Returns general info about a file.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $fileId
	 * @param string $access_token
	 * @return JSONResponse
	 * @throws \OCP\Files\InvalidPathException
	 * @throws \OCP\Files\NotFoundException
	 */
	public function checkFileInfo($fileId, $access_token) {
		list($fileId, , $version) = Helper::parseFileId($fileId);

		try {
			$wopi = $this->wopiMapper->getPathForToken($access_token);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		if ($wopi->isTemplateToken()) {
			$this->templateManager->setUserId($wopi->getOwnerUid());
			$file = $this->templateManager->get($wopi->getFileid());
		} else {
			// Login the user to see his mount locations
			try {
				/** @var File $file */
				$userFolder = $this->rootFolder->getUserFolder($wopi->getOwnerUid());
				$file = $userFolder->getById($fileId)[0];
			} catch (\Exception $e) {
				$this->logger->logException($e, ['app' => 'richdocuments']);
				return new JSONResponse([], Http::STATUS_FORBIDDEN);
			}
		}

		if(!($file instanceof File)) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		$guestUserId = 'Guest-' . \OC::$server->getSecureRandom()->generate(8);
		$response = [
			'BaseFileName' => $file->getName(),
			'Size' => $file->getSize(),
			'Version' => $version,
			'UserId' => !is_null($wopi->getEditorUid()) ? $wopi->getEditorUid() : $guestUserId,
			'OwnerId' => $wopi->getOwnerUid(),
			'UserFriendlyName' => !is_null($wopi->getEditorUid()) ? \OC_User::getDisplayName($wopi->getEditorUid()) : $wopi->getGuestDisplayname(),
			'UserExtraInfo' => [
			],
			'UserCanWrite' => $wopi->getCanwrite(),
			'UserCanNotWriteRelative' => \OC::$server->getEncryptionManager()->isEnabled() ? true : is_null($wopi->getEditorUid()),
			'PostMessageOrigin' => $wopi->getServerHost(),
			'LastModifiedTime' => Helper::toISO8601($file->getMTime()),
			'EnableInsertRemoteImage' => true,
			'EnableShare' => true,
			'HideUserList' => 'desktop',
			'DisablePrint' => $wopi->getHideDownload(),
			'DisableExport' => $wopi->getHideDownload(),
			'DisableCopy' => $wopi->getHideDownload(),
			'HideExportOption' => $wopi->getHideDownload(),
			'HidePrintOption' => $wopi->getHideDownload()
		];

		if ($wopi->isTemplateToken()) {
			$userFolder = $this->rootFolder->getUserFolder($wopi->getOwnerUid());
			$file = $userFolder->getById($wopi->getTemplateDestination())[0];
			$response['TemplateSaveAs'] = $file->getName();
		}

		/**
		 * New approach for generating files from templates by creating an empty file
		 * and providing an URL which returns the actual templyte
		 */
		if ($wopi->hasTemplateId()) {
			$templateUrl = 'index.php/apps/richdocuments/wopi/template/' . $wopi->getTemplateId() . '?access_token=' . $wopi->getToken();
			$templateUrl = $this->urlGenerator->getAbsoluteURL($templateUrl);
			$response['TemplateSource'] = $templateUrl;

		}

		$user = $this->userManager->get($wopi->getEditorUid());
		if($user !== null && $user->getAvatarImage(32) !== null) {
			$response['UserExtraInfo']['avatar'] = $this->urlGenerator->linkToRouteAbsolute('core.avatar.getAvatar', ['userId' => $wopi->getEditorUid(), 'size' => 32]);
		}

		return new JSONResponse($response);
	}

	/**
	 * Given an access token and a fileId, returns the contents of the file.
	 * Expects a valid token in access_token parameter.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $fileId
	 * @param string $access_token
	 * @return Http\Response
	 */
	public function getFile($fileId,
							$access_token) {
		list($fileId, , $version) = Helper::parseFileId($fileId);

		$wopi = $this->wopiMapper->getPathForToken($access_token);

		if ((int)$fileId !== $wopi->getFileid()) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		// Template is just returned as there is no version logic
		if ($wopi->isTemplateToken()) {
			$this->templateManager->setUserId($wopi->getOwnerUid());
			$file = $this->templateManager->get($wopi->getFileid());
			$response = new StreamResponse($file->fopen('rb'));
			$response->addHeader('Content-Disposition', 'attachment');
			$response->addHeader('Content-Type', 'application/octet-stream');
			return $response;
		}

		try {
			/** @var File $file */
			$userFolder = $this->rootFolder->getUserFolder($wopi->getOwnerUid());
			$file = $userFolder->getById($fileId)[0];
			\OC_User::setIncognitoMode(true);
			if ($version !== '0') {
				$view = new View('/' . $wopi->getOwnerUid() . '/files');
				$relPath = $view->getRelativePath($file->getPath());
				$versionPath = '/files_versions/' . $relPath . '.v' . $version;
				$view = new View('/' . $wopi->getOwnerUid());
				if ($view->file_exists($versionPath)){
					$response = new StreamResponse($view->fopen($versionPath, 'rb'));
				}
				else {
					return new JSONResponse([], Http::STATUS_NOT_FOUND);
				}
			}
			else
			{
				$response = new StreamResponse($file->fopen('rb'));
			}
			$response->addHeader('Content-Disposition', 'attachment');
			$response->addHeader('Content-Type', 'application/octet-stream');
			return $response;
		} catch (\Exception $e) {
			$this->logger->logException($e, ['level' => ILogger::ERROR,	'app' => 'richdocuments', 'message' => 'getFile failed']);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * Given an access token and a fileId, replaces the files with the request body.
	 * Expects a valid token in access_token parameter.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $fileId
	 * @param string $access_token
	 * @return JSONResponse
	 */
	public function putFile($fileId,
							$access_token) {
		list($fileId, ,) = Helper::parseFileId($fileId);
		$isPutRelative = ($this->request->getHeader('X-WOPI-Override') === 'PUT_RELATIVE');

		$wopi = $this->wopiMapper->getPathForToken($access_token);
		if (!$wopi->getCanwrite()) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		// Unless the editor is empty (public link) we modify the files as the current editor
		$editor = $wopi->getEditorUid();
		if ($editor === null) {
			$editor = $wopi->getOwnerUid();
		}

		try {
			/** @var File $file */
			$userFolder = $this->rootFolder->getUserFolder($editor);
			$file = $userFolder->getById($fileId);
			if (count($file) === 0) {
				return new JSONResponse([], Http::STATUS_NOT_FOUND);
			}
			$file = $file[0];

			if ($isPutRelative) {
				// the new file needs to be installed in the current user dir
				$userFolder = $this->rootFolder->getUserFolder($wopi->getEditorUid());
				$file = $userFolder->getById($fileId);
				if (count($file) === 0) {
					return new JSONResponse([], Http::STATUS_NOT_FOUND);
				}
				$file = $file[0];
				$suggested = $this->request->getHeader('X-WOPI-SuggestedTarget');
				$suggested = iconv('utf-7', 'utf-8', $suggested);

				if ($suggested[0] === '.') {
					$path = dirname($file->getPath()) . '/New File' . $suggested;
				}
				else if ($suggested[0] !== '/') {
					$path = dirname($file->getPath()) . '/' . $suggested;
				}
				else {
					$path = $userFolder->getPath() . $suggested;
				}

				if ($path === '') {
					return new JSONResponse([
						'status' => 'error',
						'message' => 'Cannot create the file'
					]);
				}

				$root = \OC::$server->getRootFolder();

				// create the folder first
				if (!$root->nodeExists(dirname($path))) {
					$root->newFolder(dirname($path));
				}

				// create a unique new file
				$path = $root->getNonExistingName($path);
				$root->newFile($path);
				$file = $root->get($path);
			}
			else {
				$wopiHeaderTime = $this->request->getHeader('X-LOOL-WOPI-Timestamp');
				if ($wopiHeaderTime !== null && $wopiHeaderTime != Helper::toISO8601($file->getMTime() ?? 0)) {
					$this->logger->debug('Document timestamp mismatch ! WOPI client says mtime {headerTime} but storage says {storageTime}', [
						'headerTime' => $wopiHeaderTime,
						'storageTime' => Helper::toISO8601($file->getMTime() ?? 0)
					]);
					// Tell WOPI client about this conflict.
					return new JSONResponse(['LOOLStatusCode' => self::LOOL_STATUS_DOC_CHANGED], Http::STATUS_CONFLICT);
				}
			}

			$content = fopen('php://input', 'rb');

			// Set the user to register the change under his name
			$editor = $this->userManager->get($wopi->getEditorUid());
			if (!is_null($editor)) {
				$this->userSession->setUser($editor);
			}

			$file->putContent($content);

			if ($isPutRelative) {
				// generate a token for the new file (the user still has to be
				// logged in)
				list(, $wopiToken) = $this->tokenManager->getToken($file->getId(), null, $wopi->getEditorUid());

				$wopi = 'index.php/apps/richdocuments/wopi/files/' . $file->getId() . '_' . $this->config->getSystemValue('instanceid') . '?access_token=' . $wopiToken;
				$url = $this->urlGenerator->getAbsoluteURL($wopi);

				return new JSONResponse([ 'Name' => $file->getName(), 'Url' => $url ], Http::STATUS_OK);
			}

			return new JSONResponse(['LastModifiedTime' => Helper::toISO8601($file->getMTime())]);
		} catch (\Exception $e) {
			$this->logger->logException($e, ['level' => ILogger::ERROR,	'app' => 'richdocuments', 'message' => 'getFile failed']);
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Given an access token and a fileId, replaces the files with the request body.
	 * Expects a valid token in access_token parameter.
	 * Just actually routes to the PutFile, the implementation of PutFile
	 * handles both saving and saving as.* Given an access token and a fileId, replaces the files with the request body.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $fileId
	 * @param string $access_token
	 * @return JSONResponse
	 * @throws DoesNotExistException
	 */
	public function putRelativeFile($fileId,
					$access_token) {
		list($fileId, ,) = Helper::parseFileId($fileId);
		$wopi = $this->wopiMapper->getPathForToken($access_token);

		if (!$wopi->getCanwrite()) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		// Unless the editor is empty (public link) we modify the files as the current editor
		$editor = $wopi->getEditorUid();
		if ($editor === null) {
			$editor = $wopi->getOwnerUid();
		}

		try {
			// the new file needs to be installed in the current user dir
			$userFolder = $this->rootFolder->getUserFolder($editor);

			if ($wopi->isTemplateToken()) {
				$this->templateManager->setUserId($wopi->getOwnerUid());
				$file = $userFolder->getById($wopi->getTemplateDestination())[0];
			} else {
				$file = $userFolder->getById($fileId);
				if (count($file) === 0) {
					return new JSONResponse([], Http::STATUS_NOT_FOUND);
				}
				$file = $file[0];

				$suggested = $this->request->getHeader('X-WOPI-SuggestedTarget');
				$suggested = iconv('utf-7', 'utf-8', $suggested);

				if ($suggested[0] === '.') {
					$path = dirname($file->getPath()) . '/New File' . $suggested;
				} else if ($suggested[0] !== '/') {
					$path = dirname($file->getPath()) . '/' . $suggested;
				} else {
					$path = $userFolder->getPath() . $suggested;
				}

				if ($path === '') {
					return new JSONResponse([
						'status' => 'error',
						'message' => 'Cannot create the file'
					]);
				}

				// create the folder first
				if (!$this->rootFolder->nodeExists(dirname($path))) {
					$this->rootFolder->newFolder(dirname($path));
				}

				// create a unique new file
				$path = $this->rootFolder->getNonExistingName($path);
				$file = $this->rootFolder->newFile($path);
			}

			$content = fopen('php://input', 'rb');

			// Set the user to register the change under his name
			$editor = $this->userManager->get($wopi->getEditorUid());
			if (!is_null($editor)) {
				$this->userSession->setUser($editor);
			}

			$file->putContent($content);

			// generate a token for the new file (the user still has to be
			// logged in)
			list(, $wopiToken) = $this->tokenManager->getToken($file->getId(), null, $wopi->getEditorUid());

			$wopi = 'index.php/apps/richdocuments/wopi/files/' . $file->getId() . '_' . $this->config->getSystemValue('instanceid') . '?access_token=' . $wopiToken;
			$url = $this->urlGenerator->getAbsoluteURL($wopi);

			return new JSONResponse([ 'Name' => $file->getName(), 'Url' => $url ], Http::STATUS_OK);
		} catch (\Exception $e) {
			$this->logger->logException($e, ['level' => ILogger::ERROR,	'app' => 'richdocuments', 'message' => 'putRelativeFile failed']);
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Endpoint to return the template file that is requested by collabora to create a new document
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param $fileId
	 * @param $access_token
	 * @return JSONResponse|StreamResponse
	 */
	public function getTemplate($fileId, $access_token) {
		try {
			$wopi = $this->wopiMapper->getPathForToken($access_token);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		if ((int)$fileId !== $wopi->getTemplateId()) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->templateManager->setUserId($wopi->getOwnerUid());
			$file = $this->templateManager->get($wopi->getTemplateId());
			$response = new StreamResponse($file->fopen('rb'));
			$response->addHeader('Content-Disposition', 'attachment');
			$response->addHeader('Content-Type', 'application/octet-stream');
			return $response;
		} catch (\Exception $e) {
			$this->logger->logException($e, ['level' => ILogger::ERROR,	'app' => 'richdocuments', 'message' => 'getTemplate failed']);
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

}
