#!/usr/bin/env php
<?php

/**
 * This command line application fetchs a list of URLs from the Alexa Toplist (how many can be specified) and fetches
 * all images from the homepage of each website. Afterwards the images are optimizes and a statistic is generated. This
 * application was written for Task 3 of my SE Research Seminar 1 tution at the FH Salzburg.
 *
 * @todo Parallel processing of everything that could be done in parallel.
 * @author Richard Fussenegger <richard@fussenegger.info>
 * @version 0.0.1
 */

namespace SEResearchSeminar1;

define('CWD', getcwd());
define('DATADIR', CWD . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR);

require CWD . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Delete all directories and files within this directory.
 *
 * @param string $directory
 *   Absolute or relative path to directory.
 */
function unlink_recursive($directory) {
  foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $path) {
    $path->isFile() ? unlink($path->getPathname()) : rmdir($path->getPathname());
  }
}

/**
 * Actual application logic is wrapped in a command.
 *
 * @package SEResearchSeminar1
 */
class Task3Command extends Command {


  // ------------------------------------------------------------------------------------------------------------------- Constants


  /**
   * The minimum value that is valid for our Alexa Toplist host count.
   */
  const HOSTS_MIN_COUNT = 1;

  /**
   * The default value that is used for our Alexa Toplist host count.
   */
  const HOSTS_DEFAULT_COUNT = 10;

  /**
   * The maximum value that is valid for our Alexa Toplist host count.
   */
  const HOSTS_MAX_COUNT = 1000000;

  /**
   *
   */
  const CLEAN_STATS = 1;

  /**
   *
   */
  const CLEAN_OPTIMIZED_IMAGES = 2;

  /**
   *
   */
  const CLEAN_ALL_IMAGES = 3;

  /**
   *
   */
  const CLEAN_TOPLIST = 4;

  /**
   *
   */
  const CLEAN_ALL = 5;


  // ------------------------------------------------------------------------------------------------------------------- Properties


  /**
   * @var InputInterface
   */
  private $in;

  /**
   * @var OutputInterface
   */
  private $out;

  /**
   * @var string
   */
  private $zipFilePath;

  /**
   * @var string
   */
  private $csvFilePath;

  /**
   * @var int
   */
  private $hostCount;

  /**
   * @var array
   */
  private $hosts;

  /**
   * @var string
   */
  private $alexaToplistFilePath;

  /**
   * @var string
   */
  private $hostsImagesPath;

  /**
   * @var string
   */
  private $optimizedFilePath;

  /**
   * @var string
   */
  private $statsFilePath;


  // ------------------------------------------------------------------------------------------------------------------- Methods


  /**
   * @param string $name
   */
  public function __construct($name = null) {
    parent::__construct($name);

    $this->hostCount = self::HOSTS_DEFAULT_COUNT;
    $this->zipFilePath = DATADIR . 'top-1m.csv.zip';
    $this->csvFilePath = DATADIR . 'top-1m.csv';
    $this->alexaToplistFilePath = DATADIR . 'hosts.json';
    $this->hostsImagesPath = DATADIR . 'images';
    $this->optimizedFilePath = DATADIR . '.optimized';
    $this->statsFilePath = DATADIR . 'stats.json';
  }

  /**
   * Define command specific information and options.
   */
  protected function configure() {
    $this
      ->setName('se-research-seminar-1-task-3')
      ->setDescription('Fetch and optimize images from a websites homepage.')
      ->addOption(
        'alexa',
        'a',
        InputOption::VALUE_OPTIONAL,
        'Define how many domains shall be fetched from the Alexa Toplist. Valid values range from 1 to 1000000.',
        self::HOSTS_DEFAULT_COUNT
      )
      ->addOption(
        'clean',
        'c',
        InputOption::VALUE_OPTIONAL,
        'Clean up the data folder. Valid values are:' . PHP_EOL .
        '  <info>' . self::CLEAN_STATS . '</info> - Remove the stats.json file.' . PHP_EOL .
        '  <info>' . self::CLEAN_OPTIMIZED_IMAGES . '</info> - Remove the optimized images.' . PHP_EOL .
        '  <info>' . self::CLEAN_ALL_IMAGES . '</info> - Remove all images.' . PHP_EOL .
        '  <info>' . self::CLEAN_TOPLIST . '</info> - Remove the Alexa Toplist.' . PHP_EOL .
        '  <info>' . self::CLEAN_ALL . '</info> - Remove everything!' . PHP_EOL,
        self::CLEAN_ALL
      )
    ;
  }

