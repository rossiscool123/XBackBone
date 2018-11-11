<?php

namespace App\Controllers;


use App\Exceptions\UnauthorizedException;
use App\Web\Session;
use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use Slim\Exception\NotFoundException;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\Stream;

class UploadController extends Controller
{

	/**
	 * @param Request $request
	 * @param Response $response
	 * @return Response
	 * @throws FileExistsException
	 */
	public function upload(Request $request, Response $response): Response
	{

		$json = ['message' => null];

		if ($request->getParam('token') === null) {
			$json['message'] = 'Token not specified.';
			return $response->withJson($json, 400);
		}

		$user = $this->database->query('SELECT * FROM `users` WHERE `token` = ? LIMIT 1', $request->getParam('token'))->fetch();

		if (!$user) {
			$json['message'] = 'Token specified not found.';
			return $response->withJson($json, 404);
		}

		if (!$user->active) {
			$json['message'] = 'Account disabled.';
			return $response->withJson($json, 401);
		}

		do {
			$code = uniqid();
		} while ($this->database->query('SELECT COUNT(*) AS `count` FROM `uploads` WHERE `code` = ?', $code)->fetch()->count > 0);

		/** @var \Psr\Http\Message\UploadedFileInterface $file */
		$file = $request->getUploadedFiles()['upload'];

		$fileInfo = pathinfo($file->getClientFilename());
		$storagePath = "$user->user_code/$code.$fileInfo[extension]";

		$this->getStorage()->writeStream($storagePath, $file->getStream()->detach());

		$this->database->query('INSERT INTO `uploads`(`user_id`, `code`, `filename`, `storage_path`) VALUES (?, ?, ?, ?)', [
			$user->id,
			$code,
			$file->getClientFilename(),
			$storagePath
		]);

		$base_url = $this->settings['base_url'];

		$json['message'] = 'OK.';
		$json['url'] = "$base_url/$user->user_code/$code.$fileInfo[extension]";

		$this->logger->info("User $user->username uploaded new media.", [$this->database->raw()->lastInsertId()]);

		return $response->withJson($json, 201);
	}

	/**
	 * @param Request $request
	 * @param Response $response
	 * @param $args
	 * @return Response
	 * @throws FileNotFoundException
	 * @throws NotFoundException
	 */
	public function show(Request $request, Response $response, $args): Response
	{
		$media = $this->getMedia($args['userCode'], $args['mediaCode']);

		if (!$media || !$media->published && Session::get('user_id') !== $media->user_id && !Session::get('admin', false)) {
			throw new NotFoundException($request, $response);
		}

		$filesystem = $this->getStorage();

		if (stristr($request->getHeaderLine('User-Agent'), 'TelegramBot') ||
			stristr($request->getHeaderLine('User-Agent'), 'facebookexternalhit/') ||
			stristr($request->getHeaderLine('User-Agent'), 'Facebot')) {
			return $this->streamMedia($request, $response, $filesystem, $media);
		} else {

			try {
				$mime = $filesystem->getMimetype($media->storage_path);

				$type = explode('/', $mime)[0];
				if ($type === 'text') {
					$media->text = $filesystem->read($media->storage_path);
				} elseif (in_array($type, ['image', 'video']) && $request->getHeaderLine('Scheme') === 'HTTP/2.0') {
					$response = $response->withHeader('Link', "<{$this->settings['base_url']}/$args[userCode]/$args[mediaCode]/raw>; rel=preload; as={$type}");
				}

			} catch (FileNotFoundException $e) {
				throw $e;
			}

			return $this->view->render($response, 'upload/public.twig', [
				'media' => $media,
				'type' => $mime,
				'extension' => pathinfo($media->filename, PATHINFO_EXTENSION)
			]);
		}
	}

	/**
	 * @param Request $request
	 * @param Response $response
	 * @param $args
	 * @return Response
	 * @throws NotFoundException
	 * @throws FileNotFoundException
	 */
	public function getRawById(Request $request, Response $response, $args): Response
	{

		$media = $this->database->query('SELECT * FROM `uploads` WHERE `id` = ? LIMIT 1', $args['id'])->fetch();

		if (!$media) {
			throw new NotFoundException($request, $response);
		}

		return $this->streamMedia($request, $response, $this->getStorage(), $media);
	}

	/**
	 * @param Request $request
	 * @param Response $response
	 * @param $args
	 * @return Response
	 * @throws NotFoundException
	 * @throws FileNotFoundException
	 */
	public function showRaw(Request $request, Response $response, $args): Response
	{
		$media = $this->getMedia($args['userCode'], $args['mediaCode']);

		if (!$media || !$media->published && Session::get('user_id') !== $media->user_id && !Session::get('admin', false)) {
			throw new NotFoundException($request, $response);
		}
		return $this->streamMedia($request, $response, $this->getStorage(), $media);
	}


