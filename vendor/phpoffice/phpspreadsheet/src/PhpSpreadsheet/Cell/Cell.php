<?php

namespace PhpOffice\PhpSpreadsheet\Cell;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class Cell
{
    /**
     * The value of this cell.
     *
     * @var mixed
     */
    private $value;

    /**
     * The parent worksheet.
     *
     * @var Worksheet
     */
    private $parent;

    /**
     * Create a new Cell.
     *
     * @param mixed $value
     * @param Worksheet $parent
     */
    public function __construct($value = null, Worksheet $parent = null)
    {
        $this->value = $value;
        $this->parent = $parent;
    }

    /**
     * Get the value of this cell.
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Set the value of this cell.
     *
     * @param mixed $value
     *
     * @return $this
     */
    public function setValue($value)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Get the parent worksheet.
     *
     * @return null|Worksheet
     */
    public function getParent()
    {
        return $this->parent;
    }
}
