<?php

namespace Drupal\cua_cloudinary\StreamWrapper;

// These classes are used to implement a stream wrapper class.
use Cloudinary\Api\Exception\ApiError;
use Cloudinary\Cloudinary;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Flag for dealing with Cloudinary file.
 */
define('CLOUDINARY_STREAM_WRAPPER_FILE', 0100000 | 0777);

/**
 * The sample file name in root folder.
 */
define('CLOUDINARY_STREAM_WRAPPER_SAMPLE', 'sample.jpg');

/**
 * The resource_type of images on Cloudinary.
 */
define('CLOUDINARY_STREAM_WRAPPER_RESOURCE_IMAGE', 'image');

/**
 * The resource_type of none images on Cloudinary.
 */
define('CLOUDINARY_STREAM_WRAPPER_RESOURCE_RAW', 'raw');

/**
 * The tag prefix of Cloudinary used for list folder items by tag.
 */
define('CLOUDINARY_STREAM_WRAPPER_FOLDER_TAG_PREFIX', 'root/');

/**
 * Implement DrupalStreamWrapperInterface with cloudinary[.folder]://.
 */
class CloudyStreamWrapper implements StreamWrapperInterface {

  use StringTranslationTrait;

  /**
   * Instance URI (stream).
   *
   * A stream is referenced as "scheme://target".
   *
   * @var string
   */
  protected $uri;

  /**
   * Folder name as a prefix name of public_id.
   *
   * @var string
   */
  protected $folderName = NULL;

  /**
   * The resource type of Cloudinary (image, raw).
   *
   * @var string
   */
  protected $resourceType = CLOUDINARY_STREAM_WRAPPER_RESOURCE_IMAGE;

  /**
   * The pointer to the next read or write.
   *
   * @var int
   */
  protected $streamPointer = 0;

  /**
   * A buffer for reading/wrting.
   *
   * @var string
   */
  protected $streamData = NULL;

  /**
   * This $stream_write property is flagged for data written.
   *
   * @var bool
   */
  protected $streamWrite = FALSE;

  /**
   * List of files in a given directory.
   *
   * @var array
   */
  protected $directoryList = [];

  /**
   * A current file resource of Cloudinary.
   *
   * @var array
   */
  protected $resource = NULL;

  /**
   * A current file resource of Cloudinary.
   *
   * @var \Cloudinary\Cloudinary
   */
  protected $cl = NULL;

  /**
   * Returns the type of stream wrapper.
   *
   * @return int
   *   See StreamWrapperInterface for permissible values.
   */
  public static function getType() {
    return StreamWrapperInterface::NORMAL;
  }

  /**
   * Base implementation of setUri().
   */
  public function setUri($uri) {
    $this->uri = $uri;
  }

  /**
   * Base implementation of getUri().
   */
  public function getUri() {
    return $this->uri;
  }

  /**
   * Object constructor.
   *
   * Load Cloudinary PHP SDK & initialize Cloudinary configuration.
   */
  public function __construct() {
    $url = \Drupal::state()->get('cua_cloudinary.cloudinary_url') ?: getenv('CLOUDINARY_URL');
    if ($url) {
      try {
        $this->cl = new Cloudinary($url);
      }
      catch (\Exception $exception) {
        $this->logMessage('Cannot initialize connection. -- ' . $exception->getMessage(), $exception);
      }
    }
    else {
      // Throw error.
      $this->logMessage('Cloudinary env var not found.', getenv('CLOUDINARY_URL'));
    }
  }

  /**
   * Returns the name of the stream wrapper for use in the UI.
   *
   * @return string
   *   The stream wrapper name.
   */
  public function getName() {
    return $this->t('Cloudinary files');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('File system using Cloudinary');
  }

  /**
   * Check uri is an image style.
   */
  protected function imageStylePaths($uri) {
    $paths = explode('/', $this->getTarget($uri));
    $target = array_shift($paths);
    if ($target == 'styles') {
      return $paths;
    }

    return FALSE;
  }

  /**
   * Load file or directory resource for Cloudinary.
   */
  protected function loadResource($uri) {
    $public_id = $this->getPublicId($uri);

    if ($this->getFolder($public_id)) {
      return (object) [
        'mode' => octdec('0040000'),
        'bytes' => 0,
        'timestamp' => \Drupal::time()->getRequestTime(),
        'public_id' => $public_id,
        'asset_type' => 'folder',
      ];
    }

    // Try image.
    $resource = $this->cl->image($public_id);
    return $resource;
  }