  /**
   * Display error message and exit application.
   *
   * @param string $message
   *   The message that should be displayed to the user, explaining what went wrong.
   */
  private function exitOnError($message) {
    $this->out->writeln('<error>' . $message . '. Exiting!</error>');
    exit(-1);
  }

  /**
   * Download the Alexa Toplist from Amazon for creating our own Toplist.
   *
   * @return \SEResearchSeminar1\Task3Command
   */
  private function download() {
    if (file_exists($this->zipFilePath)) {
      return $this;
    }

    if (!file_exists(DATADIR)) {
      mkdir(DATADIR);
    }

    /* @var $downloadURL string */
    $downloadURL = 'http://s3.amazonaws.com/alexa-static/top-1m.csv.zip';

    $this->out->writeln('<info>Starting download of Alexa Toplist archive.</info>' . PHP_EOL);

    /* @var $downloadFileHandle resource */
    $downloadFileHandle = fopen($downloadURL, 'rb');

    /* @var $zipFileHandle resource */
    $zipFileHandle = fopen($this->zipFilePath, 'wb');

    foreach ([ 'URL' => $downloadFileHandle, 'archive' => $zipFileHandle ] as $resource => $fileHandle) {
      if (!$fileHandle) {
        $this->exitOnError('Could not open ' . $resource);
      }
    }

    /* @var $headers array */
    $headers = array_change_key_case(get_headers($downloadURL, 1), CASE_LOWER);

    if (strstr($headers[0], '200 OK') === FALSE) {
      $this->exitOnError('Download server did not reply with OK. Returned header code was: "' . $headers[0] . '"');
    }

    /* @var $contentLength int */
    $contentLength = $headers['content-length'];

    /* @var $length int */
    $length = 1024;

    /* @var $written int */
    $written = 0;

    /* @var $progress \Symfony\Component\Console\Helper\ProgressHelper */
    $progress = $this->getHelper('progress');
    $progress->setFormat('  %current%/%max% MB [%bar%] %percent%%');
    $progress->start($this->out, $contentLength / $length);

    while (!feof($downloadFileHandle)) {
      $written += fwrite($zipFileHandle, fread($downloadFileHandle, $length), $length);

      if ($written >= $length) {
        $written %= $length;
        $progress->advance();
      }
    }

    $progress->finish();

    foreach ([ $downloadFileHandle, $zipFileHandle ] as $fileHandle) {
      fclose($fileHandle);
    }

    $this->out->writeln(PHP_EOL . '<info>Finished downloading Alexa Toplist archive!</info>' . PHP_EOL);

    return $this;
  }

  /**
   * Extract the Alexa Toplist archive and place CSV in current working directory.
   *
   * @return \SEResearchSeminar1\Task3Command
   */
  private function extract() {
    if (!file_exists($this->zipFilePath)) {
      $this->download();
    }

    if (file_exists($this->csvFilePath)) {
      return $this;
    }

    /* @var $zipArchive \ZipArchive */
    $zipArchive = new \ZipArchive();

    /* @var $zipFileHandle resource */
    if (!($zipFileHandle = $zipArchive->open($this->zipFilePath))) {
      $this->exitOnError('Could not open archive');
    }

    $this->out->writeln('<info>Starting archive extraction.</info>');
    $zipArchive->extractTo(DATADIR);

    /* @var $extractedFileName string */
    $extractedFileName = $zipArchive->getNameIndex(0);
    $zipArchive->close();

    if (basename($this->csvFilePath) !== $extractedFileName) {
      rename($extractedFileName, $this->csvFilePath);
      $extractedFileName = basename($this->csvFilePath);
    }

    $this->out->writeln('<info>Finished archive extraction!</info>' . PHP_EOL);

    return $this;
  }

