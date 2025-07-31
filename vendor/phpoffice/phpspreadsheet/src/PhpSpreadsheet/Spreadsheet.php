<?php

namespace PhpOffice\PhpSpreadsheet;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class Spreadsheet
{
    /**
     * The collection of worksheets in this spreadsheet.
     *
     * @var Worksheet[]
     */
    private $worksheets = [];

    /**
     * The active worksheet in this spreadsheet.
     *
     * @var int
     */
    private $activeSheetIndex = 0;

    /**
     * Create a new Spreadsheet.
     */
    public function __construct()
    {
        // Add a single worksheet
        $this->worksheets[] = new Worksheet($this);
    }

    /**
     * Get the active worksheet.
     *
     * @return Worksheet
     */
    public function getActiveSheet()
    {
        return $this->worksheets[$this->activeSheetIndex];
    }

    /**
     * Get a worksheet by its index.
     *
     * @param int $index
     *
     * @return Worksheet
     */
    public function getSheet($index)
    {
        if (!isset($this->worksheets[$index])) {
            throw new Exception('Sheet index is out of bounds.');
        }

        return $this->worksheets[$index];
    }

    /**
     * Get all worksheets.
     *
     * @return Worksheet[]
     */
    public function getAllSheets()
    {
        return $this->worksheets;
    }

    /**
     * Get the number of worksheets.
     *
     * @return int
     */
    public function getSheetCount()
    {
        return count($this->worksheets);
    }

    /**
     * Get worksheet by name.
     *
     * @param string $name
     *
     * @return null|Worksheet
     */
    public function getSheetByName($name)
    {
        foreach ($this->worksheets as $sheet) {
            if ($sheet->getTitle() === $name) {
                return $sheet;
            }
        }

        return null;
    }
}
