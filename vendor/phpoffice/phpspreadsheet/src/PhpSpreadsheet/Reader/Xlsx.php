<?php

namespace PhpOffice\PhpSpreadsheet\Reader;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class Xlsx extends BaseReader
{
    /**
     * Create a new Xlsx Reader instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Loads Spreadsheet from file.
     *
     * @param string $filename
     *
     * @return Spreadsheet
     */
    public function load($filename)
    {
        $spreadsheet = new Spreadsheet();
        $zip = new \ZipArchive();
        if ($zip->open($filename) !== true) {
            throw new Exception("Could not open {$filename} for reading! Error code is " . $zip->status);
        }

        // Simplified parsing logic for this context
        $workbookRels = simplexml_load_string($this->securityScan($zip->getFromName('_rels/.rels')));
        $workbookPath = '';
        foreach ($workbookRels->Relationship as $rel) {
            if ($rel['Type'] == 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument') {
                $workbookPath = (string) $rel['Target'];
            }
        }

        if (empty($workbookPath)) {
            throw new Exception("Could not find workbook part in file.");
        }

        $workbook = simplexml_load_string($this->securityScan($zip->getFromName($workbookPath)));
        $sharedStrings = [];
        if ($zip->locateName('xl/sharedStrings.xml')) {
            $sharedStringsXml = simplexml_load_string($this->securityScan($zip->getFromName('xl/sharedStrings.xml')));
            if (isset($sharedStringsXml->si)) {
                foreach ($sharedStringsXml->si as $val) {
                    $sharedStrings[] = (string) $val->t;
                }
            }
        }

        $sheetIndex = 0;
        foreach ($workbook->sheets->sheet as $sheetInfo) {
            $sheetPath = 'xl/worksheets/sheet' . ($sheetIndex + 1) . '.xml'; // Simplified path
            if ($zip->locateName($sheetPath)) {
                $worksheet = $spreadsheet->getSheet($sheetIndex);
                $worksheet->setTitle((string) $sheetInfo['name']);
                $sheetData = simplexml_load_string($this->securityScan($zip->getFromName($sheetPath)));
                foreach ($sheetData->sheetData->row as $row) {
                    foreach ($row->c as $cell) {
                        $cellCoord = (string) $cell['r'];
                        $cellValue = (string) $cell->v;
                        if (isset($cell['t']) && $cell['t'] == 's') {
                            $cellValue = $sharedStrings[(int) $cellValue];
                        }
                        $worksheet->getCell($cellCoord)->setValue($cellValue);
                    }
                }
            }
            $sheetIndex++;
        }

        $zip->close();

        return $spreadsheet;
    }

    /**
     * Can the current IReader read the file?
     *
     * @param string $pFilename
     *
     * @return bool
     */
    public function canRead($pFilename)
    {
        $zip = new \ZipArchive();
        if ($zip->open($pFilename) === true) {
            $found = $zip->locateName('xl/workbook.xml');
            $zip->close();

            return $found !== false;
        }

        return false;
    }
}