  /**
   * Get file stream data from Cloudinary by http url.
   */
  protected function streamReadCloudinary() {
    static $data;
    if (isset($data[$this->uri])) {
      return $data[$this->uri];
    }

    $resource = $this->loadResource($this->uri);
    // If (!$resource || empty($resource['url'])) {.
    if (!$resource) {
      return FALSE;
    }

    try {
      $client = \Drupal::httpClient();
      $request = $client->request('GET', $resource->toUrl());
      // Expected result.
      $data[$this->uri] = $request->getBody();
    }
    catch (\Exception $exception) {
      $this->logMessage('Error reading Cloudinary stream. -- ' . $exception->getMessage(), $exception);
    }

    return $data[$this->uri];
  }

  /**
   * Get file status.
   *
   * @return bool|array
   *   An array with file status, or FALSE in case of an error - see fstat()
   *   for a description of this array.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-stat.php
   */
  protected function stat() {
    $resource = $this->loadResource($this->uri);

    // @todo Figure out better way to determine if resource has been uploaded
    $decoded_asset = isset($resource->asset) ? json_decode(json_encode($resource))->asset : (object) ['foo'];
    if (!$resource || !isset($decoded_asset->version)) {
      return FALSE;
    }

    // @todo Figure out what code gets here that isn't a fake directory.
    $stat = [];
    $stat[0] = $stat['dev'] = 0;
    $stat[1] = $stat['ino'] = 0;
    $stat[2] = $stat['mode'] = $resource['mode'];
    $stat[3] = $stat['nlink'] = 0;
    $stat[4] = $stat['uid'] = 0;
    $stat[5] = $stat['gid'] = 0;
    $stat[6] = $stat['rdev'] = 0;
    $stat[7] = $stat['size'] = $resource['bytes'];
    $stat[8] = $stat['atime'] = $resource['timestamp'];
    $stat[9] = $stat['mtime'] = $resource['timestamp'];
    $stat[10] = $stat['ctime'] = $resource['timestamp'];
    $stat[11] = $stat['blksize'] = 0;
    $stat[12] = $stat['blocks'] = 0;

    return $stat;
  }

  /**
   * Flush the stream buffers.
   */
  protected function flush() {
    $this->folderName = NULL;
    $this->directoryList = [];
    $this->streamData = NULL;
    $this->streamPointer = 0;
    $this->streamWrite = FALSE;
  }

  /**
   * Returns the local writable target of the resource within the stream.
   *
   * This function should be used in place of calls to realpath() or similar
   * functions when attempting to determine the location of a file. While
   * functions like realpath() may return the location of a read-only file, this
   * method may return a URI or path suitable for writing that is completely
   * separate from the URI used for reading.
   *
   * @param string $uri
   *   Optional URI.
   *
   * @return string
   *   Returns a string representing a location suitable for writing of a file,
   *   or FALSE if unable to write to the file such as with read-only streams.
   */
  protected function getTarget($uri = NULL) {
    if (!isset($uri)) {
      $uri = $this->uri;
    }

    list($scheme, $target) = explode('://', $uri, 2);

    // Check the scheme that include folder.
    $pos = strpos($scheme, '.');
    if ($pos) {
      $this->folderName = trim(substr($scheme, $pos + 1));
    }

    // Remove erroneous leading or trailing, forward-slashes and backslashes.
    return trim($target, '\/');
  }

  /**
   * Returns a Cloudinary public_id.
   */
  protected function getPublicId($uri = NULL) {
    $public_id = $this->getTarget($uri);

    // If scheme include folder, prepend it.
    if ($this->folderName) {
      $public_id = $this->folderName . '/' . $public_id;
    }

    // @todo Add code to check for resource type.
    // If (cloudinary_stream_wrapper_is_image($public_id)) {
    // $this->resourceType = CLOUDINARY_STREAM_WRAPPER_RESOURCE_IMAGE;.
    $public_id = preg_replace('/(.*)\.(jpe?g|png|gif|bmp)$/i', '\1', $public_id);
    // }
    return $public_id;
  }

  /**
   * Returns a web accessible URL for the resource.
   *
   * @return string
   *   A web accessible URL for the resource.
   */
  public function getExternalUrl() {
    $resource = $this->loadResource($this->uri);
    return $resource ? $resource->toUrl() : FALSE;
  }