  /**
   * Generate list of URLs from which we are going to fetch the images.
   *
   * @return \SEResearchSeminar1\Task3Command
   */
  private function alexa() {
    if (!file_exists($this->csvFilePath)) {
      $this->extract();
    }

    if (file_exists($this->alexaToplistFilePath)) {
      return $this;
    }

    $this->out->writeln('<info>Starting to parse CSV.</info>');

    /* @var $csvFileHandle resource */
    if (!($csvFileHandle = fopen($this->csvFilePath, 'r'))) {
      $this->exitOnError('Could not open CSV file');
    }

    /* @var $i int */
    $i = 1;

    while (($line = fgets($csvFileHandle)) !== FALSE && $i <= $this->hostCount) {
      $this->hosts[] = trim(split(',', $line)[1]);
      $i++;
    }

    fclose($csvFileHandle);

    if (count($this->hosts) < 1) {
      $this->exitOnError('Could not parse CSV file [ hostCount: ' . $this->hostCount . ' ]');
    }

    file_put_contents($this->alexaToplistFilePath, json_encode($this->hosts));

    $this->out->writeln('<info>Finished parsing CSV!</info>' . PHP_EOL);

    return $this;
  }

  /**
   * Attempt to correct the given URL to a proper URL for downloading a resource.
   *
   * @param string $url
   * @param string $hostname
   * @param array $options
   *   <ul>
   *     <li><code>keepQueryString</code>: Do not attempt to remove the query string.</li>
   *     <li><code>stylesheetPath</code>: The complete path of the stylesheet, very important for relative paths.</li>
   *   </ul>
   * @return null|string
   */
  private function normalizeURL($url, $hostname, $options = array()) {
    if (strlen($url) <= 0) {
      return NULL;
    }

    if (isset($options['stylesheetPath'])) {
      /* @var $parsedURL array */
      $parsedURL = parse_url($options['stylesheetPath']);
      $hostname = sprintf('%s://%s', $parsedURL['scheme'], $parsedURL['host']);
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      if (!filter_var($url, FILTER_VALIDATE_URL, FILTER_FLAG_HOST_REQUIRED)) {
        // Check if the given URL starts with two dots and try to resolve the relative path to an absolute one.
        if ('..' === ($url[0] . $url[1])) {
          if (!isset($options['stylesheetPath'])) {
            return NULL;
          }

          $url = explode('/', dirname($options['stylesheetPath']) . $url);
          foreach (array_keys($url, '..') as $delta => $key) {
            array_splice($url, $key - ($delta * 2 + 1), 2);
          }
          $url = str_replace('./', '', implode('/', $url));
        }
        // Check if the given URL is a protocol relative one (e.g. //google.com).
        elseif ('//' === ($url[0] . $url[1])) {
          $url = 'http:' . $url;
        }
        // Check if the given URL is an absolute URL without hostname (e.g. /favicon.ico).
        elseif ('/' === $url[0]) {
          $url = $hostname . $url;
        }
        // Check if the given URL is a relative URL without hostname (e.g. favicon.ico).
        else {
          $url = $hostname . '/' . $url;
        }
      }

      // @todo Should we support HTTPS at this point?
      if (!filter_var($url, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED)) {
        $url = 'http://' . $url;
      }
    }

    if (isset($options['keepQueryString']) && $options['keepQueryString'] === TRUE) {
      return $url;
    }

    // Now try to parse the URL, if this fails drop the URL.
    if (!($url = parse_url($url))) {
      return FALSE;
    }

    // Re-create the URL without query strings.
    return sprintf('%s://%s%s', $url['scheme'], $url['host'], $url['path']);
  }

