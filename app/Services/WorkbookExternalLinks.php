<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/** Keep XLSX external-link parts which this PhpSpreadsheet version does not round-trip. */
class WorkbookExternalLinks
{
    public function preserve(string $original, string $generated): void
    {
        $source = new ZipArchive;
        $target = new ZipArchive;
        if ($source->open($original) !== true) {
            throw new RuntimeException('Could not inspect the original workbook links.');
        }
        try {
            $book = $this->xml($source->getFromName('xl/workbook.xml'));
            $xpath = new DOMXPath($book);
            $references = $xpath->query('//*[local-name()="externalReferences"]')->item(0);
            if (! $references) {
                return;
            }
            if ($target->open($generated) !== true) {
                throw new RuntimeException('Could not preserve external workbook links.');
            }
            try {
                $newBook = $this->xml($target->getFromName('xl/workbook.xml'));
                $rels = $this->xml($source->getFromName('xl/_rels/workbook.xml.rels'));
                $newRels = $this->xml($target->getFromName('xl/_rels/workbook.xml.rels'));
                $imported = $newBook->importNode($references, true);
                $relationshipNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
                $usedIds = [];
                foreach ($newRels->documentElement->childNodes as $node) {
                    if ($node instanceof \DOMElement) {
                        $usedIds[] = $node->getAttribute('Id');
                    }
                }
                foreach ($imported->childNodes as $reference) {
                    if (! $reference instanceof \DOMElement) {
                        continue;
                    }
                    $oldId = $reference->getAttributeNS($relationshipNamespace, 'id');
                    $found = false;
                    foreach ($rels->documentElement->childNodes as $relation) {
                        if (! $relation instanceof \DOMElement || $relation->getAttribute('Id') !== $oldId) {
                            continue;
                        }
                        $newId = 'rIdPreservedExternal'.count($usedIds);
                        while (in_array($newId, $usedIds, true)) {
                            $newId .= 'x';
                        }
                        $usedIds[] = $newId;
                        $copy = $newRels->importNode($relation, true);
                        $copy->setAttribute('Id', $newId);
                        $newRels->documentElement->appendChild($copy);
                        $reference->setAttributeNS($relationshipNamespace, 'r:id', $newId);
                        $found = true;
                        break;
                    }
                    if (! $found) {
                        throw new RuntimeException('An external formula link is incomplete in the original workbook.');
                    }
                }
                // OOXML requires externalReferences before definedNames / calcPr.
                $before = (new DOMXPath($newBook))->query('/*/*[local-name()="definedNames" or local-name()="calcPr"]')->item(0);
                $newBook->documentElement->insertBefore($imported, $before);
                for ($i = 0; $i < $source->numFiles; $i++) {
                    $name = $source->getNameIndex($i);
                    if (str_starts_with($name, 'xl/externalLinks/')) {
                        $target->addFromString($name, $source->getFromName($name));
                    }
                }
                $types = $this->xml($source->getFromName('[Content_Types].xml'));
                $newTypes = $this->xml($target->getFromName('[Content_Types].xml'));
                foreach ($types->documentElement->childNodes as $type) {
                    if ($type instanceof \DOMElement && str_starts_with($type->getAttribute('PartName'), '/xl/externalLinks/')) {
                        $newTypes->documentElement->appendChild($newTypes->importNode($type, true));
                    }
                }
                $target->addFromString('xl/workbook.xml', $newBook->saveXML());
                $target->addFromString('xl/_rels/workbook.xml.rels', $newRels->saveXML());
                $target->addFromString('[Content_Types].xml', $newTypes->saveXML());
            } finally {
                $target->close();
            }
        } finally {
            $source->close();
        }
    }

    protected function xml(string|false $contents): DOMDocument
    {
        $document = new DOMDocument;
        if (! $contents || ! $document->loadXML($contents, LIBXML_NONET)) {
            throw new RuntimeException('The workbook package contains invalid XML.');
        }

        return $document;
    }
}