  /**
   * Base implementation of getMimeType().
   */
  public static function getMimeType($uri, $mapping = NULL) {
    if (!isset($mapping)) {
      // The default file map, defined in file.mimetypes.inc is quite big.
      // We only load it when necessary.
      $mapping = \Drupal::service('file.mime_type.guesser')->guess($uri);
    }

    $extension = '';
    $file_parts = explode('.', \Drupal::service("file_system")->basename($uri));

    // Remove the first part: a full filename should not match an extension.
    array_shift($file_parts);

    // Iterate over the file parts, trying to find a match.
    // For my.awesome.image.jpeg, we try:
    // - jpeg
    // - image.jpeg, and
    // - awesome.image.jpeg.
    while ($additional_part = array_pop($file_parts)) {
      $extension = strtolower($additional_part . ($extension ? '.' . $extension : ''));
      if (isset($mapping['extensions'][$extension])) {
        return $mapping['mimetypes'][$mapping['extensions'][$extension]];
      }
    }

    return 'application/octet-stream';
  }

  /**
   * Base implementation of chmod().
   */
  public function chmod($mode) {
    return TRUE;
  }

  /**
   * Base implementation of realpath().
   */
  public function realpath() {
    return trim($this->uri, '\/');
  }

  /**
   * Support for fopen(), file_get_contents(), file_put_contents() etc.
   *
   * @param string $uri
   *   A string containing the URI to the file to open.
   * @param string $mode
   *   The file mode ("r", "wb" etc.).
   * @param string $options
   *   A bit mask of STREAM_USE_PATH and STREAM_REPORT_ERRORS.
   * @param string $opened_path
   *   A string containing the path actually opened.
   *
   * @return bool
   *   Returns TRUE if file was opened successfully.
   *
   * @see http://php.net/manual/streamwrapper.stream-open.php
   */
  public function stream_open($uri, $mode, $options, &$opened_path) {
    $this->uri = $uri;

    // If this stream is being opened for writing, clear the object buffer
    // Return true as we'll create the object on flush call.
    if (strpbrk($mode, 'wax')) {
      $this->flush();
      $this->streamWrite = TRUE;
      return TRUE;
    }

    $resource = $this->loadResource($uri);

    if ($resource) {
      $this->flush();
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Retrieve the underlying stream resource.
   *
   * This method is called in response to stream_select().
   *
   * @param int $cast_as
   *   Can be STREAM_CAST_FOR_SELECT when stream_select() is calling
   *   stream_cast() or STREAM_CAST_AS_STREAM when stream_cast() is called for
   *   other uses.
   *
   * @return resource|false
   *   The underlying stream resource or FALSE if stream_select() is not
   *   supported.
   *
   * @see stream_select()
   * @see http://php.net/manual/streamwrapper.stream-cast.php
   */
  public function stream_cast($cast_as) {
    return FALSE;
  }

  /**
   * Sets metadata on the stream.
   *
   * @param string $path
   *   A string containing the URI to the file to set metadata on.
   * @param int $option
   *   One of:
   *   - STREAM_META_TOUCH: The method was called in response to touch().
   *   - STREAM_META_OWNER_NAME: The method was called in response to chown()
   *     with string parameter.
   *   - STREAM_META_OWNER: The method was called in response to chown().
   *   - STREAM_META_GROUP_NAME: The method was called in response to chgrp().
   *   - STREAM_META_GROUP: The method was called in response to chgrp().
   *   - STREAM_META_ACCESS: The method was called in response to chmod().
   * @param mixed $value
   *   If option is:
   *   - STREAM_META_TOUCH: Array consisting of two arguments of the touch()
   *     function.
   *   - STREAM_META_OWNER_NAME or STREAM_META_GROUP_NAME: The name of the owner
   *     user/group as string.
   *   - STREAM_META_OWNER or STREAM_META_GROUP: The value of the owner
   *     user/group as integer.
   *   - STREAM_META_ACCESS: The argument of the chmod() as integer.
   *
   * @return bool
   *   Returns TRUE on success or FALSE on failure. If $option is not
   *   implemented, FALSE should be returned.
   *
   * @see http://www.php.net/manual/streamwrapper.stream-metadata.php
   */
  public function stream_metadata($path, $option, $value) {
    // We don't really do any of these, but we want to reassure the calling code
    // that there is no problem with chown or chgrp, even though we do not
    // actually support these.
    return TRUE;
  }

  /**
   * Change stream options.
   *
   * This method is called to set options on the stream.
   *
   * @param int $option
   *   One of:
   *   - STREAM_OPTION_BLOCKING: The method was called in response to
   *     stream_set_blocking().
   *   - STREAM_OPTION_READ_TIMEOUT: The method was called in response to
   *     stream_set_timeout().
   *   - STREAM_OPTION_WRITE_BUFFER: The method was called in response to
   *     stream_set_write_buffer().
   * @param int $arg1
   *   If option is:
   *   - STREAM_OPTION_BLOCKING: The requested blocking mode:
   *     - 1 means blocking.
   *     - 0 means not blocking.
   *   - STREAM_OPTION_READ_TIMEOUT: The timeout in seconds.
   *   - STREAM_OPTION_WRITE_BUFFER: The buffer mode, STREAM_BUFFER_NONE or
   *     STREAM_BUFFER_FULL.
   * @param int $arg2
   *   If option is:
   *   - STREAM_OPTION_BLOCKING: This option is not set.
   *   - STREAM_OPTION_READ_TIMEOUT: The timeout in microseconds.
   *   - STREAM_OPTION_WRITE_BUFFER: The requested buffer size.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise. If $option is not implemented, FALSE
   *   should be returned.
   */
  public function stream_set_option($option, $arg1, $arg2) {
    return FALSE;
  }

  /**
   * Truncate stream.
   *
   * Will respond to truncation; e.g., through ftruncate().
   *
   * @param int $new_size
   *   The new size.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise.
   *
   * @todo
   *   This one actually makes sense for the example.
   */
  public function stream_truncate($new_size) {
    return FALSE;
  }

  /**
   * Support for flock().
   *
   * @param string $operation
   *   One of the following:
   *   - LOCK_SH to acquire a shared lock (reader).
   *   - LOCK_EX to acquire an exclusive lock (writer).
   *   - LOCK_UN to release a lock (shared or exclusive).
   *   - LOCK_NB if you don't want flock() to block while locking (not
   *     supported on Windows).
   *
   * @return bool
   *   Always returns TRUE at the present time.
   *
   * @see http://php.net/manual/streamwrapper.stream-lock.php
   */
  public function stream_lock($operation) {
    return FALSE;
  }

  /**
   * Support for fread(), file_get_contents() etc.
   *
   * @param int $count
   *   Maximum number of bytes to be read.
   *
   * @return string
   *   The string that was read, or FALSE in case of an error.
   *
   * @see http://php.net/manual/streamwrapper.stream-read.php
   */
  public function stream_read($count) {
    if (!$this->streamData) {
      $stream_data = $this->streamReadCloudinary();

      if (!$stream_data) {
        return FALSE;
      }

      $this->streamData = $stream_data;
    }

    $data = substr($this->streamData, $this->streamPointer, $count);
    $this->streamPointer += $count;

    return $data;
  }

  /**
   * Support for fwrite(), file_put_contents() etc.
   *
   * @param string $data
   *   The string to be written.
   *
   * @return int
   *   The number of bytes written (integer).
   *
   * @see http://php.net/manual/streamwrapper.stream-write.php
   */
  public function stream_write($data) {
    // Write when flushed.
    $this->streamWrite = TRUE;
    $this->streamData .= $data;
    // Calculate date size.
    $bytes = strlen($data);
    $this->streamPointer += $bytes;

    return $bytes;
  }

  /**
   * Support for feof().
   *
   * @return bool
   *   TRUE if end-of-file has been reached.
   *
   * @see http://php.net/manual/streamwrapper.stream-eof.php
   */
  public function stream_eof() {
    if (!$this->streamData) {
      $stream_data = $this->streamReadCloudinary();

      if (!$stream_data) {
        return TRUE;
      }

      $this->streamData = $stream_data;
    }

    return $this->streamPointer >= strlen($this->streamData);
  }

  /**
   * Support for fseek().
   *
   * @param int $offset
   *   The byte offset to got to.
   * @param int $whence
   *   SEEK_SET, SEEK_CUR, or SEEK_END.
   *
   * @return bool
   *   TRUE on success.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-seek.php
   */
  public function stream_seek($offset, $whence = SEEK_SET) {
    $seek = FALSE;

    switch ($whence) {
      case SEEK_SET:
        if (strlen($this->streamData) >= $offset && $offset >= 0) {
          $this->streamPointer = $offset;
          $seek = TRUE;
        }
        break;

      case SEEK_CUR:
        if ($offset >= 0) {
          $this->streamPointer += $offset;
          $seek = TRUE;
        }
        break;

      case SEEK_END:
        if (strlen($this->streamData) + $offset >= 0) {
          $this->streamPointer = strlen($this->streamData) + $offset;
          $seek = TRUE;
        }
        break;
    }

    return $seek;
  }

  /**
   * Support for fflush().
   *
   * @return bool
   *   TRUE if data was successfully stored (or there was no data to store).
   *
   * @see http://php.net/manual/streamwrapper.stream-flush.php
   */
  public function stream_flush() {
    if ($this->streamWrite) {
      $public_id = $this->getPublicId($this->uri);
      $base64_data = 'data:' . self::getMimeType($this->uri) . ';base64,' . base64_encode($this->streamData);
      $dirname = dirname($public_id);
      if ($dirname == '.') {
        $dirname = '';
      }

      // @todo Add back resource type and tags?
      $options = [
        'public_id' => $public_id,
      // 'resource_type' => $this->resourceType,
      // 'tags' => CLOUDINARY_STREAM_WRAPPER_FOLDER_TAG_PREFIX . $dirname,
      ];

      try {
        $this->cl->uploadApi()->upload($base64_data, $options);
        return TRUE;
      }
      catch (ApiError $e) {
        $this->logMessage('Failed to upload file. -- ' . $e->getMessage(), $e);

        // @todo Rename file and still upload? Make a config setting for this feature.
        if ($e->getMessage() == '') {
          // @todo Make sure the public ID doesn't have file extension?
          $options['public_id'] = $options['public_id'] . '-1';

          try {
            $this->cl->uploadApi()->upload($base64_data, $options);
            return TRUE;
          }
          catch (ApiError $e) {
            $this->logMessage('Failed to rename and upload file. -- ' . $e->getMessage(), $e);
            return FALSE;
          }
        }
      }
    }

    $this->flush();

    return FALSE;
  }

  /**
   * Support for ftell().
   *
   * @return int
   *   The current offset in bytes from the beginning of file.
   *
   * @see http://php.net/manual/streamwrapper.stream-tell.php
   */
  public function stream_tell() {
    return $this->streamPointer;
  }

  /**
   * Support for fstat().
   *
   * @return array
   *   An array with file status, or FALSE in case of an error - see fstat()
   *   for a description of this array.
   *
   * @see http://php.net/manual/streamwrapper.stream-stat.php
   */
  public function stream_stat() {
    return $this->stat();
  }

  /**
   * Support for fclose().
   *
   * @return bool
   *   TRUE if stream was successfully closed.
   *
   * @see http://php.net/manual/streamwrapper.stream-close.php
   */
  public function stream_close() {
    $this->flush();

    return TRUE;
  }

  /**
   * Support for unlink().
   *
   * @param string $uri
   *   A string containing the URI to the resource to delete.
   *
   * @return bool
   *   TRUE if resource was successfully deleted.
   *
   * @see http://php.net/manual/streamwrapper.unlink.php
   */
  public function unlink($uri) {
    // If uri is an image style then ignore it.
    $paths = $this->imageStylePaths($uri);
    if ($paths !== FALSE) {
      return TRUE;
    }

    $resource = $this->loadResource($uri);

    if ($resource) {
      try {
        $this->cl->uploadApi()->destroy($this->getPublicId($uri));
        return TRUE;
      }
      catch (\Exception $exception) {
        $this->logMessage('Failed to destroy file. -- ' . $exception->getMessage(), $exception);
        return FALSE;
      }
    }

    return FALSE;
  }

  /**
   * Support for rename().
   *
   * @param string $from_uri
   *   The URI to the file to rename.
   * @param string $to_uri
   *   The new URI for file.
   *
   * @return bool
   *   TRUE if file was successfully renamed.
   *
   * @see http://php.net/manual/streamwrapper.rename.php
   */
  public function rename($from_uri, $to_uri) {
    // Check from_uri exist on Cloudinary.
    $from_resource = $this->loadResource($from_uri);

    if (!$from_resource) {
      return FALSE;
    }
    // Doesn't support folder rename.
    elseif ($from_resource['mode'] != CLOUDINARY_STREAM_WRAPPER_FILE) {
      return FALSE;
    }

    // Check to_uri exist on Cloudinary.
    $to_resource = $this->loadResource($to_uri, TRUE);

    if ($to_resource) {
      return FALSE;
    }

    // Return false if different resource type.
    $to_resource_type = (bool) preg_match('/\.(jpe?g|png|gif|bmp|webp)$/i', $to_uri)
      ? CLOUDINARY_STREAM_WRAPPER_RESOURCE_IMAGE
      : CLOUDINARY_STREAM_WRAPPER_RESOURCE_RAW;

    if ($from_resource['resource_type' != $to_resource_type]) {
      return FALSE;
    }

    // @todo Work on file rename code.
    // $to_public_id = $this->getPublicId($to_uri);
    // $src_folder = dirname($from_resource['public_id']);
    // $dst_folder = dirname($to_public_id);
    // Try {
    // $data = Uploader::rename($from_resource['public_id'], $to_public_id);
    // // Replace new tag if folder name different.
    // if ($src_folder != $dst_folder) {
    // $tag = CLOUDINARY_STREAM_WRAPPER_FOLDER_TAG_PREFIX . $dst_folder;
    // Uploader::replace_tag($tag, [$data['public_id']]);
    // }
    // // Load new file resource.
    // $dst_resource = cloudinary_stream_wrapper_load_file(
    // $data['public_id'], ['resource_type' => $data['resource_type']]);
    // \Drupal::moduleHandler()->invokeAll(
    // 'cloudinary_stream_wrapper_resource_rename', [
    // $from_resource,
    // $dst_resource,
    // ]);
    // return TRUE;
    // }
    // catch (Exception $e) {
    // $options = [
    // '%file' => $src_resource['public_id'],
    // '%newfile' => $dst_public_id,
    // '%message' => $e->getMessage(),
    // ];
    // cloudinary_stream_wrapper_logger(
    // 'Rename file [%file] to new file [%newfile] failed,
    // [%message].', $options);
    // }.
    return FALSE;
  }

  /**
   * Gets the name of the directory from a given path.
   *
   * This method is usually accessed through drupal_dirname(), which wraps
   * around the PHP dirname() function because it does not support stream
   * wrappers.
   *
   * @param string $uri
   *   A URI or path.
   *
   * @return string
   *   A string containing the directory name.
   *
   * @see drupal_dirname()
   */
  public function dirname($uri = NULL) {
    list($scheme, $target) = explode('://', $uri, 2);
    $target = $this->getTarget($uri);
    $dirname = dirname($target);

    if ($dirname == '.') {
      $dirname = '';
    }

    return $scheme . '://' . $dirname;
  }

  /**
   * Support for mkdir().
   *
   * @param string $uri
   *   A string containing the URI to the directory to create.
   * @param string $mode
   *   Permission flags - see mkdir().
   * @param string $options
   *   A bit mask of STREAM_REPORT_ERRORS and STREAM_MKDIR_RECURSIVE.
   *
   * @return bool
   *   TRUE if directory was successfully created.
   *
   * @see http://php.net/manual/streamwrapper.mkdir.php
   */
  public function mkdir($uri, $mode, $options) {
    $resource = $this->loadResource($uri);

    if (!empty($resource)) {
      return TRUE;
    }
    $public_id = $this->getPublicId($uri);

    try {
      // @todo Figure out if this is needed to create directories.
      $resource = [
        'public_id' => $public_id,
        'mode' => CLOUDINARY_STREAM_WRAPPER_FOLDER,
        'bytes' => 0,
        'timestamp' => 0,
        'folders' => [],
        'files' => [],
      ];

      return TRUE;
    }
    catch (\Exception $e) {
      $options = [
        '%folder' => $public_id,
        '%message' => $e->getMessage(),
      ];
    }

    return FALSE;
  }

  /**
   * Support for rmdir().
   *
   * @param string $uri
   *   A string containing the URI to the directory to delete.
   * @param string $options
   *   A bit mask of STREAM_REPORT_ERRORS.
   *
   * @return bool
   *   TRUE if directory was successfully removed.
   *
   * @see http://php.net/manual/streamwrapper.rmdir.php
   */
  public function rmdir($uri, $options) {
    return FALSE;

    // @todo Figure out if this is needed.
    // If ($resource = $this->loadResource($uri)) {
    // // Taken from cloudinary/modules/cloudinary_stream_wrapper/cloudinary_stream_wrapper.module#L449
    // if ($resource['mode'] != CLOUDINARY_STREAM_WRAPPER_FOLDER) {
    // return FALSE;
    // }
    // try {
    // $api = new Api();
    // $api->delete_resources_by_prefix($resource['public_id'], [
    // 'resource_type' => CLOUDINARY_STREAM_WRAPPER_RESOURCE_IMAGE
    // ]);
    // $api->delete_resources_by_prefix($resource['public_id'], [
    // 'resource_type' => CLOUDINARY_STREAM_WRAPPER_RESOURCE_RAW]
    // );
    // \Drupal::moduleHandler()->invokeAll('cloudinary_stream_wrapper_resource_delete',
    // [$resource]);
    // return TRUE;
    // }
    // catch (Exception $e) {
    // }
    // return FALSE;
    // }
    // return FALSE;.
  }

  /**
   * Support for stat().
   *
   * @param string $uri
   *   A string containing the URI to get information about.
   * @param string $flags
   *   A bit mask of STREAM_URL_STAT_LINK and STREAM_URL_STAT_QUIET.
   *
   * @return array
   *   An array with file status, or FALSE in case of an error - see fstat()
   *   for a description of this array.
   *
   * @see http://php.net/manual/streamwrapper.url-stat.php
   */
  public function url_stat($uri, $flags) {
    $this->uri = $uri;

    return $this->stat();
  }

  /**
   * Support for opendir().
   *
   * @param string $uri
   *   A string containing the URI to the directory to open.
   * @param string $options
   *   Unknown (parameter is not documented in PHP Manual).
   *
   * @return bool
   *   TRUE on success.
   *
   * @see http://php.net/manual/streamwrapper.dir-opendir.php
   */
  public function dir_opendir($uri, $options) {
    $resource = $this->loadResource($uri);

    if ($resource) {
      $list = ['.', '..'];

      if (isset($this->resource['folders']) && !empty($this->resource['folders'])) {
        $list = array_merge($list, $this->resource['folders']);
      }

      if (isset($this->resource['files']) && !empty($this->resource['files'])) {
        $list = array_merge($list, $this->resource['files']);
      }

      // Append default file "sample.jpg" into root folder.
      $public_id = $this->getPublicId($uri);
      if ('' == $public_id && !in_array(CLOUDINARY_STREAM_WRAPPER_SAMPLE, $list)) {
        $list[] = CLOUDINARY_STREAM_WRAPPER_SAMPLE;
      }

      sort($list);
      $this->directoryList = $list;

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Support for readdir().
   *
   * @return string
   *   The next filename, or FALSE if there are no more files in the directory.
   *
   * @see http://php.net/manual/streamwrapper.dir-readdir.php
   */
  public function dir_readdir() {
    $filename = current($this->directoryList);

    if ($filename !== FALSE) {
      next($this->directoryList);
    }

    return $filename;
  }

  /**
   * Support for rewinddir().
   *
   * @return bool
   *   TRUE on success.
   *
   * @see http://php.net/manual/streamwrapper.dir-rewinddir.php
   */
  public function dir_rewinddir() {
    reset($this->directoryList);

    return TRUE;
  }

  /**
   * Support for closedir().
   *
   * @return bool
   *   TRUE on success.
   *
   * @see http://php.net/manual/streamwrapper.dir-closedir.php
   */
  public function dir_closedir() {
    $this->directoryList = [];

    return TRUE;
  }

  /**
   * Determine if a public id is a folder.
   *
   * @param string|null $public_id
   *   Public ide of resource.
   *
   * @return bool
   *   TRUE if folder, FALSE if not.
   */
  private function getFolder(?string $public_id): bool {
    // @todo need to test folder creation in November.
    $folders = [
      '2020-09',
      '2020-10',
      '2020-11',
      '2020-12',
      'inline-images',
    ];

    return in_array($public_id, $folders);
  }

  /**
   * Logs a message.
   *
   * @param string $message
   *   Message needing logged.
   * @param mixed $payload
   *   The complete exception logged for more info.
   */
  private function logMessage(string $message, $payload) {
    $log_message = $message . "\n\n" . json_encode($payload);
    \Drupal::logger('cloudinary')->error($log_message);
    \Drupal::messenger()->addError($log_message);

    // @todo Add Slack error notice too?
  }

}
