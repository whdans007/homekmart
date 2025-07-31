<?php

namespace PhpOffice\PhpSpreadsheet;

use PhpOffice\PhpSpreadsheet\Reader\IReader;

class IOFactory
{
    /**
     * Search locations.
     *
     * @var array
     */
    private static $searchLocations = [
        [
            'type' => 'Xlsx',
            'string' => 'xl/workbook.xml',
        ],
        [
            'type' => 'Xls',
            'string' => 'Workbook',
            'position' => 2080,
        ],
        [
            'type' => 'Xml',
            'string' => '<?xml',
        ],
        [
            'type' => 'Ods',
            'string' => 'mimetypeapplication/vnd.oasis.opendocument.spreadsheet',
        ],
        [
            'type' => 'Slk',
            'string' => 'ID;P',
        ],
        [
            'type' => 'Gnumeric',
            'string' => '<gnm:Workbook',
        ],
        [
            'type' => 'Html',
            'string' => '<html',
        ],
        [
            'type' => 'Csv',
            'string' => '', // CSV files are identified by a byte order mark or by a lack of xml/zip headers
        ],
    ];

    /**
     * Create a new Reader\IReader instance, guessed from the filename.
     *
     * @param string $filename
     *
     * @return IReader
     */
    public static function createReaderForFile($filename)
    {
        // First, lucky guess by inspecting file extension
        $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($fileExtension) {
            $reader = self::createReader(ucfirst($fileExtension));
            if ($reader->canRead($filename)) {
                return $reader;
            }
        }

        // If we reach here, then the file extension was not recognised, or the reader
        // reports that it cannot read the file.
        $reader = self::identify($filename);
        if ($reader === null) {
            throw new Reader\Exception('Unable to identify a reader for this file');
        }

        return self::createReader($reader);
    }

    /**
     * Identify file type using string scanning.
     *
     * @param string $filename
     *
     * @return null|string
     */
    public static function identify($filename)
    {
        $signature = self::getSignature($filename);

        // Scan signatures
        foreach (self::$searchLocations as $searchLocation) {
            if (isset($searchLocation['position'])) {
                $finfo = substr($signature, $searchLocation['position']);
            } else {
                $finfo = $signature;
            }
            if (strpos($finfo, $searchLocation['string']) !== false) {
                return $searchLocation['type'];
            }
        }

        return null;
    }

    private static function getSignature($filename, $length = 4100)
    {
        $finfo = '';
        $file = fopen($filename, 'rb');
        if ($file !== false) {
            $finfo = fread($file, $length);
            fclose($file);
        }

        return $finfo;
    }

    /**
     * Create a new Reader\IReader instance.
     *
     * @param string $readerType
     *
     * @return IReader
     */
    public static function createReader($readerType)
    {
        $className = 'PhpOffice\\PhpSpreadsheet\\Reader\\' . $readerType;
        if (!class_exists($className)) {
            throw new Reader\Exception("Reader for {$readerType} not found");
        }

        return new $className();
    }

    /**
     * Create a new Writer\IWriter instance.
     *
     * @param Spreadsheet $spreadsheet
     * @param string $writerType
     *
     * @return Writer\IWriter
     */
    public static function createWriter(Spreadsheet $spreadsheet, $writerType)
    {
        $className = 'PhpOffice\\PhpSpreadsheet\\Writer\\' . $writerType;
        if (!class_exists($className)) {
            throw new Writer\Exception("Writer for {$writerType} not found");
        }

        return new $className($spreadsheet);
    }

    /**
     * Loads a Spreadsheet from a file.
     *
     * @param string $filename
     * @param int $flags
     *
     * @return Spreadsheet
     */
    public static function load($filename, $flags = 0)
    {
        $reader = self::createReaderForFile($filename);
        if (($flags & Reader\IReader::READ_DATA_ONLY) > 0) {
            $reader->setReadDataOnly(true);
        }
        if (($flags & Reader\IReader::IGNORE_EMPTY_CELLS) > 0) {
            $reader->setReadEmptyCells(false);
        }
        if (($flags & Reader\IReader::READ_AHEAD_ENABLED) > 0) {
            $reader->setReadAhead(true);
        }

        return $reader->load($filename);
    }
}
