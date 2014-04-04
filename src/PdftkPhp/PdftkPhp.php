<?php
namespace PdftkPhp;

/**
 *    PdftkPhp Class
 *    http://code.google.com/p/pdftk-php/
 *    http://www.pdfhacks.com/forgeFdf/
 *
 *    License: Released under New BSD license - http://www.opensource.org/licenses/bsd-license.php
 *
 *    Purpose: Contains functions used to inject data from MySQL into an empty PDF form
 *
 *    Authors: Andrew Heiss (www.andrewheiss.com), Sid Steward (http://www.oreillynet.com/pub/au/1754)
 *    Modified by: Manuel Silvoso
 *
 *    History:
 *        2014-04-01 - PSR-2 and PSR-4 adaptation
 *        8/26/08 - Initial programming
 *
 *    Usage:
 *        $pdfmaker = new PdftkPhp;
 *
 *        $fdfDataStrings = array();
 *        $fdfDataNames = array();
 *        $fieldsHidden = array();
 *        $fieldsReadonly = array();
 *        $pdfOriginal = "string"; = filename of the original, empty pdf form
 *        $pdfFilename = "string"; = filename to be used for the output pdf
 *
 *        $pdfmaker->makePdf($fdfDataStrings, $fdfDataNames, $fieldsHidden, $fieldsReadonly, $pdfFilename);
 *
 */
class PdftkPhp
{

    protected $execPath;
    protected $tmpDir;

    /**
     * Check for the location of the pdftk binary
     * this pèrobably only works on linux
     *
     * @param string $tmpDir   temporary dir to store temporary fdfs
     * @param string $execPath the location of pdftk
     */
    public function __construct($tmpDir = '/tmp', $execPath = '')
    {
        // find pdftk
        if ('' === $execPath) {
            $execPath = exec('which pdftk');
            if ('' === $execPath) {
                $locations = array('/usr/bin/', '/usr/local/bin/', '/bin/');
                foreach ($locations as $location) {
                    if (true === is_file($location."pdftk")) {
                        $execPath = $location."pdftk";
                    }
                }
            }
        }
        if ('' === $execPath) {
            throw new \Exception('Pdftk not found!');
        }
        $this->execPath = $execPath;
        $this->tmpDir = $tmpDir;
    }

