<?php

namespace PhpOffice\PhpSpreadsheet\Reader;

use PhpOffice\PhpSpreadsheet\Worksheet\ReadFilter;

interface IReader
{
    const READ_DATA_ONLY = 1;
    const IGNORE_EMPTY_CELLS = 2;
    const READ_AHEAD_ENABLED = 4;

    /**
     * Can the current IReader read the file?
     *
     * @param string $filename
     *
     * @return bool
     */
    public function canRead($filename);

    /**
     * Reads names of the worksheets in a file, without loading the entire file to memory.
     *
     * @param string $filename
     *
     * @return array
     */
    public function listWorksheetNames($filename);

    /**
     * Return worksheet info (Name, Last Column Letter, Last Column Index, Total Rows, Total Columns).
     *
     * @param string $filename
     *
     * @return array
     */
    public function listWorksheetInfo($filename);

    /**
     * Loads Spreadsheet from file.
     *
     * @param string $filename
     *
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    public function load($filename);

    /**
     * Set read data only.
     *
     * @param bool $readDataOnly
     *
     * @return IReader
     */
    public function setReadDataOnly($readDataOnly);

    /**
     * Get read data only.
     *
     * @return bool
     */
    public function getReadDataOnly();

    /**
     * Set read empty cells.
     *
     * @param bool $readEmptyCells
     *
     * @return IReader
     */
    public function setReadEmptyCells($readEmptyCells);

    /**
     * Get read empty cells.
     *
     * @return bool
     */
    public function getReadEmptyCells();

    /**
     * Set read ahead.
     *
     * @param bool $readAhead
     *
     * @return IReader
     */
    public function setReadAhead($readAhead);

    /**
     * Get read ahead.
     *
     * @return bool
     */
    public function getReadAhead();

    /**
     * Set a read filter.
     *
     * @param ReadFilter $readFilter
     *
     * @return IReader
     */
    public function setReadFilter(ReadFilter $readFilter);

    /**
     * Get read filter.
     *
     * @return ReadFilter
     */
    public function getReadFilter();

    /**
     * Set which sheets to load.
     *
     * @param array|string $sheetNames
     *
     * @return IReader
     */
    public function setLoadSheetsOnly($sheetNames);

    /**
     * Get which sheets to load.
     *
     * @return array
     */
    public function getLoadSheetsOnly();

    /**
     * Set load all sheets.
     *
     * @return IReader
     */
    public function setLoadAllSheets();

    /**
     * Set Contiguous.
     *
     * @param bool $contiguous
     *
     * @return IReader
     */
    public function setContiguous($contiguous);

    /**
     * Get Contiguous.
     *
     * @return bool
     */
    public function getContiguous();
}
