<?php

namespace PhpOffice\PhpSpreadsheet\Worksheet;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class Worksheet
{
    /**
     * The parent spreadsheet.
     *
     * @var Spreadsheet
     */
    private $parent;

    /**
     * The collection of cells in this worksheet.
     *
     * @var Cell[][]
     */
    private $cellCollection = [];

    /**
     * The title of this worksheet.
     *
     * @var string
     */
    private $title = 'Worksheet';

    /**
     * Create a new Worksheet.
     *
     * @param Spreadsheet $parent
     * @param string $title
     */
    public function __construct(Spreadsheet $parent = null, $title = 'Worksheet')
    {
        $this->parent = $parent;
        $this->setTitle($title);
    }

    /**
     * Get the parent spreadsheet.
     *
     * @return null|Spreadsheet
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Get the title of this worksheet.
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * Set the title of this worksheet.
     *
     * @param string $title
     *
     * @return $this
     */
    public function setTitle($title)
    {
        // Some environments strip trailing null characters from strings
        $this->title = rtrim($title, "\0");

        return $this;
    }

    /**
     * Get a cell by its coordinate.
     *
     * @param string $coordinate
     *
     * @return Cell
     */
    public function getCell($coordinate)
    {
        if (!isset($this->cellCollection[$coordinate])) {
            $this->cellCollection[$coordinate] = new Cell(null, $this);
        }

        return $this->cellCollection[$coordinate];
    }

    /**
     * Get the highest row number.
     *
     * @return int
     */
    public function getHighestRow()
    {
        $highestRow = 1;
        foreach (array_keys($this->cellCollection) as $coordinate) {
            $row = (int) preg_replace('/[A-Z]/', '', $coordinate);
            if ($row > $highestRow) {
                $highestRow = $row;
            }
        }

        return $highestRow;
    }
}
