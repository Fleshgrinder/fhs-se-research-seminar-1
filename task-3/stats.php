<?php

function create_accordion_group($title, $content) {
  static $counter = 0;

  return
    '<div class="accordion-group">' .
      '<div class="accordion-heading">' .
        '<a class="accordion-toggle" data-toggle="collapse" data-parent="#accordion" href="#accordion-body-' . $counter . '">' . $title . '</a>' .
      '</div>' .
      '<div id="accordion-body-' . $counter++ . '" class="accordion-body collapse">' . $content . '</div>' .
    '</div>'
  ;
}

define('CWD', getcwd());

require CWD . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'HighchartsPHP' . DIRECTORY_SEPARATOR . 'Highchart.php';

/* @var $statsFilePath string */
$statsFilePath = CWD . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'stats.json';

/* @var $stats object */
$stats = NULL;

/* @var $scripts string */
$scripts = '';

/* @var $container string */
$container = '<div class="alert alert-error"><strong>Error!</strong> No statistics are available. Please run the script first!</div>';

if (file_exists($statsFilePath)) {
  $stats = json_decode(file_get_contents($statsFilePath), TRUE);

  /* @var $tmp array */
  $tmp = [
    'images' => [],
    'images-total' => 0,
    'imgmin' => [],
    'imgmin-total' => 0,
    'webp' => [],
    'webp-total' => 0,
    'accordion' => '',
  ];

  /* @var $chart \Highchart */
  $chart = new \Highchart();

  $chart->chart->renderTo = 'container';
  $chart->chart->type = 'bar';
  $chart->title->text = 'Size Savings for top websites';
  $chart->subtitle->text = 'Source: self-generated';
  $chart->xAxis->categories = [];
  $chart->xAxis->title->text = NULL;
  $chart->yAxis->title->text = NULL;
  $chart->tooltip->formatter = new HighchartJsExpr(
    'function () { return "" + this.series.name + ": " + this.y + " KB"; }'
  );

  foreach ($stats as $website => $stat) {
    $chart->xAxis->categories[] = $website;

    foreach ([ 'images', 'imgmin', 'webp' ] as $type) {
      $tmp[$type][] = round($stat[$type]['sizeBytes'] / 1024, 2);
      $tmp[$type . '-total'] += $stat[$type]['sizeBytes'];

      if (isset($stat[$type]['files'])) {
        foreach ($stat[$type]['files'] as $delta => $file) {
          /* @var $folder string */
          $folder = 'images' . ($type !== 'images' ? '-' . $type : '');

          /* @var $attr string */
          if (!(@$attr = getimagesize(CWD . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $website . DIRECTORY_SEPARATOR . $file['name'])[3])) {
            $attr = '';
          }

          $stat[$type]['files'][$delta] =
            '<figure>' .
              '<img src="data/' . $folder . '/' . $website . '/' . $file['name'] . '" alt="" ' . $attr . '>' .
              '<figcaption>' .
                'Size: <strong>' . $file['size'] . '&nbsp;bytes</strong><br>Format: <strong>' . strtoupper($file['format']) . '</strong>' .
              '</figcaption>' .
            '</figure>'
          ;
        }
      }
    }

    if (isset($stat['images']['files'])) {
      $tmp['tmp'] = '';

      foreach ($stat['images']['files'] as $delta => $image) {
        $tmp['tmp'] .= '<tr>';

        foreach ([ 'images', 'imgmin', 'webp' ] as $type) {
          if (isset($stat[$type]['files']) && isset($stat[$type]['files'][$delta])) {
            $tmp['tmp'] .= '<td>' . $stat[$type]['files'][$delta] . '</td>';
          }
        }

        $tmp['tmp'] .= '</tr>';
      }

      $tmp['accordion'] .= create_accordion_group(
        $website,
        '<table class="table table-bordered table-striped">' .
          '<thead class="header">' .
            '<tr>' .
              '<th>Source</th>' .
              '<th>Optimized</th>' .
              '<th>WebP</th>' .
            '</tr>' .
          '</thead>' .
          '<tbody>' . $tmp['tmp'] . '</tbody>' .
        '</table>'
      );

      unset($tmp['tmp']);
    }
  }

  $chart->series[] = [
    'name' => 'Original Images',
    'data' => $tmp['images']
  ];
  $chart->series[] = [
    'name' => 'Optimized Images',
    'data' => $tmp['imgmin']
  ];
  $chart->series[] = [
    'name' => 'WebP Images',
    'data' => $tmp['webp']
  ];

  foreach ($chart->getScripts() as $script) {
    $scripts .= '<script src="' . $script . '"></script>';
  }

  /* @var $a float */
  $a = 100 / $tmp['images-total'];

  /* @var $percentSavingsImgmin float */
  $percentSavingsImgmin = (100 - round($a * $tmp['imgmin-total'], 2));

  /* @var $percentSavingsWebp float */
  $percentSavingsWebp = (100 - round($a * $tmp['webp-total'], 2));

  /* @var $kbSavingsImgmin float */
  $kbSavingsImgmin = number_format(($tmp['images-total'] - $tmp['imgmin-total']) / 1024, 2);

  /* @var $kbSavingsWebp float */
  $kbSavingsWebp = number_format(($tmp['images-total'] - $tmp['webp-total']) / 1024, 2);

  /* @var $containerHeight int */
  $containerHeight = count($stats) * 60;

  if (400 > $containerHeight) {
    $containerHeight = 400;
  }

  $container =
    '<div class="alert alert-info">' .
      '<h4>Summary</h4>' .
      'Optimization saved ' . $percentSavingsImgmin . '&nbsp;% (' . $kbSavingsImgmin . '&nbsp;KB) in total.<br>' .
      'WebP conversion saved ' . $percentSavingsWebp . '&nbsp;% (' . $kbSavingsWebp . '&nbsp;KB) in total.' .
    '</div>' .
    '<div id="container" style="height:' . $containerHeight . 'px"></div>' .
    '<script>' . $chart->render('chart1') . '</script>' .
    '<h2>Side by side comparison</h2>' .
    '<div class="alert alert-info">Please note that you need Google Chrome or Opera to see the WebP images.</div>' .
    '<div id="accordion" class="accordion">' .
      $tmp['accordion'] .
      create_accordion_group('Statistics source data', '<pre class="accordion-inner language-javascript">' . htmlspecialchars(print_r($stats, TRUE)) . '</pre>') .
    '</div>' .
    '<h2>What I did</h2>' .
    '<ul>' .
      '<li>Animated GIFs were not optimized at all! Animated PNG and WebP images have nearly no support in any browser. It is of course possible to optimize them.</li>' .
      '<li>I used <a href="https://github.com/rflynn/imgmin">imgmin</a> to optimize the images.</li>' .
      '<li>For PNG images I also used <a href="https://github.com/pornel/improved-pngquant">improved pngquant</a> and compared the result with the imgmin result, the smaller file was then used.</li>' .
      '<li>GIF files were converted to PNG (see <a href="http://www.w3.org/QA/Tips/png-gif">PNG versus GIF</a>).</li>' .
      '<li>The optimized version of the image was used for conversion to WebP.</li>' .
      '<li>JPG images were converted to WebP with the following command: <code>cwebp [input] -quiet -af -mt -m 6 -f 40 -q 80 -o [output]</code></li>' .
      '<li>PNG images were converted to WebP with the following command: <code>cwebp [input] -quiet -af -mt -m 6 -f 40 -q 80 -alpha_q 80 -alpha_cleanup -alpha_method 1 -alpha_filter best -o [output]</code></li>' .
    '</ul>' .
    '<h2>Possible extensions</h2>' .
    '<p>Calculate the <a href="http://en.wikipedia.org/wiki/Structural_similarity">Structural Similarity</a> (SSIM) for all images.</p>' .
    '<p>This would require a program which is able to calculate the SSIM for JPG, PNG and GIF images (the WebP program has this functionality built in). I couldn’t find a program that is capable of this and writing my own wasn’t possible in this short time.</p>' .
    '<h2>Conclusion</h2>' .
    '<p>Image optimization has a huge impact on the size of the images. Smaller file size leads to faster downloads and faster page rendering for our users. As you can see, I was able to optimize the images of all websites, and we are talking about the top websites in this world who have the best developers in this world. Another interesting thing is, that converting alpha images to WebP doesn’t produce smaller files. Although Google is able to achieve very good results in their examples, maybe my command isn’t the best. WebP works extremely great for photos though. The lack of browser support shouldn’t be that much of an issue. One can simply check for support on the server or client side. Only problem right now is the fact that one has to save both versions of an image on disc. This increases the storage demand and for big websites with lots of images a considerable cost problem.</p>' .
    '<h2></h2>' .
    '<p>Source code is available on <a href="https://github.com/Fleshgrinder/se-research-seminar-1/tree/master/task-3">GitHub</a>.</p>' .
    '<p>Please feel free to write me over at GitHub if you have any insights on improving my work.</p>'
  ;
}

echo $container;