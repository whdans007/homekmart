<?php

namespace PhpOffice\PhpSpreadsheet\Reader;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class Csv extends BaseReader
{
    /**
     * Delimiter.
     *
     * @var string
     */
    private $delimiter = ',';

    /**
     * Enclosure.
     *
     * @var string
     */
    private $enclosure = '"';

    /**
     * Sheet index to read.
     *
     * @var int
     */
    private $sheetIndex = 0;

    /**
     * Load rows into a specified worksheet.
     *
     * @param string $filename
     *
     * @return Spreadsheet
     */
    public function load($filename)
    {
        $spreadsheet = new Spreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();

        $fileHandle = fopen($filename, 'r');
        if ($fileHandle === false) {
            throw new Exception("Could not open file {$filename} for reading.");
        }

        $row = 1;
        while (($rowData = fgetcsv($fileHandle, 0, $this->delimiter, $this->enclosure)) !== false) {
            $column = 'A';
            foreach ($rowData as $cellValue) {
                $worksheet->getCell($column . $row)->setValue($cellValue);
                ++$column;
            }
            ++$row;
        }
        fclose($fileHandle);

        return $spreadsheet;
    }

    public function canRead($filename)
    {
        // This is a simple check, actual CSV detection is more complex
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $ext === 'csv';
    }
}
