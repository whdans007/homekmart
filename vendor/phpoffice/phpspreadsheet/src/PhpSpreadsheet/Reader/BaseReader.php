<?php

namespace PhpOffice\PhpSpreadsheet\Reader;

use PhpOffice\PhpSpreadsheet\Worksheet\ReadFilter;

abstract class BaseReader implements IReader
{
    /**
     * Read data only?
     *
     * @var bool
     */
    protected $readDataOnly = false;

    /**
     * Read empty cells?
     *
     * @var bool
     */
    protected $readEmptyCells = true;

    /**
     * Read ahead?
     *
     * @var bool
     */
    protected $readAhead = false;

    /**
     * Read filter.
     *
     * @var ReadFilter
     */
    protected $readFilter;

    /**
     * Sheet names to load.
     *
     * @var array
     */
    protected $loadSheetsOnly;

    /**
     * Contiguous cells.
     *
     * @var bool
     */
    protected $contiguous = false;

    /**
     * Create a new BaseReader.
     */
    public function __construct()
    {
        $this->readFilter = new \PhpOffice\PhpSpreadsheet\Reader\DefaultReadFilter();
    }

    /**
     * Set read data only.
     *
     * @param bool $readDataOnly
     *
     * @return IReader
     */
    public function setReadDataOnly($readDataOnly)
    {
        $this->readDataOnly = $readDataOnly;

        return $this;
    }

    /**
     * Get read data only.
     *
     * @return bool
     */
    public function getReadDataOnly()
    {
        return $this->readDataOnly;
    }

    /**
     * Set read empty cells.
     *
     * @param bool $readEmptyCells
     *
     * @return IReader
     */
    public function setReadEmptyCells($readEmptyCells)
    {
        $this->readEmptyCells = $readEmptyCells;

        return $this;
    }

    /**
     * Get read empty cells.
     *
     * @return bool
     */
    public function getReadEmptyCells()
    {
        return $this->readEmptyCells;
    }

    /**
     * Set read ahead.
     *
     * @param bool $readAhead
     *
     * @return IReader
     */
    public function setReadAhead($readAhead)
    {
        $this->readAhead = $readAhead;

        return $this;
    }

    /**
     * Get read ahead.
     *
     * @return bool
     */
    public function getReadAhead()
    {
        return $this->readAhead;
    }

    /**
     * Set a read filter.
     *
     * @param ReadFilter $readFilter
     *
     * @return IReader
     */
    public function setReadFilter(ReadFilter $readFilter)
    {
        $this->readFilter = $readFilter;

        return $this;
    }

    /**
     * Get read filter.
     *
     * @return ReadFilter
     */
    public function getReadFilter()
    {
        return $this->readFilter;
    }

    /**
     * Set which sheets to load.
     *
     * @param array|string $sheetNames
     *
     * @return IReader
     */
    public function setLoadSheetsOnly($sheetNames)
    {
        $this->loadSheetsOnly = is_array($sheetNames) ? $sheetNames : [$sheetNames];

        return $this;
    }

    /**
     * Get which sheets to load.
     *
     * @return array
     */
    public function getLoadSheetsOnly()
    {
        return $this->loadSheetsOnly;
    }

    /**
     * Set load all sheets.
     *
     * @return IReader
     */
    public function setLoadAllSheets()
    {
        $this->loadSheetsOnly = null;

        return $this;
    }

    /**
     * Set Contiguous.
     *
     * @param bool $contiguous
     *
     * @return IReader
     */
    public function setContiguous($contiguous)
    {
        $this->contiguous = $contiguous;

        return $this;
    }

    /**
     * Get Contiguous.
     *
     * @return bool
     */
    public function getContiguous()
    {
        return $this->contiguous;
    }

    /**
     * Scans the XML for insecure entities, and throws an exception if any are found.
     *
     * @param string $xml
     *
     * @return string
     */
    public function securityScan($xml)
    {
        $pattern = '/(?:\s|<!DOCTYPE|<!ENTITY|%|&#x)(?:SYSTEM|PUBLIC)/i';
        if (preg_match($pattern, $xml)) {
            throw new Exception('Detected use of ENTITY in XML, aborting');
        }

        return $xml;
    }

    public function listWorksheetNames($filename)
    {
        return [];
    }

    public function listWorksheetInfo($filename)
    {
        return [];
    }
}