    /**
     *
     *    Function name: makePDF
     *
     *    Purpose: Generate an FDF file from db data, inject the FDF in an empty PDF form
     *
     *    Incoming parameters:
     *        $fetchedArray - one row of fetched MySQL data saved as a variable
     *
     *    Returns: Downloaded PDF file
     *
     *    Notes:
     *        * For text fields, combo boxes and list boxes, add field values as a name => value pair to $fdfDataStrings. An example of $fdfDataStrings is given in /example/download.php
     *        * For check boxes and radio buttons, add field values as a name => value pair to $fdfDataNames.  Typically, true and false correspond to the (case sensitive) names "Yes" and "Off".
     *        * Any field added to the $fieldsHidden or $fieldsReadonly array must also be a key in $fdfDataStrings or $fdfDataNames; this might be changed in the future
     *        * Any field listed in $fdfDataStrings or $fdfDataNames that you want hidden or read-only must have its field name added to $fieldsHidden or $fieldsReadonly; do this even if your form has these bits set already
     *
     */
    public function makePdf($fdfDataStrings, $fdfDataNames, $fieldsHidden, $fieldsReadonly, $pdfOriginal, $pdfFilename)
    {
        // check if the pdf form exists and is a pdf
        $fInfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!is_file($pdfOriginal) || finfo_file($fInfo, $pdfOriginal) != 'application/pdf') {
            throw new \Exception('Error: Original does not exist or is not a pdf');
        }
        // Create the fdf file
        $fdf = $this->forgeFdf('', $fdfDataStrings, $fdfDataNames, $fieldsHidden, $fieldsReadonly);
        // Save the fdf file temporarily - make sure the server has write permissions in the folder you specify in tempnam()
        $fdfFn = tempnam($this->tmpDir, "fdf");
        $fp = fopen($fdfFn, 'w');
        if ($fp) {
            fwrite($fp, $fdf);
            fclose($fp);
            // Send a force download header to the browser with a file MIME type
            header("Content-Type: application/force-download");
            header("Content-Disposition: attachment; filename=\"$pdfFilename\"");
            header("Content-Transfer-Encoding: binary");
            // Actually make the PDF by running pdftk - make sure the path to pdftk is correct
            // The PDF will be output directly to the browser - apart from the original PDF file, no actual PDF wil be saved on the server.
            passthru(escapeshellcmd($this->execPath).' '.escapeshellarg($pdfOriginal).' fill_form '.escapeshellarg($fdfFn).' output - flatten');
            // delete temporary fdf file
            unlink($fdfFn);
        } else { // error
            throw new \Exception('Error: unable to write temp fdf file: '. $fdfFn);
        }
    } // end of makePdf()

    protected function forgeFdf($pdfFormUrl, &$fdfDataStrings, &$fdfDataNames, &$fieldsHidden, &$fieldsReadonly)
    {
        /* forgeFdf, by Sid Steward
           version 1.1
           visit: www.pdfhacks.com/forgeFdf/
           PDF can be particular about CR and LF characters, so I spelled them out in hex: CR == \x0d : LF == \x0a
        */
        $fdf = "%FDF-1.2\x0d%\xe2\xe3\xcf\xd3\x0d\x0a"; // header
        $fdf.= "1 0 obj\x0d<< "; // open the Root dictionary
        $fdf.= "\x0d/FDF << "; // open the FDF dictionary
        $fdf.= "/Fields [ "; // open the form Fields array
        $fdfDataStrings = $this->burstDotsIntoArrays($fdfDataStrings);
        $this->forgeFdfFieldsStrings($fdf, $fdfDataStrings, $fieldsHidden, $fieldsReadonly);
        $fdfDataNames= $this->burstDotsIntoArrays($fdfDataNames);
        $this->forgeFdfFieldsNames($fdf, $fdfDataNames, $fieldsHidden, $fieldsReadonly);

        $fdf.= "] \x0d"; // close the Fields array

        // the PDF form filename or URL, if given
        if ($pdfFormUrl) {
            $fdf.= "/F (".$this->escapePdfString($pdfFormUrl).") \x0d";
        }

        $fdf.= ">> \x0d"; // close the FDF dictionary
        $fdf.= ">> \x0dendobj\x0d"; // close the Root dictionary

        // trailer; note the "1 0 R" reference to "1 0 obj" above
        $fdf.= "trailer\x0d<<\x0d/Root 1 0 R \x0d\x0d>>\x0d";
        $fdf.= "%%EOF\x0d\x0a";

        return $fdf;
    }

    public function escapePdfString($ss)
    {
        $backslash= chr(0x5c);
        $ssEsc= '';
        $ssLen= strlen($ss);
        for ($ii=0; $ii<$ssLen; ++$ii) {
            if (ord($ss{$ii})== 0x28 ||  // open paren
                ord($ss{$ii})== 0x29 ||  // close paren
                ord($ss{$ii})== 0x5c) {  // backslash
                $ssEsc.= $backslash.$ss{$ii}; // escape the character w/ backslash
            } elseif (ord($ss{$ii})<32 || 126<ord($ss{$ii})) {
                $ssEsc.= sprintf("\\%03o", ord($ss{$ii})); // use an octal code
            } else {
                $ssEsc.= $ss{$ii};
            }
        }
        return $ssEsc;
    }

    protected function escapePdfName($ss)
    {
        $ssEsc= '';
        $ssLen= strlen($ss);
        for ($ii=0; $ii<$ssLen; ++$ii) {
            if (ord($ss{$ii})<33 ||
                126<ord($ss{$ii}) ||
                ord($ss{$ii})==0x23) {// hash mark
                $ssEsc.= sprintf("#%02x", ord($ss{$ii})); // use a hex code
            } else {
                $ssEsc.= $ss{$ii};
            }
        }
        return $ssEsc;
    }

    /**
     * In PDF, partial form field names are combined using periods to
     * yield the full form field name; we'll take these dot-delimited
     * names and then expand them into nested arrays, here; takes
     * an array that uses dot-delimited names and returns a tree of arrays;
     */
    protected function burstDotsIntoArrays(&$fdfDataOld)
    {
        $fdfDataNew= array();

        foreach ($fdfDataOld as $key => $value) {
            $keySplit= explode('.', (string)$key, 2);

            if (count($keySplit) == 2) { // handle dot
                if (!array_key_exists((string)($keySplit[0]), $fdfDataNew)) {
                    $fdfDataNew[ (string)($keySplit[0]) ] = array();
                }
                if (gettype($fdfDataNew[ (string)($keySplit[0]) ])!= 'array') {
                    // this new key collides with an existing name; this shouldn't happen;
                    // associate string value with the special empty key in array, anyhow;

                    $fdfDataNew[ (string)($keySplit[0]) ] = array('' => $fdfDataNew[ (string)($keySplit[0]) ]);
                }

                $fdfDataNew[ (string)($keySplit[0]) ][ (string)($keySplit[1]) ] = $value;
            } else { // no dot
                if (array_key_exists((string)($keySplit[0]), $fdfDataNew) &&
                    gettype($fdfDataNew[ (string)($keySplit[0]) ])== 'array') {
                    // this key collides with an existing array; this shouldn't happen;
                    // associate string value with the special empty key in array, anyhow;

                    $fdfDataNew[ (string)$key ]['']= $value;
                } else { // simply copy
                    $fdfDataNew[ (string)$key ]= $value;
                }
            }
        }

        foreach ($fdfDataNew as $key => $value) {
            if (gettype($value) == 'array') {
                $fdfDataNew[ (string)$key ]= $this->burstDotsIntoArrays($value); // recurse
            }
        }

        return $fdfDataNew;
    }

    protected function forgeFdfFieldsFlags(&$fdf, $fieldName, &$fieldsHidden, &$fieldsReadonly)
    {
        if (in_array($fieldName, $fieldsHidden)) {
            $fdf.= "/SetF 2 "; // set
        } else {
            $fdf.= "/ClrF 2 "; // clear
        }

        if (in_array($fieldName, $fieldsReadonly)) {
            $fdf.= "/SetFf 1 "; // set
        } else {
            $fdf.= "/ClrFf 1 "; // clear
        }
    }

    /**
     * true <==> $fdfData contains string data
     *
     * string data is used for text fields, combo boxes and list boxes;
     * name data is used for checkboxes and radio buttons, and
     * /Yes and /Off are commonly used for true and false
     */
    protected function forgeFdfFields(&$fdf, &$fdfData, &$fieldsHidden, &$fieldsReadonly, $accumulatedName, $stringsB)
    {
        if (0 < strlen($accumulatedName)) {
            $accumulatedName.= '.'; // append period seperator
        }

        foreach ($fdfData as $key => $value) {
            // we use string casts to prevent numeric strings from being silently converted to numbers

            $fdf.= "<< "; // open dictionary

            if (gettype($value)== 'array') { // parent; recurse
                $fdf.= "/T (".$this->escapePdfString((string)$key).") "; // partial field name
                $fdf.= "/Kids [ ";                                    // open Kids array

                // recurse
                $this->forgeFdfFields($fdf, $value, $fieldsHidden, $fieldsReadonly, $accumulatedName.(string)$key, $stringsB);

                $fdf.= "] "; // close Kids array
            } else {
                // field name
                $fdf.= "/T (".$this->escapePdfString((string)$key).") ";

                // field value
                if ($stringsB) { // string
                    $fdf.= "/V (".$this->escapePdfString((string)$value).") ";
                } else { // name
                    $fdf.= "/V /".$this->escapePdfName((string)$value). " ";
                }

                // field flags
                $this->forgeFdfFieldsFlags($fdf, $accumulatedName. (string)$key, $fieldsHidden, $fieldsReadonly);
            }
            $fdf.= ">> \x0d"; // close dictionary
        }
    }


    protected function forgeFdfFieldsStrings(&$fdf, &$fdfDataStrings, &$fieldsHidden, &$fieldsReadonly)
    {
        return $this->forgeFdfFields($fdf, $fdfDataStrings, $fieldsHidden, $fieldsReadonly, '', true); // true => strings data
    }


    protected function forgeFdfFieldsNames(&$fdf, &$fdfDataNames, &$fieldsHidden, &$fieldsReadonly)
    {
        return $this->forgeFdfFields($fdf, $fdfDataNames, $fieldsHidden, $fieldsReadonly, '', false); // false => names data
    }
}
