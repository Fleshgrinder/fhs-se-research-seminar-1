<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Matrix.php';

use \Imagick;

/**
 * Compute Structural Similarity Index (SSIM) image quality metrics. Based on original MathLab code and the original
 * paper:
 *
 *   Z. Wang, A. C. Bovik, H. R. Sheikh, and E. P. Simoncelli, "Image quality assessment: From error measurement to
 *   structural similarity" IEEE Transactios on Image Processing, vol. 13, no. 1, Jan. 2004.
 *
 * @link https://ece.uwaterloo.ca/~z70wang/research/ssim/ssim_index.m
 * @link https://ece.uwaterloo.ca/~z70wang/publications/ssim.pdf
 * @link http://isit.u-clermont1.fr/~anvacava/code-ssim-source.html
 * @link http://isit.u-clermont1.fr/~anvacava/code-main_ssim_2images-source.html
 * @author Richard Fussenegger <richard@fussenegger.info>
 */
class SSIM {

//  /** Full two-dimensional correlation. */
//  const FILTER2_SHAPE_FULL = 0;
//
//  /** Central part of the correlation. */
//  const FILTER2_SHAPE_SAME = 1;
//
//  /** Only those parts of the correlation that are computed without zero-padded edges. */
//  const FILTER2_SHAPE_VALID = 2;

  /**
   * Constants in the SSIM index formula (see the above paper for reference). Default values are
   * <code>[ 0.01, 0.03 ]</code>.
   *
   * @var array
   */
  private $K;

  /**
   * Size and sigma for gaussian blur filter. Default values are <code>[ 'width' => 11, 'σ' => 1.5 ]</code>.
   *
   * @var array
   */
  private $window;

  /**
   * The dynamic range of the pixel values. Default value is <code>255</code> (for 8-bit grayscale images).
   *
   * @var int
   */
  private $dynamicRange;

  /**
   * The first image that we should compare (this image should be perfect and of full quality).
   *
   * @var array
   */
  private $img1;

  /**
   * The second image that we should compare (this image should be the compressed one).
   *
   * @var array
   */
  private $img2;

  /**
   * @see \MovLib\Utility\SSIM::getMeanSSIM
   * @var number
   */
  private $meanSSIM;

  /**
   * @see \MobLib\Utility\SSIM::getSSIMmap
   * @var array
   */
  private $SSIMmap;

  /**
   * Configure SSIM calculation.
   *
   * @see \MovLib\Utility\SSIM::getMeanSSIM
   * @see \MobLib\Utility\SSIM::getSSIMmap
   * @param string $img1
   *   Absolute path to the first image.
   * @param string $img2
   *   Absolute path to the second image.
   * @param array $K
   *   The SSIM formula constants.
   * @param array $window
   *   The gaussian blur size and sigma.
   * @param int $dynamicRange
   *   The dynamic range of a single pixel.
   * @throws \Exception
   */
  public function __construct($img1, $img2, array $K = [ 0.01, 0.03 ], $window = [ 'width' => 11, 'σ' => 1.5 ], $dynamicRange = 255) {
    foreach ([ 1 => $img1, 2 => $img2 ] as $delta => $img) {
      if (!file_exists($img) || !is_readable($img)) {
        throw new \Exception("img$delta can not be read by PHP process");
      }

      if (!($getimagesize = getimagesize($img))) {
        throw new \Exception('getimagesize() failed');
      }

      $this->{"img$delta"} = [
        'src' => $img,
        'resource' => imagecreatefromstring(file_get_contents($img)),
        'matrix' => $this->getImageMatrix($img),
        'width' => $getimagesize[0],
        'height' => $getimagesize[1],
        'type' => $getimagesize[2],
        'channels' => $getimagesize['channels'],
        'bits' => $getimagesize['bits'],
      ];
    }

    if ($this->img1['width'] !== $this->img2['width'] || $this->img1['height'] !== $this->img2['height']) {
      throw new \Exception('image sizes are not equal');
    }

    if ($this->img1['width'] < 11 || $this->img1['height'] < 11) {
      throw new \Exception('minimum width and height for images is 11 pixels');
    }

    $this->setK($K);
    $this->setWindow($window);
    $this->setDynamicRange($dynamicRange);

    /* @var $C1 number */
    $c1 = pow($this->K[0] * $this->dynamicRange, 2);
    /* @var $C2 number */
    $c2 = pow($this->K[1] * $this->dynamicRange, 2);

    // PHP has no convolution function that gives us a matrix back, dive into:
    // {@link http://stackoverflow.com/questions/2219386/how-does-a-convolution-matrix-work}

    /* @var $µ1 */
    $µx = 0;
    /* @var $µ2 */
    $µy = 0;

    /* @var $σ1 */
    $σx = 0;
    /* @var $σ2 */
    $σy = 0;
    /* @var $σxy */
    $σxy = 0;

    //...
  }

  /**
   * Set the SSIM formula constants.
   *
   * @param array $K
   * @throws \Exception
   */
  private function setK(array $K) {
    if (count($K) !== 2) {
      throw new \Exception('K can only have two values');
    }

    if ($K[0] < 0 || $K[1] < 0) {
      throw new \Exception('SSIM formula constants must not be negative');
    }

    $this->K = $K;
  }

