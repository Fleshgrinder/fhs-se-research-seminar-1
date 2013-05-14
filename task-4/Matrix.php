<?php

use \RuntimeException;

/**
 * Description of Matrix
 *
 * @link http://www.phpkode.com/scripts/item/matrix-new/
 * @author Richard Fussenegger <richard@fussenegger.info>
 */
class Matrix {

  /**
   * Array containing the numbers of the matrix.
   *
   * <var>$numbers[$i][$j]</var> is a two-dimensional array where <var>$i</var> represents the depth and <var>$j</var>
   * the width. Hence, <var>$numbers[$i]</var> returns one row of the matrix. As a convention, zeros are not stored in
   * the array. Thus, the dimension of the array is not necessarely the dimension of the matrix.
   *
   * @var array
   */
  private $numbers = [];

  /**
   * The number of columns in this matrix.
   *
   * @var int
   */
  private $columnCount = 0;

  /**
   * The number of rows in this matrix.
   *
   * @var int
   */
  private $rowCount = 0;

  public function __construct(array $numbers) {
    foreach ($numbers as $i => $rows) {
      foreach ($rows as $j => $number) {
        if ($number !== 0) {
          $this->numbers[$i][$j] = $number;
        }
        if ($j >= $this->columnCount) {
          $this->columnCount = $j;
        }
      }
      if ($i >= $this->rowCount) {
        $this->rowCount = $i;
      }
    }
    $this->columnCount++;
    $this->rowCount++;
  }

  public function getNumbers() {
    return $this->numbers;
  }

  public function getColumnCount() {
    return $this->columnCount;
  }

  public function getRowCount() {
    return $this->rowCount;
  }

  public function prime() {
    $data = [];
    foreach ($this->numbers as $i => $row) {
      foreach ($row as $j => $number) {
        $data[$j][$i] = $number;
      }
    }
    return new Matrix($data, $this->columnCount, $this->rowCount);
  }

  public function times(Matrix $matrix) {
    /* @var $matrixT \MovLib\Utility\Matrix */
    $matrixT = $matrix->prime();

    if ($this->columnCount == $matrix->getColumnCount()) {
      /* @var $data array */
      $data = [];
      foreach ($this->numbers as $i => $row) {
        foreach ($matrixT->getNumbers() as $j => $column) {
          $data[$i][$j] = 0;
          foreach ($row as $k => $number) {
            if (!empty($column[$k])) {
              $data[$i][$j] += $number * $column[$k];
            }
          }
        }
      }
      return new Matrix($data);
    }

    throw new RuntimeException();
  }

  public function squareTimes($scalar) {
    $data = $this->numbers;
    foreach ($this->numbers as $i => $column) {
      foreach ($column as $j => $number) {
        $data[$i][$j] *= $scalar;
      }
    }
    return new Matrix($data);
  }

}
