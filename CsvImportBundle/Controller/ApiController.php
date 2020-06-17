<?php

namespace Mautic\LeadBundle\Controller\Api;

use FOS\RestBundle\Util\Codes;
use JMS\Serializer\SerializationContext;
use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\CoreBundle\Helper\CsvHelper;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\LeadBundle\Controller\FrequencyRuleTrait;
use Mautic\LeadBundle\Controller\LeadDetailsTrait;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;

class ApiController extends CommonApiController
{
    /**
    * Imports a CSV file with contacts. Accepts multipart form-data requests.
    * Accepted request params:
    *
    *  mapping: JSON mapping of CSV columns in format: {"csv_column": "mautic_field", ...}
    *  config: Import configuration object, see below for details.
    *  file: Multipart-encoded CSV file.
    *
    * Example usage from cURL:
    * curl -X POST -H 'Authorization: Basic ...' -F 'file=@path/to/contacts.csv' \
    *      -F 'mapping={"email":"email","firstname":"first_name"}' http://mautic.local/api/contacts/importCsv
    *
    * @return \Symfony\Component\HttpFoundation\Response
    */
    public function importCsvAction()
    {
        $files = $this->request->files;
        $mapping = $this->request->request->get('mapping');
        $user_config = $this->request->request->get('config');

        if (!$this->get('mautic.security')->isGranted('lead:leads:create')) {
            return $this->accessDenied();
        }

        if (!$files || count($files) !== 1) {
            return $this->badRequest('You must upload exactly one CSV file.');
        }

        $file = $files->get('file');
        $extension = $file->getClientOriginalExtension();

        if (strtolower($extension) !== 'csv') {
            return $this->badRequest('Only CSV files are supported. Uploaded file type: '.$extension);
        }

        if (!$mapping || !json_decode($mapping)) {
            return $this->badRequest('CSV column mapping is missing or not in JSON format.');
        }

        $mapping = json_decode($mapping, true);

        // Default configuration
        $config = [
            'delimiter'  => ',',
            'enclosure'  => '"',
            'escape'     => '"',
            'batchlimit' => 200,
        ];

        // Apply user configuration
        if ($user_config) {
            $user_config = json_decode($user_config, true);
            $config = array_merge($config, $user_config);
        }

        /** @var \Mautic\LeadBundle\Model\ImportModel $importModel */
        $importModel = $this->getModel('lead.import');

        $fs = new Filesystem();
        $import = $importModel->getEntity();
        $importDir = $importModel->getImportDir();
        $fileName = $importModel->getUniqueFileName();
        $fullPath = $importDir.'/'.$fileName;

        /** @var \Mautic\LeadBundle\Model\FieldModel $fieldModel */
        $fieldModel = $this->getModel('lead.field');
        $leadFields = $fieldModel->getFieldList(false, false);

        foreach ($mapping as $csvField => $leadField) {
            if (!isset($leadFields[$leadField])) {
                return $this->badRequest('Unrecognized column mapping field: '.$leadField);
            }
        }

        // Create the import dir recursively
        $fs->mkdir($importDir, 0755);

        if (file_exists($fullPath)) {
            unlink($fullPath);
        }

        // Move the file to final location
        $file->move($importDir, $fullPath);

        // Get file headers and line count
        $fileData = new \SplFileObject($fullPath);
        $headers = $fileData->fgetcsv($config['delimiter'], $config['enclosure'], $config['escape']);
        $headers = CsvHelper::sanitizeHeaders($headers);

        $fileData->seek(PHP_INT_MAX);
        $linecount = $fileData->key();

        // Create an import object
        $import->setMatchedFields($mapping)
            ->setDir($importDir)
            ->setLineCount($linecount)
            ->setFile($fileName)
            ->setOriginalFile($file->getClientOriginalName())
            ->setDefault('owner', null)
            ->setHeaders($headers)
            ->setParserConfig($config)
            ->setStatus($import::QUEUED);

        $importModel->saveEntity($import);

        $view = $this->view(['import' => $import]);

        return $this->handleView($view);
    }
}