  /**
   * Set the gaussian blur window.
   *
   * @link http://www.mathworks.de/de/help/images/ref/fspecial.html
   * @param array $window
   * @throws \Exception
   */
  private function setWindow(array $window) {
    if (count($window) !== 2) {
      throw new \Exception('window can only have two values');
    }

    if (!is_int($window['width'])) {
      throw new \Exception('window dimensions must be of type integer');
    }

    if (!is_numeric($window['σ'])) {
      throw new \Exception('σ must be of type number');
    }

    if ($window['width'] * $window['width'] < 4 || $window['width'] > $this->img1['width'] || $window['width'] > $this->img1['height']) {
      throw new \Exception('window dimensions must fit image dimensions');
    }

    // Vector division (original author called function vdiv).
    // @link http://www.phpmath.com/home?op=cat&cid=14
//    $divisor = $this->sum($this->sum($window));
//    $size = count($window);
//    $divArray = array_fill(0, $size, $divisor);
//    $this->window = array_map(function ($numerator, $divisor) {
//      return ($numerator / $divisor);
//    }, $window, $divArray);

    // @link http://isit.u-clermont1.fr/~anvacava/code-ssim-source.html
    $window['kernel'] = [];
    for ($i = 0; $i < $window['width']; ++$i) {
      for ($j = 0; $j < $window['width']; ++$j) {
        $window['kernel'][$i][$j] = (1 / 2 * pi() * pow($window['σ'], 2)) * exp(-(pow($i - 5, 2) + pow($j - 5, 2))) / (2 * pow($window['σ'], 2));
      }
    }
    $window['kernel'] = new Matrix($window['kernel']);

    $this->window = $window;
  }

  /**
   * Set the images dynamic range.
   *
   * @param int $dynamicRange
   * @throws \Exception
   */
  private function setDynamicRange($dynamicRange) {
    if (!is_int($dynamicRange)) {
      throw new \Exception('L can only be of type integer');
    }

    // We could also calculate the dynamic range with the following formula:
    //$this->dynamicRange = pow(2, ($this->img1['bits'] - 1));
    $this->dynamicRange = $dynamicRange;
  }

  /**
   * Get the mean SSIM index value between the given two images. If one of the images being compared is regarded as
   * perfect quality, then mean SSIM can be considered as the quality measure of the other image.
   *
   * @return number
   *   <code>1</code> if both images are of the exact same quality, otherwise mean SSIM index between <code>-1</code>
   *   and <code>1</code>.
   */
  public function getMeanSSIM() {
    return $this->meanSSIM;
  }

  /**
   * The SSIM index map of the test image. The map has a smaller size than the input images. The actual size is:
   *   <pre>size(img1) - size(window) + 1</pre>
   *
   * @return array
   */
  public function getSSIMmap() {
    return $this->SSIMmap;
  }

  /**
   * PHP implementation of Matlab sum function.
   *
   * @link http://www.phpmath.com/home?op=cat&cid=14
   * @param array|number $a
   *   One to two dimensional array of numeric values.
   * @param int $dimension
   *   Dimension to sum (1 = column sums, 2 = row sums).
   * @return array|string
   *   A row (<var>$dimension</var> = 1) or column (<var>$dimension</var> = 2) vector of sums.
   */
//  private function sum($a, $dimension = 1) {
//    // Scalar value
//    if (is_numeric($a)) {
//      return $a;
//    }
//    // Check if we have a vector or matrix.
//    elseif (is_array($a) && count($a) > 0) {
//      // Vector value
//      if (!is_array($a[0])) {
//        return array_sum($a);
//      }
//      // Matrix value
//      $sums = [];
//      $nrows = count($a);
//      $ncols = count($a[0]);
//      if ($dimension === 1) {
//        for ($i = 0; $i < $ncols; ++$i) {
//          $sums[$i] = 0.0;
//          for ($j = 0; $j < $nrows; ++$j) {
//            $sums[$i] += $a[$j][$i];
//          }
//        }
//      }
//      else {
//        for ($i = 0; $i < $nrows; ++$i) {
//          $sums[$i][0] += array_sum($a[$i]);
//        }
//      }
//      return $sums;
//    }
//    throw new \BadMethodCallException();
//  }

  /**
   * Get two-dimensional matrix from image file.
   *
   * @param string $image
   *   Absolute or relative file path on local filesystem.
   * @return \MovLib\Utility\Matrix
   */
  private function getImageMatrix($image) {
    /* @var $imagick \Imagick */
    $imagick = new Imagick(realpath($image));
//    $imagick->setColorspace(Imagick::COLORSPACE_GRAY);
    /* @var $rgbPixel array */
    $rgbPixel = array_chunk($imagick->exportImagePixels(0, 0, $imagick->getImageWidth(), $imagick->getImageHeight(), 'RGB', Imagick::PIXEL_DOUBLE), 3);
    /* @var $pixel int */
    $pixel = 0;
    /* @var $twoDimensionalMatrix array */
    $matrixData = [];
    for ($i = 0; $i < $imagick->getImageHeight(); ++$i) {
      for ($j = 0; $j < $imagick->getImageWidth(); ++$j) {
        // @link http://en.wikipedia.org/wiki/Luminance_%28relative%29
        // @link http://stackoverflow.com/a/13558570/1251219
        $matrixData[$i][$j] = (0.2126 * $rgbPixel[$pixel][0]) + (0.7152 * $rgbPixel[$pixel][1]) + (0.0722 * $rgbPixel[$pixel][2]);
        $pixel++;
      }
    }
    return new Matrix($matrixData);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    ob_start();
    var_dump($this);
    return ob_get_clean();
  }

}