	/**
	 * @param Request $request
	 * @param Response $response
	 * @param $args
	 * @return Response
	 * @throws NotFoundException
	 * @throws FileNotFoundException
	 */
	public function download(Request $request, Response $response, $args): Response
	{
		$media = $this->getMedia($args['userCode'], $args['mediaCode']);

		if (!$media || !$media->published && Session::get('user_id') !== $media->user_id && !Session::get('admin', false)) {
			throw new NotFoundException($request, $response);
		}
		return $this->streamMedia($request, $response, $this->getStorage(), $media, 'attachment');
	}

	/**
	 * @param Request $request
	 * @param Response $response
	 * @param $args
	 * @return Response
	 * @throws NotFoundException
	 */
	public function togglePublish(Request $request, Response $response, $args): Response
	{
		if (Session::get('admin')) {
			$media = $this->database->query('SELECT * FROM `uploads` WHERE `id` = ? LIMIT 1', $args['id'])->fetch();
		} else {
			$media = $this->database->query('SELECT * FROM `uploads` WHERE `id` = ? AND `user_id` = ? LIMIT 1', [$args['id'], Session::get('user_id')])->fetch();
		}

		if (!$media) {
			throw new NotFoundException($request, $response);
		}

		$this->database->query('UPDATE `uploads` SET `published`=? WHERE `id`=?', [!$media->published, $media->id]);

		return $response->withStatus(200);
	}

	/**
	 * @param Request $request
	 * @param Response $response
	 * @param $args
	 * @return Response
	 * @throws NotFoundException
	 * @throws UnauthorizedException
	 */
	public function delete(Request $request, Response $response, $args): Response
	{
		$media = $this->database->query('SELECT * FROM `uploads` WHERE `id` = ? LIMIT 1', $args['id'])->fetch();

		if (Session::get('admin', false) || $media->user_id === Session::get('user_id')) {

			$filesystem = $this->getStorage();
			try {
				$filesystem->delete($media->storage_path);
			} catch (FileNotFoundException $e) {
				throw new NotFoundException($request, $response);
			} finally {
				$this->database->query('DELETE FROM `uploads` WHERE `id` = ?', $args['id']);
				$this->logger->info('User ' . Session::get('username') . ' deleted a media.', [$args['id']]);
			}
		} else {
			throw new UnauthorizedException();
		}

		return $response->withStatus(200);
	}

	/**
	 * @param $userCode
	 * @param $mediaCode
	 * @return mixed
	 */
	protected function getMedia($userCode, $mediaCode)
	{
		$mediaCode = pathinfo($mediaCode)['filename'];

		$media = $this->database->query('SELECT * FROM `uploads` INNER JOIN `users` ON `uploads`.`user_id` = `users`.`id` WHERE `user_code` = ? AND `uploads`.`code` = ? LIMIT 1', [
			$userCode,
			$mediaCode
		])->fetch();

		return $media;
	}

	/**
	 * @param Request $request
	 * @param Response $response
	 * @param Filesystem $storage
	 * @param $media
	 * @param string $disposition
	 * @return Response
	 * @throws FileNotFoundException
	 */
	protected function streamMedia(Request $request, Response $response, Filesystem $storage, $media, string $disposition = 'inline'): Response
	{
		$mime = $storage->getMimetype($media->storage_path);

		if ($request->getParam('width') !== null && explode('/', $mime)[0] === 'image') {

			$image = imagecreatefromstring($storage->read($media->storage_path));
			$scaled = imagescale($image, $request->getParam('width'), $request->getParam('height') !== null ? $request->getParam('height') : -1);
			imagedestroy($image);

			ob_start();
			imagepng($scaled, null, 9);

			$imagedata = ob_get_contents();
			ob_end_clean();
			imagedestroy($scaled);

			return $response
				->withHeader('Content-Type', 'image/png')
				->withHeader('Content-Disposition', $disposition . ';filename="scaled-' . pathinfo($media->filename)['filename'] . '.png"')
				->write($imagedata);
		} else {
			ob_end_clean();
			return $response
				->withHeader('Content-Type', $mime)
				->withHeader('Content-Disposition', $disposition . ';filename="' . $media->filename . '"')
				->withHeader('Content-Length', $storage->getSize($media->storage_path))
				->withBody(new Stream($storage->readStream($media->storage_path)));
		}
	}
}