  /**
   * Save the remote image specified by parameter <code>$src</code> into the folder specified by <code>$folder</code>.
   *
   * @param string $src
   *   The already normalized URL of the remote image.
   * @param string $folder
   *   The absolute path to the folder where we should save the remote image.
   * @return boolean
   *   Returns <code>FALSE</code> if something is wrong with this image.
   */
  private function saveImage($src, $folder) {
    /* @var $type int */
    if (!($type = @getimagesize($src)[2])) {
      return FALSE;
    }

    /* @var $ext string */
    $ext = '.';

    switch ($type) {
      case IMAGETYPE_GIF:
        $ext .= 'gif';
        break;

      case IMAGETYPE_JPEG:
        $ext .= 'jpg';
        break;

      case IMAGETYPE_PNG:
        $ext .= 'png';
        break;

      default:
        return FALSE;
    }

    $this->out->writeln($folder . DIRECTORY_SEPARATOR . md5($src) . $ext);

    // We have to create a hash for the filename, because images are sometimes identified by path and not by filename.
    return copy($src, $folder . DIRECTORY_SEPARATOR . md5($src) . $ext);
  }

  /**
   * Extract URLs from CSS text.
   *
   * @link http://nadeausoftware.com/articles/2008/01/php_tip_how_extract_urls_css_file
   * @param string $text
   * @param boolean $atImport
   * @return array
   */
  private function extractCSSURLs($text, $atImport = FALSE) {
    /* @var $urls array */
    $urls = [];

    /* @var $matches array */
    $matches = [];

    /* @var $URLpattern string */
    $URLpattern = '(([^\\\\\'", \(\)]*(\\\\.)?)+)';

    /* @var $URLfnPattern string */
    $URLfnPattern = 'url\(\s*[\'"]?' . $URLpattern . '[\'"]?\s*\)';

    /* @var $pattern string */
    $pattern = '/((@import\s*[\'"]' . $URLpattern . '[\'"])|(@import\s*' . $URLfnPattern . ')|(' . $URLfnPattern . ')' . ')/iu';

    // Nothing matched!
    if (!preg_match_all($pattern, $text, $matches)) {
      return FALSE;
    }

    if ($atImport) {
      // @import '...'
      // @import "..."
      foreach ($matches[3] as $match) {
        if (!empty($match)) {
          $urls[] = preg_replace('/\\\\(.)/u', '\\1', $match);
        }
      }

      // @import url(...)
      // @import url('...')
      // @import url("...")
      foreach ($matches[7] as $match) {
        if (!empty($match)) {
          $urls[] = preg_replace('/\\\\(.)/u', '\\1', $match);
        }
      }
    } else {
      // url(...)
      // url('...')
      // url("...")
      foreach ($matches[11] as $match) {
        if (!empty($match)) {
          $urls[] = preg_replace('/\\\\(.)/u', '\\1', $match);
        }
      }
    }

    return $urls;
  }

