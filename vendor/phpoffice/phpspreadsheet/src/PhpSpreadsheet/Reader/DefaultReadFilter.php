<?php

namespace PhpOffice\PhpSpreadsheet\Reader;

use PhpOffice\PhpSpreadsheet\Worksheet\ReadFilter;

class DefaultReadFilter implements ReadFilter
{
    /**
     * Should this cell be read?
     *
     * @param string $column Address of the cell, eg 'A1'
     * @param int $row Row number
     * @param string $worksheetName Optional worksheet name
     *
     * @return bool
     */
    public function readCell($column, $row, $worksheetName = '')
    {
        return true;
    }
}
