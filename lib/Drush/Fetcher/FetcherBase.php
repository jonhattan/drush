<?php

/**
 * @file
 * Base class for 'fetcher' engine implementations.
 */

namespace Drush\Fetcher;

/**
 * Default amount of time, in seconds, to cache downloads via
 * drush_download_file(). One day is 86400 seconds.
 */
define('DRUSH_CACHE_LIFETIME_DEFAULT', 86400);

abstract class FetcherBase {

  private $engine_config;

  /**
   * Constructor.
   */
  public function __construct($type, $engine, $config) {
    $this->engine_type = $type;
    $this->engine = $engine;

    if (is_null($config)) {
      $config = array();
    }
    $config += array(
      'cache' => drush_get_option('cache', FALSE),
    );
    $this->engine_config = $config;
  }

  /**
   * Validate engine pre-requisites.
   */
  public function validate() {
    // Check wget or curl command exists. Disable possible output.
    $debug = drush_get_context('DRUSH_DEBUG');
    drush_set_context('DRUSH_DEBUG', FALSE);
    $success = drush_shell_exec('wget --version');
    if (!$success) {
      $success = drush_shell_exec('curl --version');
      // Old version of curl shipped in darwin returns error status for --version
      // and --help. Give the chance to use it.
      if (!$success) {
        $success = drush_shell_exec('which curl');
      }
    }
    drush_set_context('DRUSH_DEBUG', $debug);
    if (!$success) {
      return drush_set_error('DRUSH_SHELL_COMMAND_NOT_FOUND', dt('wget nor curl executables found.'));
    }

    return TRUE;
  }

  /**
   * Determine name of cached file based on url.
   */
  private static function downloadFileName($url) {
    if ($cache_dir = drush_directory_cache('download')) {
      $cache_name = str_replace(array(':', '/', '?', '='), '-', $url);
      return $cache_dir . '/' . $cache_name;
    }
    else {
      return FALSE;
    }
  }

  public static function deleteCachedDownload($url) {
    $cache_file = self::downloadFileName($url);
    if (file_exists($cache_file)) {
      unlink($cache_file);
    }
  }

  /**
   * Download a file or obtain it from download cache.
   *
   * @param string $url
   *   The url of the file to download.
   * @param string $destination
   *   The name of the file to be saved, which may include the full path.
   *   Optional, if omitted the filename will be extracted from the url and the
   *   file downloaded to the current working directory (Drupal root if
   *   bootstrapped).
   * @param integer $cache_duration
   *   The acceptable age of a cached file. If cached file is too old, a fetch
   *   will occur and cache will be updated. Optional, if ommitted the file will
   *   be fetched directly.
   *
   * @return string
   *   The path to the downloaded file, or FALSE if the file could not be
   *   downloaded.
   */
  public function fetch($url, $destination = FALSE, $cache_duration = 0) {
    // Generate destination if omitted.
    if (!$destination) {
      $file = basename(current(explode('?', $url, 2)));
      $destination = getcwd() . '/' . basename($file);
    }

    // Simply copy local files to the destination
    if (!_drush_is_url($url)) {
      return copy($url, $destination) ? $destination : FALSE;
    }

    if (drush_get_option('cache') && $cache_duration !== 0 && $cache_file = self::downloadFileName($url)) {
      // Check for cached, unexpired file.
      if (file_exists($cache_file) && filectime($cache_file) > ($_SERVER['REQUEST_TIME']-$cache_duration)) {
        drush_log(dt('!name retrieved from cache.', array('!name' => $cache_file)));
      }
      else {
        if (self::download($url, $cache_file, TRUE)) {
          // Cache was set just by downloading file to right location.
        }
        elseif (file_exists($cache_file)) {
          drush_log(dt('!name retrieved from an expired cache since refresh failed.', array('!name' => $cache_file)), 'warning');
        }
        else {
          $cache_file = FALSE;
        }
      }

      if ($cache_file && copy($cache_file, $destination)) {
        // Copy cached file to the destination
        return $destination;
      }
    }
    elseif ($return = self::download($url, $destination)) {
      drush_register_file_for_deletion($return);
      return $return;
    }

    // Unable to retrieve from cache nor download.
    return FALSE;
  }

  /**
   * Download a file using wget, curl or file_get_contents. Does not use download
   * cache.
   *
   * @param string $url
   *   The url of the file to download.
   * @param string $destination
   *   The name of the file to be saved, which may include the full path.
   * @param boolean $overwrite
   *   Overwrite any file thats already at the destination.
   * @return string
   *   The path to the downloaded file, or FALSE if the file could not be
   *   downloaded.
   */
  private static function download($url, $destination, $overwrite = TRUE) {
    static $use_wget;
    if ($use_wget === NULL) {
      $use_wget = drush_shell_exec('wget --version');
    }

    $destination_tmp = drush_tempnam('download_file');
    if ($use_wget) {
      drush_shell_exec("wget -q --timeout=30 -O %s %s", $destination_tmp, $url);
    }
    else {
      // Force TLS1+ as per https://github.com/drush-ops/drush/issues/894.
      drush_shell_exec("curl --tlsv1 --fail -s -L --connect-timeout 30 -o %s %s", $destination_tmp, $url);
    }
    if (!drush_file_not_empty($destination_tmp) && $file = @file_get_contents($url)) {
      @file_put_contents($destination_tmp, $file);
    }
    if (!drush_file_not_empty($destination_tmp)) {
      // Download failed.
      return FALSE;
    }

    drush_move_dir($destination_tmp, $destination, $overwrite);
    return $destination;
  }
}