  /**
   * Fetch the hompage of each website and download all images that are references within the document itself or the
   * linked stylesheets.
   *
   * @return \SEResearchSeminar1\Task3Command
   */
  private function fetch() {
    if (!file_exists($this->alexaToplistFilePath)) {
      $this->alexa();
    }

    if (file_exists($this->hostsImagesPath)) {
      return $this;
    }

    $this->out->writeln('<info>Starting to download images from ' . $this->hostCount . ' top websites.</info>');

    if (!$this->hosts) {
      $this->hosts = (array) json_decode(file_get_contents($this->alexaToplistFilePath));
    }

    if (!file_exists($this->hostsImagesPath)) {
      mkdir($this->hostsImagesPath);
    }

    /* @var $ch resource */
    $ch = curl_init();

    // See http://www.php.net/manual/en/function.curl-setopt.php for all possible options.
    curl_setopt_array($ch, [
      CURLOPT_AUTOREFERER => TRUE,
      CURLOPT_FAILONERROR => TRUE,
      CURLOPT_FOLLOWLOCATION => TRUE,
      CURLOPT_FORBID_REUSE => FALSE,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_MAXREDIRS => 20,
      CURLOPT_ENCODING => '',
      CURLOPT_HTTPHEADER => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.5'
      ],
      CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:21.0) Gecko/20100101 Firefox/21.0',
    ]);

    /* @var $progress \Symfony\Component\Console\Helper\ProgressHelper */
    $progress = $this->getHelper('progress');

    foreach ($this->hosts as $hostname) {
      $this->out->writeln(PHP_EOL . 'Fetching images from <info>' . $hostname . '</info>:');

      /* @var $websitePath string */
      $websitePath = $this->hostsImagesPath . DIRECTORY_SEPARATOR . $hostname;
      if (!file_exists($websitePath)) {
        mkdir($websitePath);
      }

      curl_setopt($ch, CURLOPT_URL, 'http://' . $hostname);

      /* @var $homepage string */
      $homepage = curl_exec($ch);

      /* @var $crawler \Symfony\Component\DomCrawler\Crawler */
      $crawler = new Crawler($homepage);

      // No clue what facebook is doing, but do not process if this is facebook.
      if ($hostname !== 'facebook.com') {
        /* @var $metaRefresh string */
        if (count($metaRefresh = $crawler->filter('meta[http-equiv="refresh"]')) > 0) {
          /**
           * @todo Should we follow more then one meta refresh?
           */
          curl_setopt($ch, CURLOPT_URL, preg_replace('/[0-9]+;url=/i', '', $metaRefresh->attr('content')));
          $homepage = curl_exec($ch);
          $crawler = new Crawler($homepage);
        }
      }

      /* @var $images array */
      $images = $crawler->filter('img');

      /* @var $imagesCount int */
      if (($imagesCount = count($images)) > 0) {
        $progress->setFormat('  Downloading images                  <comment>[%bar%]</comment> %percent%% <comment>%current%/%max%</comment>');
//        $progress->start($this->out, $imagesCount);

        foreach ($images as $image) {
          /* @var $src string */
          if (!($src = $this->normalizeURL($image->getAttribute('src'), $hostname))) {
            continue;
          }
          $this->saveImage($src, $websitePath);
//          $progress->advance();
        }

//        $progress->finish();
      }

      /* @var $links array */
      $links = $crawler->filter('link');

      /* DEV */ $links = []; /* DEV */

      /* @var $linksCount int */
      if (count($links) > 0) {
        $i = 1;
        foreach ($links as $link) {
          // Is this save?
          if ($link->getAttribute('rel') !== 'stylesheet') {
            continue;
          }

          /* @var $stylesheetPath string */
          $stylesheetPath = $this->normalizeURL($link->getAttribute('href'), $hostname, [ 'keepQueryString' => TRUE ]);

          /* @var $stylesheet string */
          $stylesheet = file_get_contents($stylesheetPath);

          /* @var $images array */
          if (!($images = $this->extractCSSURLs($stylesheet))) {
            continue;
          }

          $progress->setFormat('  Downloading CSS stylesheet ' . $i++ . ' images <comment>[%bar%]</comment> %percent%% <comment>%current%/%max%</comment>');
          $progress->start($this->out, count($images));

          foreach ($images as $image) {
            /* @var $src string */
            if (!($src = $this->normalizeURL($image, $hostname, [ 'stylesheetPath' => $stylesheetPath ]))) {
              continue;
            }
            $this->saveImage($src, $websitePath);
            $progress->advance();
          }

          $progress->finish();
        }
      }

      /**
       * @todo Extend with more functionality, e.g. snatch images from inline styles, JavaScript, iframes or even AJAX.
       */
    }

    curl_close($ch);

    $this->out->writeln('<info>Finished downloading images from top websites.</info>' . PHP_EOL);

    return $this;
  }

  /**
   * Go through the images folder and optimize the images of each subfolder.
   *
   * @return \SEResearchSeminar1\Task3Command
   */
  private function optimize() {
    if (!file_exists($this->hostsImagesPath)) {
      $this->fetch();
    }

    if (file_exists($this->optimizedFilePath)) {
      return $this;
    }

    /* @var $paths array */
    $paths = [ 'imgmin' => '', 'webp' => '', 'tmp' => '' ];

    foreach ($paths as $foldernameSuffix => $value) {
      $paths[$foldernameSuffix] = $this->hostsImagesPath . '-' . $foldernameSuffix;
      if (!file_exists($paths[$foldernameSuffix])) {
        mkdir($paths[$foldernameSuffix]);
      }
    }

    if (file_exists($this->optimizedFilePath)) {
      unlink($this->optimizedFilePath);
    }

    /* @var $folders array */
    $folders = glob($this->hostsImagesPath . '/*', GLOB_ONLYDIR);

    if (count($folders) < 1) {
      $this->fetch();
    }

    $this->out->writeln('<info>Starting to optimize top website images.</info>' . PHP_EOL . 'A skipped progress indicates an animated GIF.' . PHP_EOL);

    foreach (glob($this->hostsImagesPath . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $hostImagesPath) {
      /* @var $hostname string */
      $hostname = basename($hostImagesPath);

      /* @var $hostPaths array */
      $hostPaths = [];

      foreach ($paths as $foldernameSuffix => $path) {
        $hostPaths[$foldernameSuffix] = $path . DIRECTORY_SEPARATOR . $hostname;
        if (!file_exists($hostPaths[$foldernameSuffix])) {
          mkdir($hostPaths[$foldernameSuffix]);
        }
      }

      /* @var $images array */
      $images = glob($hostImagesPath . DIRECTORY_SEPARATOR . '*.*');

      /* @var $progress \Symfony\Component\Console\Helper\ProgressHelper */
      $progress = $this->getHelper('progress');
      $progress->setFormat(
        '<info>' .
        sprintf('%20.' . strlen($hostname) . 's', $hostname) .
        ':</info> <comment>[%bar%]</comment> %percent%% <comment>%current%/%max%</comment>'
      );
      $progress->start($this->out, count($images));

      foreach ($images as $image) {
        /* @var $imageNameWithExtension string */
        $imageNameWithExtension = basename($image);

        /* @var $imageName string */
        $imageName = pathinfo($image)['filename'];

        /* @var $type bits */
        $type = getimagesize($image)[2];

        /* @var $webpCommand string */
        $webpCommand = 'cwebp';

        /* @var $webpOptions string */
        $webpOptions = '-quiet -af -mt -m 6 -f 40 -q 80';

        // Beware! We can not optimize animated GIFs at all. So we have to check if this might be an animated GIF. If
        // it is, simply copy it to the destination folder without any further optimizations. This is something that's
        // not covered by the imgmin tool collection and not by WebP. We could create a APNG image, but the browser
        // support is very limited. And we could separate the image into it's frames, optimize each frame and put it
        // back together.
        //
        // @todo Optimization of animated GIF images.
        if ($type === IMAGETYPE_GIF) {
          /* @var $gifFileHandle resource */
          if (!($gifFileHandle = fopen($image, 'rb'))) {
            continue;
          }

          /* @var $count int */
          $count = 0;

          while (!feof($gifFileHandle) && $count < 2) {
            $count += preg_match_all('#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', fread($gifFileHandle, 102400));
          }

          fclose($gifFileHandle);

          // Simply copy the animated GIF.
          if ($count > 2) {
            foreach ($hostPaths as $foldernameSuffix => $path) {
              if ($foldernameSuffix === 'tmp') {
                continue;
              }
              copy($image, $path . DIRECTORY_SEPARATOR . $imageNameWithExtension);
            }

            continue;
          }

          // The GIFLib always throws error 0 "failed to open file" for no reason. We stick to converting the GIF file
          // to a PNG file.
//          $webpCommand = 'gif2webp';
//          $webpOptions = '-quiet -m 6 -f 40';

          $imageNameWithExtension = $imageName . '.png';

          /* @var $imageTmp string */
          $imageTmp = $hostPaths['tmp'] . DIRECTORY_SEPARATOR . $imageNameWithExtension;

          /* @var $imagick \Imagick */
          $imagick = new \Imagick();
          $imagick->readimage($image);
          $imagick->setimageformat('png');
          $imagick->writeimage($imageTmp);

          // Update path with new path.
          $image = $imageTmp;
          unset($imageTmp);
        }

        /* @var $imgminImagePath string */
        $imgminImagePath = $hostPaths['imgmin'] . DIRECTORY_SEPARATOR . $imageNameWithExtension;

        // Auto optimize the image with imgmin.
        // https://github.com/rflynn/imgmin
        shell_exec('imgmin ' . $image . ' ' . $imgminImagePath . ' 2>&1 >/dev/null');

        // If this is a PNG try to optimize it even more with improved pngquant.
        // https://github.com/pornel/improved-pngquant
        if ($type === IMAGETYPE_PNG) {
          /* @var $pngquantImagePath string */
          $pngquantImagePath = $hostPaths['imgmin'] . DIRECTORY_SEPARATOR . $imageNameWithExtension . '.quant';

          shell_exec('pngquant --ext .png.quant -- ' . $image . ' 2>&1 >/dev/null');
          rename($image . '.quant', $pngquantImagePath);

          if (filesize($pngquantImagePath) <= filesize($imgminImagePath)) {
            unlink($imgminImagePath);
            rename($pngquantImagePath, $imgminImagePath);
          } else {
            unlink($pngquantImagePath);
          }

          $webpOptions .= ' -alpha_q 80 -alpha_cleanup -alpha_method 1 -alpha_filter best';
        }

        // Convert the optimized image to WebP.
        // https://developers.google.com/speed/webp/docs/cwebp
        shell_exec($webpCommand . ' ' . $imgminImagePath . ' ' . $webpOptions . ' -o ' . $hostPaths['webp'] . DIRECTORY_SEPARATOR . $imageName . '.webp 2>&1 >/dev/null');

        $progress->advance();
      }

      $progress->finish();
      unlink_recursive($paths['tmp']);
    }

    touch($this->optimizedFilePath);
    $this->out->writeln(PHP_EOL . '<info>Finished optimizing images!</info>' . PHP_EOL);

    return $this;
  }

  /**
   * Create statistics for each website about potential saving.
   *
   * @return \SEResearchSeminar1\Task3Command
   */
  private function statistics() {
    if (!file_exists($this->optimizedFilePath)) {
      $this->optimize();
    }

    $this->out->writeln('<info>Starting to create statistics about potential saving for each top website.</info>' . PHP_EOL);

    /* @var $websites array */
    $websites = glob($this->hostsImagesPath . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);

    /* @var $stats array */
    $stats = [];

    /* @var $progress \Symfony\Component\Console\Helper\ProgressHelper */
    $progress = $this->getHelper('progress');
    $progress->setFormat('  <comment>%current%/%max%</comment> stats generated <comment>[%bar%]</comment> %percent%%');
    $progress->start($this->out, count($websites));

    foreach ($websites as $folder) {
      /* @var $hostname string */
      $hostname = basename($folder);

      foreach ([
        'images' => $folder,
        'imgmin' => $this->hostsImagesPath . '-imgmin' . DIRECTORY_SEPARATOR . $hostname,
        'webp' => $this->hostsImagesPath . '-webp' . DIRECTORY_SEPARATOR . $hostname
      ] as $type => $path) {
        /* @var $images array */
        $images = glob($path . DIRECTORY_SEPARATOR . '*');

        $stats[$hostname][$type] = [
          'imageCount' => count($images),
          'sizeBytes' => 0
        ];

        foreach ($images as $image) {
          /* @var $size int */
          $size = filesize($image);

          $stats[$hostname][$type]['files'][] = [
            'name' => basename($image),
            'size' => $size,
            'format' => pathinfo($image)['extension'],
          ];

          $stats[$hostname][$type]['sizeBytes'] += $size;
        }
      }

      $progress->advance();
    }

    $progress->finish();
    file_put_contents($this->statsFilePath, json_encode($stats));
    $this->out->writeln(PHP_EOL . '<info>Finished generating stats.</info>' . PHP_EOL);

    return $this;
  }

  private function printStats() {
    if (!file_exists($this->statsFilePath)) {
      $this->statistics();
    }

    $this->out->writeln(
      sprintf(
        '<info>%54.28s</info>' . PHP_EOL . PHP_EOL . '<info>%20.14s:</info> %10s %10s %10s' . PHP_EOL,
        'Statistics for each website!',
        'Website',
        'images',
        'imgmin',
        'webp'
      )
    );

    foreach (json_decode(file_get_contents($this->statsFilePath)) as $website => $stats) {
      $this->out->writeln(
        sprintf(
          '<info>%20.' . strlen($website) . 's:</info> %10d %10d %10d',
          $website,
          $stats->images->sizeBytes,
          $stats->imgmin->sizeBytes,
          $stats->webp->sizeBytes
        )
      );
    }

    $this->out->writeln(sprintf(
      PHP_EOL . 'Go to <bg=blue;options=underscore>http://alpha.movlib.org/research/stats.html</bg=blue;options=underscore> to have a look at these stats with some eye candy!' . PHP_EOL
    ));

    return $this;
  }

  private function clean($clean) {
    // Please note that there are no break statements for a reason. All options shall fall through and execute the
    // code that follows as well.
    switch ($clean) {
      case self::CLEAN_ALL:
        $this->out->writeln('<info>Cleaning complete data!</info>');
        if (file_exists(DATADIR)) {
          unlink_recursive(DATADIR);
          rmdir(DATADIR);
        }
        break;

      case self::CLEAN_TOPLIST:
        $this->out->writeln('<info>Cleaning Alexa Toplist!</info>');
        if (file_exists($this->alexaToplistFilePath)) {
          unlink($this->alexaToplistFilePath);
        }

      case self::CLEAN_ALL_IMAGES:
        $this->out->writeln('<info>Cleaning all source images!</info>');
        if (file_exists($this->hostsImagesPath)) {
          unlink_recursive($this->hostsImagesPath);
          rmdir($this->hostsImagesPath);
        }

      case self::CLEAN_OPTIMIZED_IMAGES:
        $this->out->writeln('<info>Cleaning all optimized images!</info>');
        /* @var $imgminPath string */
        $imgminPath = $this->hostsImagesPath . '-imgmin';
        if (file_exists($imgminPath)) {
          unlink_recursive($imgminPath);
          rmdir($imgminPath);
        }
        /* @var $webpPath string */
        $webpPath = $this->hostsImagesPath . '-webp';
        if (file_exists($webpPath)) {
          unlink_recursive($webpPath);
          rmdir($webpPath);
        }
        if (file_exists($this->optimizedFilePath)) {
          unlink($this->optimizedFilePath);
        }

      case self::CLEAN_STATS:
        $this->out->writeln('<info>Cleaning statistics!</info>');
        if (file_exists($this->statsFilePath)) {
          unlink($this->statsFilePath);
        }
    }

    $this->out->writeln('');

    return $this;
  }

  /**
   * Called if the command is executed. This is the place where we have to decide what should be done upon execution.
   *
   * @param InputInterface $input
   * @param OutputInterface $output
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->in = $input;
    $this->out = $output;

    $this->out->writeln('');

    /* @var $clean string */
    if (($clean = $input->getOption('clean'))) {
      $this->clean((int) $clean);
    }

    if (($hostCount = $input->getOption('alexa'))) {
      // Abort and tell the user if the value is out of range or invalid (e.g. not a number).
      if (!is_numeric($hostCount) || $hostCount < self::HOSTS_MIN_COUNT || $hostCount > self::HOSTS_MAX_COUNT) {
        $this->exitOnError('The given value ' . $hostCount . ' is out of range. Valid values range from ' . self::HOSTS_MIN_COUNT . ' to ' . self::HOSTS_MAX_COUNT);
      }

      $this->hostCount = (int) $hostCount;
    }

    $this->download()->extract()->alexa()->fetch()->optimize()->statistics()->printStats();
  }


}

/**
 * We only have a single command that is usable, therfor we want to execute this command every time the script is
 * called.
 *
 * @package SEResearchSeminar1
 */
class Task3Application extends Application {
  /**
   * Returns the name of the command our application shall always execute.
   *
   * @param InputInterface $input
   * @return string
   */
  protected function getCommandName(InputInterface $input) {
    return 'se-research-seminar-1-task-3';
  }

  /**
   * The default commands that should always be available.
   *
   * @return array
   */
  protected function getDefaultCommands() {
    $defaultCommands = parent::getDefaultCommands();
    $defaultCommands[] = new Task3Command();
    return $defaultCommands;
  }

  /**
   * Overriden so that the application doesn't expect the command name to be the first argument.
   *
   * @return InputDefinition
   */
  public function getDefinition() {
    $inputDefinition = parent::getDefinition();
    $inputDefinition->setArguments();
    return $inputDefinition;
  }
}

// Create new instance of our application (set name and version) and directly run it.
(new Task3Application('SE Research Seminar 1: Task 3', '0.0.1'))->run();